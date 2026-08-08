<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Jobs;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\Collector\BackgroundJobs;
use OCA\ServerInfo\Config\ConfigLexicon;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;

/**
 * @psalm-api
 */
class UpdateJobStats extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private BackgroundJobs $backgroundJobs,
		IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval($appConfig->getValueInt(Application::APP_ID, ConfigLexicon::JOB_INTERVAL_JOB_STATS));
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	#[\Override]
	protected function run($argument): void {
		$this->backgroundJobs->updateSlowestJobs();
	}
}
