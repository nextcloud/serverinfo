<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\StatisticsCache;
use OCA\ServerInfo\StorageStatistics;
use OCP\AppFramework\Services\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Server;
use Test\TestCase;

/**
 * @group DB
 */
class StatisticsCacheDatabaseTest extends TestCase {
	public function testCachedStatisticsMatchAFreshComputation(): void {
		$storageStatistics = Server::get(StorageStatistics::class);
		$shareStatistics = Server::get(ShareStatistics::class);
		$sessionStatistics = Server::get(SessionStatistics::class);
		$databaseStatistics = Server::get(DatabaseStatistics::class);

		// Stores values as JSON, the way the Redis cache does.
		$stored = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')
			->willReturnCallback(function (string $key) use (&$stored) {
				return isset($stored[$key]) ? json_decode($stored[$key], true) : null;
			});
		$cache->expects($this->once())
			->method('set')
			->willReturnCallback(function (string $key, mixed $value) use (&$stored): bool {
				$stored[$key] = json_encode($value, JSON_THROW_ON_ERROR);
				return true;
			});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueInt')->willReturn(300);

		$statisticsCache = new StatisticsCache(
			$storageStatistics,
			$shareStatistics,
			$sessionStatistics,
			$databaseStatistics,
			$appConfig,
			$cacheFactory,
		);

		$computed = $statisticsCache->get();
		$cached = $statisticsCache->get();

		$this->assertSame([
			'storage' => $storageStatistics->getStorageStatistics(),
			'shares' => $shareStatistics->getShareStatistics(),
			'database' => $databaseStatistics->getDatabaseStatistics(),
			'activeUsers' => $sessionStatistics->getSessionStatistics(),
		], $computed);
		$this->assertSame($computed, $cached);
	}
}
