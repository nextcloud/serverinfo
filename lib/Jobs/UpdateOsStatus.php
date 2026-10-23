<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Jobs;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;

/**
 * Collects the operating system status.
 *
 * Time sensitive on purpose: a maintenance window would hold a pending
 * security update back from the admin for most of a day.
 *
 * Runs only from system cron: the commands may take minutes, which under
 * AJAX or webcron would hold a web server worker.
 *
 * @psalm-api
 */
class UpdateOsStatus extends TimedJob {
	private const MIN_INTERVAL = 300;

	public function __construct(
		ITimeFactory $time,
		private OsStatusCollector $collector,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(self::interval($appConfig));
	}

	#[\Override]
	protected function run($argument): void {
		if (!self::runsFromSystemCron($this->appConfig)) {
			return;
		}

		$this->collector->collectAndStore();
	}

	/**
	 * Seconds between two collections.
	 */
	public static function interval(IAppConfig $appConfig): int {
		return max(self::MIN_INTERVAL, $appConfig->getValueInt(Application::APP_ID, ConfigLexicon::JOB_INTERVAL_OS_STATUS));
	}

	public static function runsFromSystemCron(IAppConfig $appConfig): bool {
		return $appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax') === 'cron';
	}
}
