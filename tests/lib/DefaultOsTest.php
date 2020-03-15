<?php
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

use OCA\ServerInfo\OperatingSystems\DefaultOs;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Class DefaultOsTest
 *
 * @package OCA\ServerInfo\Tests
 */
class DefaultOsTest extends TestCase {

	public function testGetMemory(): void {
		/** @var DefaultOs|MockObject $os */
		$os = $this->getMockBuilder(DefaultOs::class)
			->disableOriginalConstructor()
			->disableOriginalClone()
			->disableArgumentCloning()
			->disallowMockingUnknownTypes()
			->setMethods(['readContent'])
			->getMock();
		$os->method('readContent')
			->with('/proc/meminfo')
			->willReturn(file_get_contents(__DIR__ . '/../data/meminfo'));

		$memory = $os->getMemory();

		$this->assertArrayHasKey('MemTotal', $memory);
		$this->assertArrayHasKey('MemFree', $memory);
		$this->assertArrayHasKey('MemAvailable', $memory);
		$this->assertArrayHasKey('SwapTotal', $memory);
		$this->assertArrayHasKey('SwapFree', $memory);

		$this->assertEquals(16330252 * 1000, $memory['MemTotal']);
		$this->assertEquals(2443908 * 1000, $memory['MemFree']);
		$this->assertEquals(7675276 * 1000, $memory['MemAvailable']);
		$this->assertEquals(999420 * 1000, $memory['SwapTotal']);
		$this->assertEquals(917756 * 1000, $memory['SwapFree']);
	}

}
