<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCP\AppFramework\Services\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * The statistics that come from database queries: storage and user counts,
 * shares, active users and the database itself. They change on a scale of
 * minutes but cost about 25 queries, so the monitoring API and the admin page
 * share one entry in the distributed cache instead of each running them on
 * every request.
 *
 * @psalm-type StatisticsBlock = array{storage: array, shares: array, database: array, activeUsers: array}
 * @psalm-api
 */
class StatisticsCache {
	/** Longest time the statistics may be served from the cache, in seconds */
	public const MAX_TTL = 3600;

	private const CACHE_KEY = 'statistics';
	private const BLOCKS = ['storage', 'shares', 'database', 'activeUsers'];

	private ICache $cache;

	public function __construct(
		private StorageStatistics $storageStatistics,
		private ShareStatistics $shareStatistics,
		private SessionStatistics $sessionStatistics,
		private DatabaseStatistics $databaseStatistics,
		private IAppConfig $appConfig,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('serverinfo');
	}

	/**
	 * The statistics, at most ttl() seconds old.
	 *
	 * @return StatisticsBlock
	 */
	public function get(): array {
		$ttl = $this->ttl();
		if ($ttl === 0) {
			return $this->compute();
		}

		$cached = $this->decode($this->cache->get(self::CACHE_KEY));
		if ($cached !== null) {
			return $cached;
		}

		$statistics = $this->compute();
		try {
			// Encoded here so that floats keep their type on every cache backend
			$json = json_encode($statistics, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
			$this->cache->set(self::CACHE_KEY, $json, $ttl);
		} catch (\JsonException) {
			// Served uncached: invalid UTF-8, for example in a database version string
		}
		return $statistics;
	}

	/**
	 * How long the statistics are cached, in seconds; 0 when caching is off.
	 */
	public function ttl(): int {
		$ttl = $this->appConfig->getAppValueInt(ConfigLexicon::STATISTICS_CACHE_TTL);
		return max(0, min(self::MAX_TTL, $ttl));
	}

	/**
	 * @return StatisticsBlock
	 */
	private function compute(): array {
		return [
			'storage' => $this->storageStatistics->getStorageStatistics(),
			'shares' => $this->shareStatistics->getShareStatistics(),
			'database' => $this->databaseStatistics->getDatabaseStatistics(),
			'activeUsers' => $this->sessionStatistics->getSessionStatistics(),
		];
	}

	/**
	 * @return StatisticsBlock|null
	 */
	private function decode(mixed $json): ?array {
		if (!is_string($json)) {
			return null;
		}
		try {
			$cached = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($cached)) {
			return null;
		}
		foreach (self::BLOCKS as $block) {
			if (!isset($cached[$block]) || !is_array($cached[$block])) {
				return null;
			}
		}
		/** @var StatisticsBlock $cached */
		return $cached;
	}
}
