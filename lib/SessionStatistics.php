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


namespace OCA\ServerInfo;


use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class SessionStatistics
 *
 * get active users
 *
 * @group DB
 * @package OCA\ServerInfo
 */
class SessionStatistics {

	/** @var IDBConnection */
	private $connection;

	/** @var ITimeFactory */
	private $timeFactory;

	private $offset5Minutes = 300;

	private $offset1Hour = 3600;

	private $offset1Day = 86400;

	/**
	 * SessionStatistics constructor.
	 *
	 * @param IDBConnection $connection
	 * @param ITimeFactory $timeFactory
	 */
	public function __construct(IDBConnection $connection, ITimeFactory $timeFactory) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
	}


	public function getSessionStatistics() {
		return [
			'last5minutes' => $this->getNumberOfActiveUsers($this->offset5Minutes),
			'last1hour' => $this->getNumberOfActiveUsers($this->offset1Hour),
			'last24hours' => $this->getNumberOfActiveUsers($this->offset1Day)
		];
	}

	/**
	 * get number of active user in a given time span
	 *
	 * @param int $offset seconds
	 * @return int
	 */
	private function getNumberOfActiveUsers($offset) {

		$query = $this->connection->getQueryBuilder();
		$query->select('uid')
			->from('authtoken')
			->where($query->expr()->gte(
				'last_activity',
				$query->createNamedParameter($this->timeFactory->getTime() - $offset)
			))->groupBy('uid');

		$result = $query->execute();
		$activeUsers = $result->fetchAll();
		$result->closeCursor();

		return count($activeUsers);
	}

}
