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
 * @psalm-api
 */
class UpdateOsStatus extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OsStatusCollector $collector,
		IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(max(300, $appConfig->getValueInt(Application::APP_ID, ConfigLexicon::JOB_INTERVAL_OS_STATUS)));
	}

	#[\Override]
	protected function run($argument): void {
		$this->collector->collectAndStore();
	}
}
