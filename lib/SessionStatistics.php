<?php

declare(strict_types=1);

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
	private const OFFSET_5MIN = 300;
	private const OFFSET_1HOUR = 3600;
	private const OFFSET_1DAY = 86400;

	private IDBConnection $connection;
	private ITimeFactory $timeFactory;

	public function __construct(IDBConnection $connection, ITimeFactory $timeFactory) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
	}

	public function getSessionStatistics(): array {
		return [
			'last5minutes' => $this->getNumberOfActiveUsers(self::OFFSET_5MIN),
			'last1hour' => $this->getNumberOfActiveUsers(self::OFFSET_1HOUR),
			'last24hours' => $this->getNumberOfActiveUsers(self::OFFSET_1DAY),
		];
	}

	/**
	 * get number of active user in a given time span
	 *
	 * @param int $offset seconds
	 */
	private function getNumberOfActiveUsers(int $offset): int {
		$query = $this->connection->getQueryBuilder();
		$query->select('uid')
			->from('authtoken')
			->where($query->expr()->gte(
				'last_activity',
				$query->createNamedParameter($this->timeFactory->getTime() - $offset)
			))->groupBy('uid');

		$result = $query->executeQuery();
		$activeUsers = $result->fetchAll();
		$result->closeCursor();

		return count($activeUsers);
	}
}
