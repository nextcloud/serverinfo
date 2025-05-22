<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\SessionStatistics;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
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
	private const OFFSET_1MONTH = 2592000;
	private const OFFSET_3MONTHS = 7776000;
	private const OFFSET_6MONTHS = 15552000;
	private const OFFSET_1YEAR = 31536000;


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
		$query = $this->connection->getQueryBuilder();
		$query->insert(self::TABLE)
			->values(
				[
					'userid' => $query->createNamedParameter("session-statistics-test$id"),
					'appid' => $query->createNamedParameter('login'),
					'configkey' => $query->createNamedParameter('lastLogin'),
					'configvalue' => $query->createNamedParameter((string)($this->currentTime - $offset + 1)),
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
	}
}
