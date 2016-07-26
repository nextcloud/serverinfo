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

use OCP\IDBConnection;

class StorageStatistics {

	/** @var  IDBConnection */
	private $connection;

	/**
	 * SystemStatistics constructor.
	 *
	 * @param IDBConnection $connection
	 */
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	public function getStorageStatistics() {
		return [
			'num_users' => $this->countUserEntries(),
			'num_files' => $this->countEntries('filecache'),
			'num_storages' => $this->countEntries('storages'),
			'num_storages_local' => $this->countStorages('local'),
			'num_storages_home' => $this->countStorages('home'),
			'num_storages_other' => $this->countStorages('other'),
		];
	}

	/**
	 * count number of users
	 *
	 * @return int
	 */
	protected function countUserEntries() {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_entries')
			->from('preferences')
			->where($query->expr()->eq('configkey', $query->createNamedParameter('lastLogin')));
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();
		return (int) $row['num_entries'];
	}

	/**
	 * @param string $tableName
	 * @return int
	 */
	protected function countEntries($tableName) {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_entries')
			->from($tableName);
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();
		return (int) $row['num_entries'];
	}

	/**
	 * @param string $type
	 * @return int
	 */
	protected function countStorages($type) {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_entries')
			->from('storages');
		if ($type === 'home') {
			$query->where($query->expr()->like('id', $query->createNamedParameter('home::%')));
		} else if ($type === 'local') {
			$query->where($query->expr()->like('id', $query->createNamedParameter('local::%')));
		} else if ($type === 'other') {
			$query->where($query->expr()->notLike('id', $query->createNamedParameter('home::%')));
			$query->andWhere($query->expr()->notLike('id', $query->createNamedParameter('local::%')));
		}
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();
		return (int) $row['num_entries'];
	}

}
