<?php
/**
 * @copyright Copyright (c) 2016 Bjoern Schiessle <bjoern@schiessle.org>
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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

	/** @var  ITimeFactory | \PHPUnit_Framework_MockObject_MockObject */
	private $timeFactory;

	/** @var  IDBConnection */
	private $connection;

	/** @var  SessionStatistics */
	private $instance;

	private $table = 'authtoken';

	private $offset5Minutes = 300;

	private $offset1Hour = 3600;

    private $offset1Day = 86400;
    
    private $offset7Days = 604800;
    
    private $offset30Days = 2592000;

    private $currentTime = 10000000;

	protected function setUp(): void {
		parent::setUp();

		$this->timeFactory = $this->getMockBuilder('OCP\AppFramework\Utility\ITimeFactory')
			->disableOriginalConstructor()->getMock();

		$this->connection = \OC::$server->getDatabaseConnection();

		$this->instance = new SessionStatistics($this->connection, $this->timeFactory);
	}

	private function addDummyValues() {
		$this->addDummyValuesWithLastLogin($this->currentTime - $this->offset5Minutes +1, 10);
		$this->addDummyValuesWithLastLogin($this->currentTime - $this->offset1Hour +1, 20);
        $this->addDummyValuesWithLastLogin($this->currentTime - $this->offset1Day +1, 30);
        $this->addDummyValuesWithLastLogin($this->currentTime - $this->offset7Days +1, 40);
        $this->addDummyValuesWithLastLogin($this->currentTime - $this->offset30Days +1, 50);
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
						'token' => $query->createNamedParameter('token-' . ($i + $numOfEntries*10)),
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
        $this->assertSame(8, $result['last7days']);
        $this->assertSame(10, $result['last7days']);
	}
}
