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

	public function testSupported(): void {
		$this->assertFalse($this->os->supported());
	}
}
