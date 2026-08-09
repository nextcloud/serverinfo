<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Jobs;

use OCA\ServerInfo\StorageStatistics;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;

/**
 * @psalm-api
 */
class UpdateStorageStats extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private StorageStatistics $storageStatistics,
		IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval($appConfig->getValueInt('serverinfo', 'job_interval_storage_stats', 60 * 60 * 3));
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$this->storageStatistics->updateStorageCounts();
	}
}
