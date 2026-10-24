<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\StorageStatistics;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * Class SessionStatisticsTest
 *
 * @group DB
 * @package OCA\ServerInfo\Tests
 */
class SessionStatisticsTest extends TestCase {
	private ITimeFactory&MockObject $timeFactory;
	private IDBConnection $connection;
	private SessionStatistics $instance;
	private int $currentTime;
	private const TABLE = 'preferences';
	private const OFFSET_5MIN = 300;
	private const OFFSET_1HOUR = 3600;
	private const OFFSET_1DAY = 86400;
	private const OFFSET_7DAYS = 604800;
	private const OFFSET_1MONTH = 2592000;
	private const OFFSET_3MONTHS = 7776000;
	private const OFFSET_6MONTHS = 15552000;
	private const OFFSET_1YEAR = 31536000;
	private const WINDOWS = [
		'last5minutes' => self::OFFSET_5MIN,
		'last1hour' => self::OFFSET_1HOUR,
		'last24hours' => self::OFFSET_1DAY,
		'last7days' => self::OFFSET_7DAYS,
		'last1month' => self::OFFSET_1MONTH,
		'last3months' => self::OFFSET_3MONTHS,
		'last6months' => self::OFFSET_6MONTHS,
		'lastyear' => self::OFFSET_1YEAR,
	];

	protected function setUp(): void {
		parent::setUp();

		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->connection = Server::get(IDBConnection::class);

		$this->instance = new SessionStatistics($this->connection, $this->timeFactory);

		// when running the tests locally, you may have other lastLogin values in the database.
		// using a timestamp in the future to workaround.
		$this->currentTime = time() + (400 * 24 * 60 * 60);

		$this->removeDummyValues();
		$this->addDummyValues();
	}

	protected function tearDown(): void {
		$this->removeDummyValues();
	}

	protected function removeDummyValues(): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->delete('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('login')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('lastLogin')))
			->andWhere($qb->expr()->like('userid', $qb->createNamedParameter('session-statistics-test%')));
		$qb->executeStatement();
	}

	private function addDummyValues(): void {
		$this->addDummyValuesWithLastLogin(self::OFFSET_5MIN, 10);
		$this->addDummyValuesWithLastLogin(self::OFFSET_5MIN, 11);
		$this->addDummyValuesWithLastLogin(self::OFFSET_1HOUR, 20);
		$this->addDummyValuesWithLastLogin(self::OFFSET_1HOUR, 21);
		$this->addDummyValuesWithLastLogin(self::OFFSET_1HOUR, 22);
		$this->addDummyValuesWithLastLogin(self::OFFSET_1DAY, 30);
		$this->addDummyValuesWithLastLogin(self::OFFSET_1MONTH, 50);
		$this->addDummyValuesWithLastLogin(self::OFFSET_3MONTHS, 60);
		$this->addDummyValuesWithLastLogin(self::OFFSET_6MONTHS, 70);
		$this->addDummyValuesWithLastLogin(self::OFFSET_1YEAR, 80);
		$this->addDummyValuesWithLastLogin(self::OFFSET_1YEAR, 81);
		$this->addDummyValuesWithLastLogin(self::OFFSET_1YEAR, 82);
	}

	private function addDummyValuesWithLastLogin(int $offset, int $id): void {
		$this->addLastLogin("session-statistics-test$id", (string)($this->currentTime - $offset + 1));
	}

	private function addLastLogin(string $userId, string $lastLogin): void {
		$query = $this->connection->getQueryBuilder();
		$query->insert(self::TABLE)
			->values(
				[
					'userid' => $query->createNamedParameter($userId),
					'appid' => $query->createNamedParameter('login'),
					'configkey' => $query->createNamedParameter('lastLogin'),
					'configvalue' => $query->createNamedParameter($lastLogin),
					'lazy' => $query->createNamedParameter(0),
					'type' => $query->createNamedParameter(0),
					'flags' => $query->createNamedParameter(0),
				]
			);
		$query->executeStatement();
	}

	public function testGetSessionStatistics() {
		$this->timeFactory->expects($this->any())->method('getTime')
			->willReturn($this->currentTime);

		$result = $this->instance->getSessionStatistics();

		$this->assertSame(8, count($result));
		$this->assertSame(2, $result['last5minutes']);  // 2 users in last 5 minutes
		$this->assertSame(5, $result['last1hour']);     // 2 + 3 users in last hour
		$this->assertSame(6, $result['last24hours']);   // 2 + 3 + 1 users in last day
		$this->assertSame(6, $result['last7days']);     // 2 + 3 + 1 + 0 users in last week
		$this->assertSame(7, $result['last1month']);    // 2 + 3 + 1 + 0 + 1 users in last month
		$this->assertSame(8, $result['last3months']);   // 2 + 3 + 1 + 0 + 1 + 1 users in last 3 months
		$this->assertSame(9, $result['last6months']);   // 2 + 3 + 1 + 0 + 1 + 1 + 1 users in last 6 months
		$this->assertSame(12, $result['lastyear']);     // 2 + 3 + 1 + 0 + 1 + 1 + 1 + 3 users in last year
		$this->assertSame($this->countPerWindow(), $result);
	}

