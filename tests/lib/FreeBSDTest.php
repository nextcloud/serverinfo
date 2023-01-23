<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020 Daniel Kesselberg <mail@danielkesselberg.de>
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
use OCA\ServerInfo\Resources\Memory;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Class FreeBSDTest
 *
 * @package OCA\ServerInfo\Tests
 */
class FreeBSDTest extends TestCase {
	/** @var FreeBSD|MockObject */
	protected $os;

	protected function setUp(): void {
		parent::setUp();

		$this->os = $this->getMockBuilder(FreeBSD::class)
			->disableOriginalConstructor()
			->disableOriginalClone()
			->disableArgumentCloning()
			->disallowMockingUnknownTypes()
			->setMethods(['executeCommand'])
			->getMock();
	}

	public function testGetMemory(): void {
		$this->os->method('executeCommand')
			->willReturnMap([
				['/usr/sbin/swapinfo -k', file_get_contents(__DIR__ . '/../data/freebsd_swapinfo')],
				['/sbin/sysctl -n hw.realmem hw.pagesize vm.stats.vm.v_inactive_count vm.stats.vm.v_cache_count vm.stats.vm.v_free_count', file_get_contents(__DIR__ . '/../data/freebsd_meminfo')],
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

	public function testGetNetworkInterfaces(): void {
		$this->os->method('executeCommand')
			->willReturnCallback(static function ($command) {
				if ($command === '/sbin/ifconfig -a') {
					return file_get_contents(__DIR__ . '/../data/freebsd_interfaces');
				}
				if ($command === '/sbin/ifconfig lo0') {
					return file_get_contents(__DIR__ . '/../data/freebsd_interface_lo0');
				}
				if ($command === '/sbin/ifconfig pflog0') {
					return file_get_contents(__DIR__ . '/../data/freebsd_interface_pflog0');
				}
				if ($command === '/sbin/ifconfig epair0b') {
					return file_get_contents(__DIR__ . '/../data/freebsd_interface_epair0b');
				}

				// Regex matches way more than the interface names, so if it doesn't match any of the defined ones, throw.
				throw new \RuntimeException();
			});

		$interfaces = $this->os->getNetworkInterfaces();
		$this->assertEquals([
			[
				"interface" => "lo0",
				"ipv4" => "127.0.0.1",
				"ipv6" => "::1 fe80::1",
				"status" => "active",
				"speed" => "unknown",
				"duplex" => "",
			],
			[
				"interface" => "pflog0",
				"ipv4" => "",
				"ipv6" => "",
				"mac" => "",
				"status" => "active",
				"speed" => "unknown",
				"duplex" => "",
			],
			[
				"interface" => "epair0b",
				"ipv4" => "192.168.178.150",
				"ipv6" => "",
				"mac" => "1a:c0:4d:ba:b5:82",
				"speed" => "10 Gbps",
				"status" => "active",
				"duplex" => "Duplex: full",
			]
		], $interfaces);
	}

	public function testSupported(): void {
		$this->assertFalse($this->os->supported());
	}
}
