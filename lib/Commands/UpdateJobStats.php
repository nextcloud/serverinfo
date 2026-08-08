<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Commands;

use OC\Core\Command\Base;
use OCA\ServerInfo\Collector\BackgroundJobs;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @psalm-api
 */
class UpdateJobStats extends Base {
	public function __construct(
		private BackgroundJobs $backgroundJobs,
	) {
		parent::__construct();
	}

	#[\Override]
	public function configure(): void {
		parent::configure();
		$this->setName('serverinfo:update-job-statistics')
			->setDescription('Triggers an update of the background job statistics used in serverinfo');
	}

	public function execute(InputInterface $input, OutputInterface $output): int {
		$this->backgroundJobs->updateSlowestJobs();
		if ($output->isVerbose()) {
			$this->writeArrayInOutputFormat($input, $output, $this->backgroundJobs->get()['slowest']);
		}
		return 0;
	}
}
