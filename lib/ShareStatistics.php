<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo;

use OCP\IDBConnection;

class ShareStatistics {
	protected IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	/**
	 * @return array (string => string|int)
	 */
	public function getShareStatistics(): array {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_entries')
			->addSelect(['permissions', 'share_type'])
			->from('share')
			->addGroupBy('permissions')
			->addGroupBy('share_type');
		$result = $query->executeQuery();

		$data = [
			'num_shares' => $this->countEntries('share'),
			'num_shares_user' => $this->countShares(\OCP\Share\IShare::TYPE_USER),
			'num_shares_groups' => $this->countShares(\OCP\Share\IShare::TYPE_GROUP),
			'num_shares_link' => $this->countShares(\OCP\Share\IShare::TYPE_LINK),
			'num_shares_mail' => $this->countShares(\OCP\Share\IShare::TYPE_EMAIL),
			'num_shares_room' => $this->countShares(\OCP\Share\IShare::TYPE_ROOM),
			'num_shares_link_no_password' => $this->countShares(\OCP\Share\IShare::TYPE_LINK, true),
			'num_fed_shares_sent' => $this->countShares(\OCP\Share\IShare::TYPE_REMOTE),
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
	protected function countEntries(string $tableName): int {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_entries')
			->from($tableName);
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)$row['num_entries'];
	}

	/**
	 * @param int $type
	 * @param bool $noPassword
	 * @return int
	 */
	protected function countShares(int $type, bool $noPassword = false): int {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_entries')
			->from('share')
			->where($query->expr()->eq('share_type', $query->createNamedParameter($type)));

		if ($noPassword) {
			$query->andWhere($query->expr()->isNull('password'));
		}

		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)$row['num_entries'];
	}
}
