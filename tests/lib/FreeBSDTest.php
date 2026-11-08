<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\OperatingSystems\FreeBSD;
use OCA\ServerInfo\OsStatus\Host\CommandResult;
use OCA\ServerInfo\Resources\Disk;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\Resources\NetInterface;
use OCA\ServerInfo\Tests\OsStatus\FakeHost;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Test\TestCase;

/**
 * Class FreeBSDTest
 *
 * @package OCA\ServerInfo\Tests
 */
class FreeBSDTest extends TestCase {
	/** @var FreeBSD&MockObject */
	protected $os;
	private FakeHost $host;
	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->host = new FakeHost();
		$this->host->family = 'BSD';
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->os = $this->getMockBuilder(FreeBSD::class)
			->setConstructorArgs([$this->host, $this->logger])
			->disableOriginalClone()
			->disableArgumentCloning()
			->disallowMockingUnknownTypes()
			->onlyMethods(['getNetInterfaces'])
			->getMock();
	}

	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../data/' . $name);
	}

	public function testGetCpu(): void {
		$this->host->withCommand('sysctl', ['-n', 'hw.model', 'kern.smp.cpus'], "Intel(R) Xeon(R) CPU E5-2650 v4 @ 2.20GHz\n8\n");

		$cpu = $this->os->getCPU();

		$this->assertEquals('Intel(R) Xeon(R) CPU E5-2650 v4 @ 2.20GHz', $cpu->getName());
		$this->assertEquals(8, $cpu->getThreads());
		$this->assertSame(['sysctl -n hw.model kern.smp.cpus'], $this->host->ran);
	}

	public function testGetCpuNoData(): void {
		$this->host->commandsAllowed = false;

		$cpu = $this->os->getCPU();

		$this->assertEquals('Unknown Processor', $cpu->getName());
		// -1, not 1: the thread count divides the load average, so claiming a
		// single core reports an eight-core host as 800% busy.
		$this->assertEquals(-1, $cpu->getThreads());
	}

	public function testGetMemory(): void {
		$this->host->withCommand('swapinfo', ['-k'], $this->fixture('freebsd_swapinfo'))
			->withCommand('sysctl', ['-n', 'hw.realmem', 'hw.pagesize', 'vm.stats.vm.v_inactive_count', 'vm.stats.vm.v_cache_count', 'vm.stats.vm.v_free_count'], $this->fixture('freebsd_meminfo'));

		$memory = $this->os->getMemory();

		$this->assertEquals(65393, $memory->getMemTotal());
		$this->assertEquals(-1, $memory->getMemFree());
		$this->assertEquals(15076, $memory->getMemAvailable());
		$this->assertEquals(3656, $memory->getSwapTotal());
		$this->assertEquals(3656, $memory->getSwapFree());
	}

	public function testGetMemoryNoSwapinfo(): void {
		$this->host->withCommand('swapinfo', ['-k'], '', 1)
			->withCommand('sysctl', ['-n', 'hw.realmem', 'hw.pagesize', 'vm.stats.vm.v_inactive_count', 'vm.stats.vm.v_cache_count', 'vm.stats.vm.v_free_count'], $this->fixture('freebsd_meminfo'));

		$memory = $this->os->getMemory();

		$this->assertEquals(65393, $memory->getMemTotal());
		$this->assertEquals(-1, $memory->getMemFree());
		$this->assertEquals(15076, $memory->getMemAvailable());
		$this->assertEquals(-1, $memory->getSwapTotal());
		$this->assertEquals(-1, $memory->getSwapFree());
	}

	public function testGetMemoryNoData(): void {
		$this->host->commandsAllowed = false;

		$this->assertEquals(new Memory(), $this->os->getMemory());
	}

	public function testGetMemoryTruenasSwapinfo(): void {
		$this->host->withCommand('swapinfo', ['-k'], $this->fixture('truenas_core_swapinfo'))
			->withCommand('sysctl', ['-n', 'hw.realmem', 'hw.pagesize', 'vm.stats.vm.v_inactive_count', 'vm.stats.vm.v_cache_count', 'vm.stats.vm.v_free_count'], $this->fixture('truenas_core_meminfo'));

		$memory = $this->os->getMemory();

		$this->assertEquals(131068, $memory->getMemTotal());
		$this->assertEquals(-1, $memory->getMemFree());
		$this->assertEquals(25136, $memory->getMemAvailable());
		$this->assertEquals(24576, $memory->getSwapTotal());
		$this->assertEquals(24576, $memory->getSwapFree());
	}

	public function testGetNetworkInterfaces(): void {
		$this->os->method('getNetInterfaces')
			->willReturn(json_decode(file_get_contents(__DIR__ . '/../data/freebsd_net_get_interfaces.json'), true, 512, JSON_THROW_ON_ERROR));
		$this->host->withCommand('ifconfig', ['pflog0'], $this->fixture('freebsd_interface_pflog0'))
			->withCommand('ifconfig', ['epair0b'], $this->fixture('freebsd_interface_epair0b'));

		$net1 = new NetInterface('epair0b', true);
		$net1->addIPv4('10.0.2.15');
		$net1->addIPv6('fe80::a00:27ff:fe91:f84b');
		$net1->setMAC('1a:c0:4d:ba:b5:82');
		$net1->setSpeed('10 Gbps');
		$net1->setDuplex('full');

		$net2 = new NetInterface('pflog0', true);
		$net2->addIPv4('192.168.2.20');
		$net2->addIPv6('fe80::a00:27ff:fe8b:d03d');
		$net2->addIPv4('192.168.2.21');
		$net2->addIPv4('192.168.2.22');

		$net3 = new NetInterface('lo0', true);
		$net3->addIPv4('127.0.0.1');
		$net3->addIPv6('::1');
		$net3->addIPv6('fe80::1');

		$expected = [$net1, $net2, $net3];
		$actual = $this->os->getNetworkInterfaces();

		$this->assertEquals($expected, $actual);
	}

	public function testGetDiskInfoUnusualMountPoints(): void {
		$this->host->withCommand('df', ['-TPk'], $this->fixture('freebsd_df_tp_mount_points'));

		// getDiskInfo() had no coverage here at all, and this pattern was even
		// narrower than the Linux one: it allowed no dots, so a mount point like
		// /mnt/media.old removed that filesystem from the report entirely.
		$disk1 = new Disk();
		$disk1->setDevice('/dev/gpt/rootfs');
		$disk1->setFs('ufs');
		$disk1->setUsed(8889);
		$disk1->setAvailable(46212);
		$disk1->setPercent('16.13%');
		$disk1->setMount('/');

		$disk2 = new Disk();
		$disk2->setDevice('zroot/data');
		$disk2->setFs('zfs');
		$disk2->setUsed(1954);
		$disk2->setAvailable(7812);
		$disk2->setPercent('20%');
		$disk2->setMount('/mnt/My Backup');

		$disk3 = new Disk();
		$disk3->setDevice('zroot/media');
		$disk3->setFs('zfs');
		$disk3->setUsed(3907);
		$disk3->setAvailable(15625);
		$disk3->setPercent('20%');
		$disk3->setMount('/mnt/media.old');

		$disk4 = new Disk();
		$disk4->setDevice('storage:/vol');
		$disk4->setFs('nfs');
		$disk4->setUsed(7813);
		$disk4->setAvailable(31250);
		$disk4->setPercent('20%');
		$disk4->setMount('/mnt/nfs');

		$this->assertEquals([$disk1, $disk2, $disk3, $disk4], $this->os->getDiskInfo());
	}

	public function testGetNetworkInfo(): void {
		$this->host->withCommand('netstat', ['-rn'], "Routing tables\n\ndefault            192.0.2.1        UGS\n");

		$networkInfo = $this->os->getNetworkInfo();

		$this->assertSame('192.0.2.1', $networkInfo['gateway']);
		$this->assertSame('', $networkInfo['dns']);
		$this->assertArrayHasKey('hostname', $networkInfo);
	}

	public function testGetNetworkInfoListsTheNameservers(): void {
		$this->host->withCommand('netstat', ['-rn'], "Routing tables\n\ndefault            192.0.2.1        UGS\n");
		$this->host->withFile('/etc/resolv.conf', "# Generated by resolvconf\nsearch example.org\nnameserver 192.0.2.53\n  nameserver 2001:db8::53\nnameserver 192.0.2.53\n");

		$this->assertSame('192.0.2.53, 2001:db8::53', $this->os->getNetworkInfo()['dns']);
	}

	public function testGetNetworkInterfacesError(): void {
		$this->os->method('getNetInterfaces')
			->willThrowException(new RuntimeException('Unable to get network interfaces'));

		$expected = [];
		$actual = $this->os->getNetworkInterfaces();

		$this->assertEquals($expected, $actual);
	}

	public function testGetDiskInfoZeroCapacityAndInvertedUsage(): void {
		$this->host->withCommand('df', ['-TPk'], $this->fixture('freebsd_df_tp_edge_cases'));

		$disk1 = new Disk();
		$disk1->setDevice('/dev/gpt/rootfs');
		$disk1->setFs('ufs');
		$disk1->setUsed(8889);
		$disk1->setAvailable(46212);
		$disk1->setPercent('16.13%');
		$disk1->setMount('/');

		// Zero blocks: used to be a DivisionByZeroError
		$disk2 = new Disk();
		$disk2->setDevice('empty');
		$disk2->setFs('nullfs');
		$disk2->setUsed(0);
		$disk2->setAvailable(0);
		$disk2->setPercent('0%');
		$disk2->setMount('/mnt/empty');

		// More available than total: used is clamped
		$disk3 = new Disk();
		$disk3->setDevice('inverted');
		$disk3->setFs('zfs');
		$disk3->setUsed(0);
		$disk3->setAvailable(1);
		$disk3->setPercent('0%');
		$disk3->setMount('/tank');

		$this->assertEquals([$disk1, $disk2, $disk3], $this->os->getDiskInfo());
	}

	public function testGetNetworkInterfacesSkipsNamesThatAreNoInterfaceName(): void {
		$this->host->withCommand('ifconfig', ['-a'], 'every interface');
		$this->os->method('getNetInterfaces')->willReturn([
			'-a' => ['up' => true, 'unicast' => []],
			'em0 ; reboot' => ['up' => true, 'unicast' => []],
		]);

		$interfaces = $this->os->getNetworkInterfaces();

		$this->assertCount(2, $interfaces);
		$this->assertSame([], $this->host->ran);
	}

	public function testGetAverageLoadComesFromPhp(): void {
		$load = $this->os->getAverageLoad();
		if (!function_exists('sys_getloadavg') || sys_getloadavg() === false) {
			$this->assertFalse($load);
			return;
		}

		$this->assertIsArray($load);
		$this->assertCount(3, $load);
		$this->assertContainsOnly('float', $load);
		$this->assertSame([], $this->host->ran);
	}

	public function testSampleUptimeFromBootTime(): void {
		$bootedAt = time() - 3600;
		$this->host->withCommand('sysctl', ['-n', 'kern.boottime'], '{ sec = ' . $bootedAt . ', usec = 503212 } Sat Oct 24 09:00:00 2026' . "\n");

		$this->assertEqualsWithDelta(3600, $this->os->sampleUptime(), 2);
	}

	public function testSampleUptimeWithoutSysctl(): void {
		$this->host->withCommand('sysctl', ['-n', 'kern.boottime'], 'sysctl: unknown oid', 1);

		$this->assertSame(-1, $this->os->sampleUptime());
	}

	public function testUptimeIsLeftToTheSampler(): void {
		$this->assertSame(-1, $this->os->getUptime());
		$this->assertSame([], $this->host->ran);
	}

	public function testGetTimeInTheLinkedSystemZone(): void {
		$this->host->links['/etc/localtime'] = '/usr/share/zoneinfo/Asia/Kolkata';

		$this->assertStringContainsString(' IST ', $this->os->getTime());
		$this->assertSame([], $this->host->ran);
	}

	public function testGetTimeInTheZoneTzsetupRecorded(): void {
		$this->host->withFile('/var/db/zoneinfo', "Asia/Kolkata\n");

		$this->assertStringContainsString(' IST ', $this->os->getTime());
	}

	public function testGetTimeFormat(): void {
		$this->assertMatchesRegularExpression(
			'/^[A-Z][a-z]{2} [A-Z][a-z]{2} \d{1,2} \d{2}:\d{2}:\d{2} \S+ \d{4}$/',
			$this->os->getTime(),
		);
	}

	public function testGetDiskInfoReturnsTheMountsThatAnsweredBeforeATimeout(): void {
		$this->host->binaries[] = 'df';
		$this->host->commands['df -TPk'] = new CommandResult(137, "/dev/gpt/rootfs ufs 10000000 2000000 8000000 20% /\nstorage:/vol nfs 1", '', true);
		$this->logger->expects($this->once())->method('warning');

		$disks = $this->os->getDiskInfo();

		$this->assertCount(1, $disks);
		$this->assertSame('/', $disks[0]->getMount());
	}

	public function testSupported(): void {
		$this->assertFalse($this->os->supported());
	}
}