	public function testWindowBoundaries(): void {
		$this->timeFactory->method('getTime')->willReturn($this->currentTime);
		foreach (self::WINDOWS as $key => $offset) {
			$this->addLastLogin("session-statistics-test-at-$key", (string)($this->currentTime - $offset));
			$this->addLastLogin("session-statistics-test-outside-$key", (string)($this->currentTime - $offset - 1));
		}

		$result = $this->instance->getSessionStatistics();

		// the base fixtures, plus each window's user at the cutoff and the
		// users just outside every smaller window
		$this->assertSame([
			'last5minutes' => 2 + 1,
			'last1hour' => 5 + 3,
			'last24hours' => 6 + 5,
			'last7days' => 6 + 7,
			'last1month' => 7 + 9,
			'last3months' => 8 + 11,
			'last6months' => 9 + 13,
			'lastyear' => 12 + 15,
		], $result);
		$this->assertSame($this->countPerWindow(), $result);
	}

	public function testNonNumericLastLogin(): void {
		$provider = $this->connection->getDatabaseProvider();
		if ($provider === IDBConnection::PLATFORM_POSTGRES || $provider === IDBConnection::PLATFORM_ORACLE) {
			$this->markTestSkipped('Casting a non-numeric lastLogin fails on this database');
		}
		$this->timeFactory->method('getTime')->willReturn($this->currentTime);
		$this->addLastLogin('session-statistics-test-empty', '');
		$this->addLastLogin('session-statistics-test-text', 'yesterday');
		$this->addLastLogin('session-statistics-test-mixed', ($this->currentTime - 10) . 'x');

		$this->assertSame($this->countPerWindow(), $this->instance->getSessionStatistics());
	}

	public function testNoLastLoginRows(): void {
		$this->timeFactory->method('getTime')->willReturn($this->currentTime);
		$this->connection->beginTransaction();
		try {
			$qb = $this->connection->getQueryBuilder();
			$qb->delete('preferences')
				->where($qb->expr()->eq('appid', $qb->createNamedParameter('login')))
				->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('lastLogin')));
			$qb->executeStatement();

			$this->assertSame(array_fill_keys(array_keys(self::WINDOWS), 0), $this->instance->getSessionStatistics());
			$this->assertSame(0, $this->createStorageStatistics()->getStorageStatistics()['num_users']);
		} finally {
			$this->connection->rollBack();
		}
	}

	public function testSeenUsersCountEveryLastLogin(): void {
		$storageStatistics = $this->createStorageStatistics();
		$before = $storageStatistics->getStorageStatistics()['num_users'];
		$this->addLastLogin('session-statistics-test-empty', '');
		$this->addLastLogin('session-statistics-test-old', '1');

		$this->assertSame($before + 2, $storageStatistics->getStorageStatistics()['num_users']);
	}

	private function createStorageStatistics(): StorageStatistics {
		return new StorageStatistics(
			$this->connection,
			$this->createMock(IRootFolder::class),
			$this->createMock(IAppConfig::class),
			Server::get(IUserManager::class),
		);
	}

	/**
	 * Counts each window with its own query, as a reference for the single
	 * pass in SessionStatistics.
	 *
	 * @return array<string, int>
	 */
	private function countPerWindow(): array {
		$counts = [];
		foreach (self::WINDOWS as $key => $offset) {
			$qb = $this->connection->getQueryBuilder();
			$qb->select($qb->func()->count('userid'))
				->from('preferences')
				->where($qb->expr()->eq('appid', $qb->createNamedParameter('login')))
				->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('lastLogin')))
				->andWhere($qb->expr()->gte(
					$qb->expr()->castColumn('configvalue', IQueryBuilder::PARAM_INT),
					$qb->createNamedParameter($this->currentTime - $offset, IQueryBuilder::PARAM_INT),
					IQueryBuilder::PARAM_INT,
				));
			$result = $qb->executeQuery();
			$counts[$key] = (int)$result->fetchOne();
			$result->closeCursor();
		}
		return $counts;
	}
}
