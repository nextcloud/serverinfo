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

use OC\Files\View;
use OCP\IDBConnection;

class ShareStatistics {

	/** @var IDBConnection */
	protected $connection;

	/** @var  View on data/ */
	protected $view;

	/**
	 * ShareStatistics constructor.
	 *
	 * @param IDBConnection $connection
	 */
	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @return array (string => string|int)
	 */
	public function getShareStatistics() {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_entries')
			->addSelect(['permissions', 'share_type'])
			->from('share')
			->addGroupBy('permissions')
			->addGroupBy('share_type');
		$result = $query->execute();

		$data = [
			'num_shares' => $this->countEntries('share'),
			'num_shares_user' => $this->countShares(\OCP\Share::SHARE_TYPE_USER),
			'num_shares_groups' => $this->countShares(\OCP\Share::SHARE_TYPE_GROUP),
			'num_shares_link' => $this->countShares(\OCP\Share::SHARE_TYPE_LINK),
			'num_shares_mail' => $this->countShares(\OCP\Share::SHARE_TYPE_EMAIL),
			'num_shares_room' => $this->countShares(\OCP\Share::SHARE_TYPE_ROOM),
			'num_shares_link_no_password' => $this->countShares(\OCP\Share::SHARE_TYPE_LINK, true),
			'num_fed_shares_sent' => $this->countShares(\OCP\Share::SHARE_TYPE_REMOTE),
			'num_fed_shares_received' => $this->countEntries('share_external'),
		];
		while ($row = $result->fetch()) {
			$data['permissions_' . $row['share_type'] . '_' . $row['permissions']] = $row['num_entries'];
		}
		$result->closeCursor();

		return $data;
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
	 * @param int $type
	 * @param bool $noShareWith
	 * @return int
	 */
	protected function countShares($type, $noShareWith = false) {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_entries')
			->from('share')
			->where($query->expr()->eq('share_type', $query->createNamedParameter($type)));

		if ($noShareWith) {
			$query->andWhere($query->expr()->isNull('share_with'));
		}

		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return (int) $row['num_entries'];
	}
}
