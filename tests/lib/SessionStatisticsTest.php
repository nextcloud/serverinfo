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
use Test\TestCase;

/**
 * Class SessionStatisticsTest
 *
 * @group DB
 * @package OCA\ServerInfo\Tests
 */
class SessionStatisticsTest extends TestCase {
	/** @var ITimeFactory | \PHPUnit_Framework_MockObject_MockObject */
	private $timeFactory;

	/** @var IDBConnection */
	private $connection;

	/** @var SessionStatistics */
	private $instance;

	private $table = 'authtoken';

	private $offset5Minutes = 300;

	private $offset1Hour = 3600;

	private $offset1Day = 86400;

	private $currentTime = 100000;

	protected function setUp(): void {
		parent::setUp();

		$this->timeFactory = $this->getMockBuilder('OCP\AppFramework\Utility\ITimeFactory')
			->disableOriginalConstructor()->getMock();

		$this->connection = \OC::$server->getDatabaseConnection();

		$this->instance = new SessionStatistics($this->connection, $this->timeFactory);
	}

	private function addDummyValues() {
		$this->addDummyValuesWithLastLogin($this->currentTime - $this->offset5Minutes + 1, 10);
		$this->addDummyValuesWithLastLogin($this->currentTime - $this->offset1Hour + 1, 20);
		$this->addDummyValuesWithLastLogin($this->currentTime - $this->offset1Day + 1, 30);
	}

	private function addDummyValuesWithLastLogin($lastActivity, $numOfEntries) {
		for ($i = 0; $i < $numOfEntries; $i++) {
			$query = $this->connection->getQueryBuilder();
			$query->insert($this->table)
				->values(
					[
						'uid' => $query->createNamedParameter('user-' . ($numOfEntries + $i % 2)),
						'login_name' => $query->createNamedParameter('user-' . ($numOfEntries + $i % 2)),
						'password' => $query->createNamedParameter('password'),
						'name' => $query->createNamedParameter('user agent'),
						'token' => $query->createNamedParameter('token-' . ($i + $numOfEntries * 10)),
						'type' => $query->createNamedParameter(0),
						'last_activity' => $query->createNamedParameter($lastActivity),
						'last_check' => $query->createNamedParameter($lastActivity),
					]
				);
			$query->execute();
		}
	}

	public function testGetSessionStatistics() {
		$this->addDummyValues();
		$this->timeFactory->expects($this->any())->method('getTime')
			->willReturn($this->currentTime);

		$result = $this->instance->getSessionStatistics();

		$this->assertSame(3, count($result));
		$this->assertSame(2, $result['last5minutes']);
		$this->assertSame(4, $result['last1hour']);
		$this->assertSame(6, $result['last24hours']);
	}
}
