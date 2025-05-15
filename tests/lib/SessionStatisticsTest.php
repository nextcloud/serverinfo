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
	private const TABLE = 'preferences';
	private const OFFSET_5MIN = 300;
	private const OFFSET_1HOUR = 3600;
	private const OFFSET_1DAY = 86400;
	private const OFFSET_7DAYS = 604800;
	private const OFFSET_1MONTH = 2592000;
	private const OFFSET_3MONTHS = 7776000;
	private const OFFSET_6MONTHS = 15552000;
	private const OFFSET_1YEAR = 31536000;
	private const CURRENT_TIME = 100000000;


	protected function setUp(): void {
		parent::setUp();

		$this->timeFactory = $this->createMock(ITimeFactory::class);

		$this->connection = Server::get(IDBConnection::class);

		$this->instance = new SessionStatistics($this->connection, $this->timeFactory);
	}

	private function addDummyValues(): void {
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_5MIN + 1, 10);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_5MIN + 1, 11);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_1HOUR + 1, 20);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_1HOUR + 1, 21);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_1HOUR + 1, 22);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_1DAY + 1, 30);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_1MONTH + 1, 50);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_3MONTHS + 1, 60);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_6MONTHS + 1, 70);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_1YEAR + 1, 80);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_1YEAR + 1, 81);
		$this->addDummyValuesWithLastLogin(self::CURRENT_TIME - self::OFFSET_1YEAR + 1, 82);
	}

	private function addDummyValuesWithLastLogin($lastActivity, $id): void {
		$query = $this->connection->getQueryBuilder();
		$query->insert(self::TABLE)
			->values(
				[
					'userid' => $query->createNamedParameter("user-$id"),
					'appid' => $query->createNamedParameter('login'),
					'configkey' => $query->createNamedParameter('lastLogin'),
					'configvalue' => $query->createNamedParameter($lastActivity),
					'lazy' => $query->createNamedParameter(0),
					'type' => $query->createNamedParameter(0),
					'flags' => $query->createNamedParameter(0),
				]
			);
		$query->executeStatement();
	}

	public function testGetSessionStatistics() {
		$this->addDummyValues();
		$this->timeFactory->expects($this->any())->method('getTime')
			->willReturn(self::CURRENT_TIME);

		$result = $this->instance->getSessionStatistics();

		$this->assertSame(8, count($result));
		$this->assertSame(2, $result['last5minutes']);
		$this->assertSame(3, $result['last1hour']);
		$this->assertSame(1, $result['last24hours']);
		$this->assertSame(0, $result['last7days']);
		$this->assertSame(1, $result['last1month']);
		$this->assertSame(1, $result['last3months']);
		$this->assertSame(1, $result['last6months']);
		$this->assertSame(3, $result['lastyear']);
	}
}
