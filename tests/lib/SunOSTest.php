<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2023 Daniel Kesselberg <mail@danielkesselberg.de>
 *
 * @author Daniel Kesselberg <mail@danielkesselberg.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\OperatingSystems\FreeBSD;
use OCA\ServerInfo\OperatingSystems\SunOS;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\Resources\NetInterface;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Test\TestCase;

class SunOSTest extends TestCase {
	/** @var SunOS&MockObject */
	protected $os;

	protected function setUp(): void {
		parent::setUp();

		$this->os = $this->getMockBuilder(SunOS::class)
			->disableOriginalConstructor()
			->disableOriginalClone()
			->disableArgumentCloning()
			->disallowMockingUnknownTypes()
			->setMethods(['executeCommand', 'getNetInterfaces'])
			->getMock();
	}

	public function testGetMemory(): void {
		$this->os->method('executeCommand')
			->willReturnMap([
				['/usr/sbin/swap -s', file_get_contents(__DIR__ . '/../data/sunos_swapinfo')],
				['/usr/bin/vmstat -p', file_get_contents(__DIR__ . '/../data/sunos_meminfo')],
			]);

		$memory = $this->os->getMemory();

		$this->assertEquals(65393, $memory->getMemTotal());
		$this->assertEquals(-1, $memory->getMemFree());
		$this->assertEquals(15076, $memory->getMemAvailable());
		$this->assertEquals(3656, $memory->getSwapTotal());
		$this->assertEquals(3656, $memory->getSwapFree());
	}

	public function testGetMemoryNoSwapinfo(): void {
		$this->os->method('executeCommand')
			->willReturnCallback(static function ($command) {
				if ($command === '/usr/sbin/swapinfo -k') {
					throw new \RuntimeException('No output for command: /usr/sbin/swapinfo');
				}
				if ($command === '/sbin/sysctl -n hw.realmem hw.pagesize vm.stats.vm.v_inactive_count vm.stats.vm.v_cache_count vm.stats.vm.v_free_count') {
					return file_get_contents(__DIR__ . '/../data/freebsd_meminfo');
				}
			});

		$memory = $this->os->getMemory();

		$this->assertEquals(65393, $memory->getMemTotal());
		$this->assertEquals(-1, $memory->getMemFree());
		$this->assertEquals(15076, $memory->getMemAvailable());
		$this->assertEquals(-1, $memory->getSwapTotal());
		$this->assertEquals(-1, $memory->getSwapFree());
	}

	public function testGetMemoryNoData(): void {
		$this->os->method('executeCommand')
			->willThrowException(new \RuntimeException('No output for command: xxx'));

		$this->assertEquals(new Memory(), $this->os->getMemory());
	}

	public function testGetCpuName(): void {
		$this->os->method('executeCommand')
			->willReturnCallback(static function ($command) {
				if ($command === '/usr/sbin/psrinfo -pv') {
					return file_get_contents(__DIR__ . '/../data/sunos_cpuinfo_model');
				}
				if ($command === '/usr/sbin/psrinfo') {
					return file_get_contents(__DIR__ . '/../data/sunos_cpuinfo_cores');
				}
				throw new RuntimeException();
			});

		$expected = 'AMD Ryzen 5 5600X 6-Core Processor      [ Socket: AM4 ] (4 cores)';
		$actual = $this->os->getCpuName();

		$this->assertEquals($expected, $actual);
	}
	public function testGetNetworkInterfaces(): void {
		$this->os->method('getNetInterfaces')
			->willReturn(json_decode(file_get_contents(__DIR__ . '/../data/freebsd_net_get_interfaces.json'), true, 512, JSON_THROW_ON_ERROR));
		$this->os->method('executeCommand')
			->willReturnCallback(static function ($command) {
				if ($command === '/sbin/ifconfig pflog0') {
					return file_get_contents(__DIR__ . '/../data/freebsd_interface_pflog0');
				}
				if ($command === '/sbin/ifconfig epair0b') {
					return file_get_contents(__DIR__ . '/../data/freebsd_interface_epair0b');
				}
				throw new RuntimeException();
			});

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

	public function testSupported(): void {
		$this->assertFalse($this->os->supported());
	}
}
