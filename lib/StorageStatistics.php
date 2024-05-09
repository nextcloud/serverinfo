<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo;

use OCP\IConfig;
use OCP\IDBConnection;

class StorageStatistics {
	private IDBConnection $connection;
	private IConfig $config;

	public function __construct(IDBConnection $connection, IConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
	}

	public function getStorageStatistics(): array {
		return [
			'num_users' => $this->countUserEntries(),
			'num_files' => $this->getCountOf('filecache'),
			'num_storages' => $this->getCountOf('storages'),
			'num_storages_local' => $this->countStorages('local'),
			'num_storages_home' => $this->countStorages('home'),
			'num_storages_other' => $this->countStorages('other'),
		];
	}

	/**
	 * count number of users
	 */
	protected function countUserEntries(): int {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_entries')
			->from('preferences')
			->where($query->expr()->eq('configkey', $query->createNamedParameter('lastLogin')));
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int) $row['num_entries'];
	}

	protected function getCountOf(string $table): int {
		return (int)$this->config->getAppValue('serverinfo', 'cached_count_' . $table, '0');
	}

	public function updateStorageCounts(): void {
		$storageCount = 0;
		$fileCount = 0;

		$fileQuery = $this->connection->getQueryBuilder();
		$fileQuery->select($fileQuery->func()->count())
			->from('filecache')
			->where($fileQuery->expr()->eq('storage', $fileQuery->createParameter('storageId')));

		$storageQuery = $this->connection->getQueryBuilder();
		$storageQuery->selectAlias('numeric_id', 'id')
			->from('storages');
		$storageResult = $storageQuery->executeQuery();
		while ($storageRow = $storageResult->fetch()) {
			$storageCount++;
			$fileQuery->setParameter('storageId', $storageRow['id']);
			$fileResult = $fileQuery->executeQuery();
			$fileCount += (int)$fileResult->fetchOne();
			$fileResult->closeCursor();
		}
		$storageResult->closeCursor();

		$this->config->setAppValue('serverinfo', 'cached_count_filecache', (string)$fileCount);
		$this->config->setAppValue('serverinfo', 'cached_count_storages', (string)$storageCount);
	}

	protected function countStorages(string $type): int {
		$query = $this->connection->getQueryBuilder();
		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_entries')
			->from('storages');
		if ($type === 'home') {
			$query->where($query->expr()->like('id', $query->createNamedParameter('home::%')));
		} elseif ($type === 'local') {
			$query->where($query->expr()->like('id', $query->createNamedParameter('local::%')));
		} elseif ($type === 'other') {
			$query->where($query->expr()->notLike('id', $query->createNamedParameter('home::%')));
			$query->andWhere($query->expr()->notLike('id', $query->createNamedParameter('local::%')));
		}
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return (int) $row['num_entries'];
	}
}
