<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
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

namespace OCA\ServerInfo\Jobs;

use OCA\ServerInfo\StorageStatistics;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

class UpdateStorageStats extends TimedJob {
	private StorageStatistics $storageStatistics;

	public function __construct(ITimeFactory $time, StorageStatistics $storageStatistics, IConfig $config) {
		$this->setInterval((int)$config->getAppValue('serverinfo', 'job_interval_storage_stats', (string)(60 * 60 * 3)));
		parent::__construct($time);

		$this->storageStatistics = $storageStatistics;
	}

	/**
	 * @inheritDoc
	 */
	protected function run($argument): void {
		$this->storageStatistics->updateStorageCounts();
	}
}
