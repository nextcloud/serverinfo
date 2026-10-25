<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCA\ServerInfo\Resources\ThermalZone;
use OCP\ICache;
use OCP\ICacheFactory;

class LiveData {
	/**
	 * Seconds a temperature reading is reused. Reading the sensors can wake
	 * sleeping drives or send them NVMe or ATA commands, which the page's poll
	 * must not do every few seconds; temperatures change slowly anyway.
	 */
	public const THERMAL_TTL = 15;
	private const THERMAL_KEY = 'thermalzones';

	private ICache $cache;

	public function __construct(
		private Os $os,
		private UptimeFormatter $uptimeFormatter,
		ICacheFactory $cacheFactory,
	) {
		// Local, not distributed: the sensors belong to the host answering.
		$this->cache = $cacheFactory->createLocal('serverinfo-live');
	}

	public function getData(): array {
		$memory = $this->os->getMemory();

		return [
			'cpu' => [
				'load' => $this->os->getAverageLoad(),
			],
			'memory' => [
				'total' => $memory->getMemTotal(),
				'free' => $memory->getMemAvailable(),
				'swap_total' => $memory->getSwapTotal(),
				'swap_free' => $memory->getSwapFree(),
			],
			'servertime' => $this->os->getTime(),
			'uptime' => $this->uptimeFormatter->format($this->os->getUptime()),
			'thermalzones' => $this->thermalZones(),
		];
	}

	/**
	 * The sensor readings, read at most once per THERMAL_TTL. Without a local
	 * memory cache configured they are read on every call.
	 *
	 * @return list<array{zone: string, type: string, temp: float}>
	 */
	private function thermalZones(): array {
		$cached = $this->cache->get(self::THERMAL_KEY);
		if (is_array($cached)) {
			/** @var list<array{zone: string, type: string, temp: float}> */
			return $cached;
		}

		$zones = array_values(array_map(
			static fn (ThermalZone $zone): array => $zone->jsonSerialize(),
			$this->os->getThermalZones(),
		));
		$this->cache->set(self::THERMAL_KEY, $zones, self::THERMAL_TTL);

		return $zones;
	}
}
