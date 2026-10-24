<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\Collector\Opcache;
use OCA\ServerInfo\Collector\Php;
use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\Controller\ApiController;
use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\FpmStatistics;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\StaticData;
use OCA\ServerInfo\StatisticsCache;
use OCA\ServerInfo\StorageStatistics;
use OCA\ServerInfo\SystemStatistics;
use OCA\ServerInfo\UptimeFormatter;
use OCP\AppFramework\Services\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class StatisticsCacheTest extends TestCase {
	private const STORAGE = ['num_users' => 7, 'num_files' => 708860, 'size_appdata_storage' => 1024.0];
	private const SHARES = ['num_shares' => 1, 'permissions_3_1' => '1'];
	private const DATABASE = ['type' => 'pgsql', 'version' => '16.4', 'size' => 331382784];
	private const ACTIVE_USERS = ['last5minutes' => 2, 'last1hour' => 3];

	private StorageStatistics&MockObject $storageStatistics;
	private ShareStatistics&MockObject $shareStatistics;
	private SessionStatistics&MockObject $sessionStatistics;
	private DatabaseStatistics&MockObject $databaseStatistics;
	private IAppConfig&MockObject $appConfig;
	private ICache&MockObject $cache;
	private ICacheFactory&MockObject $cacheFactory;
	/** @var array<string, string> */
	private array $stored = [];
	/** @var list<int|null> */
	private array $storedTtls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->storageStatistics = $this->createMock(StorageStatistics::class);
		$this->storageStatistics->method('getStorageStatistics')->willReturn(self::STORAGE);
		$this->shareStatistics = $this->createMock(ShareStatistics::class);
		$this->shareStatistics->method('getShareStatistics')->willReturn(self::SHARES);
		$this->sessionStatistics = $this->createMock(SessionStatistics::class);
		$this->sessionStatistics->method('getSessionStatistics')->willReturn(self::ACTIVE_USERS);
		$this->databaseStatistics = $this->createMock(DatabaseStatistics::class);
		$this->databaseStatistics->method('getDatabaseStatistics')->willReturn(self::DATABASE);
		$this->appConfig = $this->createMock(IAppConfig::class);

		// Stores values as JSON, the way the Redis cache does.
		$this->cache = $this->createMock(ICache::class);
		$this->cache->method('get')
			->willReturnCallback(fn (string $key) => isset($this->stored[$key]) ? json_decode($this->stored[$key], true) : null);
		$this->cache->method('set')
			->willReturnCallback(function (string $key, mixed $value, int $ttl = 0): bool {
				$this->stored[$key] = json_encode($value, JSON_THROW_ON_ERROR);
				$this->storedTtls[] = $ttl;
				return true;
			});
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('createDistributed')->with('serverinfo')->willReturn($this->cache);
	}

	private function statisticsCache(int $ttl = 300): StatisticsCache {
		$this->appConfig->method('getAppValueInt')
			->with(ConfigLexicon::STATISTICS_CACHE_TTL)
			->willReturn($ttl);

		return new StatisticsCache(
			$this->storageStatistics,
			$this->shareStatistics,
			$this->sessionStatistics,
			$this->databaseStatistics,
			$this->appConfig,
			$this->cacheFactory,
		);
	}

	private function expected(): array {
		return [
			'storage' => self::STORAGE,
			'shares' => self::SHARES,
			'database' => self::DATABASE,
			'activeUsers' => self::ACTIVE_USERS,
		];
	}

	public function testMissComputesAndStoresForTheTtl(): void {
		$this->storageStatistics->expects($this->once())->method('getStorageStatistics');
		$this->shareStatistics->expects($this->once())->method('getShareStatistics');
		$this->sessionStatistics->expects($this->once())->method('getSessionStatistics');
		$this->databaseStatistics->expects($this->once())->method('getDatabaseStatistics');

		$this->assertSame($this->expected(), $this->statisticsCache(120)->get());
		$this->assertSame([120], $this->storedTtls);
	}

	public function testHitServesTheSameValuesWithoutQuerying(): void {
		$this->storageStatistics->expects($this->once())->method('getStorageStatistics');
		$this->shareStatistics->expects($this->once())->method('getShareStatistics');
		$this->sessionStatistics->expects($this->once())->method('getSessionStatistics');
		$this->databaseStatistics->expects($this->once())->method('getDatabaseStatistics');
		$statisticsCache = $this->statisticsCache();

		$first = $statisticsCache->get();
		$second = $statisticsCache->get();

		$this->assertSame($first, $second);
		$this->assertSame($this->expected(), $second);
	}

	public function testZeroTtlTurnsTheCacheOff(): void {
		$this->storageStatistics->expects($this->exactly(2))->method('getStorageStatistics');
		$this->databaseStatistics->expects($this->exactly(2))->method('getDatabaseStatistics');
		$this->cache->expects($this->never())->method('get');
		$this->cache->expects($this->never())->method('set');
		$statisticsCache = $this->statisticsCache(0);

		$this->assertSame($this->expected(), $statisticsCache->get());
		$this->assertSame($this->expected(), $statisticsCache->get());
	}

	public static function dataTtl(): array {
		return [
			'default' => [300, 300],
			'off' => [0, 0],
			'negative' => [-5, 0],
			'longest' => [StatisticsCache::MAX_TTL, StatisticsCache::MAX_TTL],
			'too long' => [86400, StatisticsCache::MAX_TTL],
		];
	}

	#[DataProvider('dataTtl')]
	public function testTtlIsClamped(int $configured, int $expected): void {
		$this->assertSame($expected, $this->statisticsCache($configured)->ttl());
	}

	public function testNegativeTtlTurnsTheCacheOff(): void {
		$this->cache->expects($this->never())->method('set');

		$this->assertSame($this->expected(), $this->statisticsCache(-1)->get());
	}

	public static function dataInvalidEntry(): array {
		$complete = ['storage' => [], 'shares' => [], 'database' => [], 'activeUsers' => []];
		return [
			'not a string' => [$complete],
			'not JSON' => ['{"storage":'],
			'not an object' => ['"stale"'],
			'block missing' => [json_encode(['storage' => [], 'shares' => [], 'database' => []])],
			'block not an array' => [json_encode(['activeUsers' => 3] + $complete)],
		];
	}

	#[DataProvider('dataInvalidEntry')]
	public function testInvalidEntryIsRecomputed(mixed $entry): void {
		$this->stored['statistics'] = json_encode($entry, JSON_THROW_ON_ERROR);
		$this->storageStatistics->expects($this->once())->method('getStorageStatistics');

		$this->assertSame($this->expected(), $this->statisticsCache()->get());
		$this->assertSame($this->expected(), json_decode(json_decode($this->stored['statistics'], true), true));
	}

	public function testUnencodableStatisticsAreServedUncached(): void {
		$database = ['type' => 'mysql', 'version' => "\xB1\x31", 'size' => 1];
		$this->databaseStatistics = $this->createMock(DatabaseStatistics::class);
		$this->databaseStatistics->expects($this->exactly(2))
			->method('getDatabaseStatistics')
			->willReturn($database);
		$this->cache->expects($this->never())->method('set');
		$statisticsCache = $this->statisticsCache();

		$this->assertSame($database, $statisticsCache->get()['database']);
		$this->assertSame($database, $statisticsCache->get()['database']);
	}

	public function testApiAndPageShareOneEntry(): void {
		$this->storageStatistics->expects($this->once())->method('getStorageStatistics');
		$this->shareStatistics->expects($this->once())->method('getShareStatistics');
		$this->sessionStatistics->expects($this->once())->method('getSessionStatistics');
		$this->databaseStatistics->expects($this->once())->method('getDatabaseStatistics');
		$statisticsCache = $this->statisticsCache();

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('NC-Token')->willReturn('token');
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->with('serverinfo', 'token', '')->willReturn('token');
		$api = new ApiController(
			'serverinfo',
			$request,
			$config,
			$this->createMock(IGroupManager::class),
			null,
			$this->createMock(Os::class),
			$this->createMock(SystemStatistics::class),
			$statisticsCache,
			$this->createMock(PhpStatistics::class),
			$this->createMock(FpmStatistics::class),
			$this->createMock(UptimeFormatter::class),
			$this->createMock(OsStatusService::class),
		);
		$page = new StaticData(
			$this->createMock(Os::class),
			$this->createMock(IURLGenerator::class),
			$statisticsCache,
			$this->createMock(Php::class),
			$this->createMock(Opcache::class),
			$this->createMock(FpmStatistics::class),
			$this->createMock(SystemStatistics::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(OsStatusService::class),
		);

		$info = $api->info()->getData();
		$data = $page->getData();

		$this->assertSame(self::STORAGE, $info['nextcloud']['storage']);
		$this->assertSame(self::SHARES, $info['nextcloud']['shares']);
		$this->assertSame(self::DATABASE, $info['server']['database']);
		$this->assertSame(self::ACTIVE_USERS, $info['activeUsers']);
		$this->assertSame(self::STORAGE, $data['storage']);
		$this->assertSame(self::SHARES, $data['shares']);
		$this->assertSame(self::DATABASE, $data['database']);
		$this->assertSame(self::ACTIVE_USERS, $data['activeUsers']);
	}
}
