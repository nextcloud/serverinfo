<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Commands;

use OC\Core\Command\Base;
use OCA\ServerInfo\StorageStatistics;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @psalm-api
 */
class UpdateStorageStats extends Base {
	private StorageStatistics $storageStatistics;

	public function __construct(StorageStatistics $storageStatistics) {
		parent::__construct();

		$this->storageStatistics = $storageStatistics;
	}

	#[\Override]
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
