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

namespace OCA\ServerInfo\Commands;

use OC\Core\Command\Base;
use OCA\ServerInfo\StorageStatistics;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateStorageStats extends Base {
	private StorageStatistics $storageStatistics;

	public function __construct(StorageStatistics $storageStatistics) {
		parent::__construct();

		$this->storageStatistics = $storageStatistics;
	}

	public function configure(): void {
		parent::configure();
		$this->setName('serverinfo:update-storage-statistics')
			->setDescription('Triggers an update of the counts related to storages used in serverinfo');
	}

	public function execute(InputInterface $input, OutputInterface $output): int {
		if ($output->isVeryVerbose()) {
			$this->writeMixedInOutputFormat($input, $output, 'Updating database counts. This might take a while.');
		}
		$this->storageStatistics->updateStorageCounts();
		if ($output->isVerbose()) {
			$this->writeArrayInOutputFormat($input, $output, $this->storageStatistics->getStorageStatistics());
		}
		return 0;
	}
}
