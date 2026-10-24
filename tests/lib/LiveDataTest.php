<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\LiveData;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\Resources\ThermalZone;
use OCA\ServerInfo\UptimeFormatter;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;

class LiveDataTest extends \Test\TestCase {
	private Os&MockObject $os;
	private ICache&MockObject $cache;
	private ICacheFactory&MockObject $cacheFactory;
	/** @var array<string, mixed> */
	private array $stored = [];
	/** @var array<string, int> */
	private array $ttls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->os = $this->createMock(Os::class);
		$this->os->method('getMemory')->willReturn(new Memory());
		$this->os->method('getAverageLoad')->willReturn([0.5, 0.4, 0.3]);
		$this->os->method('getTime')->willReturn('Sat Oct 24 12:00:00 UTC 2026');
		$this->os->method('getUptime')->willReturn(3600);

		$this->cache = $this->createMock(ICache::class);
		$this->cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->stored[$key] ?? null);
		$this->cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl = 0): bool {
			$this->stored[$key] = $value;
			$this->ttls[$key] = $ttl;
			return true;
		});

		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->expects($this->once())
			->method('createLocal')
			->with('serverinfo-live')
			->willReturn($this->cache);
	}

	private function liveData(): LiveData {
		return new LiveData($this->os, $this->createMock(UptimeFormatter::class), $this->cacheFactory);
	}

	public function testReadsTheSensorsOnceWhileTheReadingIsCached(): void {
		$this->os->expects($this->once())
			->method('getThermalZones')
			->willReturn([new ThermalZone('thermal_zone0', 'x86_pkg_temp', 47.0)]);

		$liveData = $this->liveData();
		$first = $liveData->getData();
		$second = $liveData->getData();

		$expected = [['zone' => 'thermal_zone0', 'type' => 'x86_pkg_temp', 'temp' => 47.0]];
		$this->assertSame($expected, $first['thermalzones']);
		$this->assertSame($expected, $second['thermalzones']);
		$this->assertSame(LiveData::THERMAL_TTL, $this->ttls['thermalzones']);
		$this->assertSame(15, LiveData::THERMAL_TTL);
	}

	public function testRemembersThatThereAreNoSensors(): void {
		$this->os->expects($this->once())->method('getThermalZones')->willReturn([]);

		$liveData = $this->liveData();
		$this->assertSame([], $liveData->getData()['thermalzones']);
		$this->assertSame([], $liveData->getData()['thermalzones']);
	}

	public function testReadsTheSensorsAgainOnceTheReadingExpired(): void {
		$this->os->expects($this->exactly(2))
			->method('getThermalZones')
			->willReturnOnConsecutiveCalls(
				[new ThermalZone('thermal_zone0', 'acpitz', 40.0)],
				[new ThermalZone('thermal_zone0', 'acpitz', 42.0)],
			);

		$liveData = $this->liveData();
		$this->assertSame(40.0, $liveData->getData()['thermalzones'][0]['temp']);
		$this->stored = [];
		$this->assertSame(42.0, $liveData->getData()['thermalzones'][0]['temp']);
	}

	public function testKeepsThePayloadShape(): void {
		$this->os->method('getThermalZones')->willReturn([]);

		$data = $this->liveData()->getData();

		$this->assertSame(['cpu', 'memory', 'servertime', 'uptime', 'thermalzones'], array_keys($data));
		$this->assertSame([0.5, 0.4, 0.3], $data['cpu']['load']);
		$this->assertSame(['total', 'free', 'swap_total', 'swap_free'], array_keys($data['memory']));
	}
}
