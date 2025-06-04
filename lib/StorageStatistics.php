<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo;

use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

class StorageStatistics {

	public function __construct(
		private IDBConnection $connection,
		private IRootFolder $rootFolder,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
	) {
	}

	public function getStorageStatistics(): array {
		return [
			'num_users' => $this->countUserEntries(),
			'num_disabled_users' => $this->countDisabledUserEntries(),
			'num_files' => $this->getCountOf('filecache'),
			'num_storages' => $this->getCountOf('storages'),
			'num_storages_local' => $this->countStorages('local'),
			'num_storages_home' => $this->countStorages('home'),
			'num_storages_other' => $this->countStorages('other'),
			'size_appdata_storage' => $this->appConfig->getValueFloat('serverinfo', 'size_appdata_storage'),
			'num_files_appdata' => $this->getCountOf('appdata_files'),
		];
	}

	/**
	 * count number of users
	 */
	protected function countUserEntries(): int {
		return $this->userManager->countSeenUsers();
	}

	protected function countDisabledUserEntries(): int {
		return $this->userManager->countDisabledUsers();
	}

	protected function getCountOf(string $table): int {
		return $this->appConfig->getValueInt('serverinfo', 'cached_count_' . $table);
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

		$this->updateAppDataStorageStats();

		$this->appConfig->setValueInt('serverinfo', 'cached_count_filecache', $fileCount);
		$this->appConfig->setValueInt('serverinfo', 'cached_count_storages', $storageCount);
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
		return (int)$row['num_entries'];
	}

	public function updateAppDataStorageStats(): void {
		$appDataPath = $this->rootFolder->getAppDataDirectoryName();
		$appDataFolder = $this->rootFolder->get($appDataPath);
		$this->appConfig->setValueFloat('serverinfo', 'size_appdata_storage', $appDataFolder->getSize());

		$query = $this->connection->getQueryBuilder();
		$query->select($query->func()->count())
			->from('filecache')
			->where($query->expr()->like('path', $query->createNamedParameter($appDataPath . '%')));
		$fileResult = $query->executeQuery();
		$fileCount = (int)$fileResult->fetchOne();
		$fileResult->closeCursor();
		$this->appConfig->setValueInt('serverinfo', 'cached_count_appdata_files', $fileCount);
	}
}
