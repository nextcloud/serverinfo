<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Jobs;

use OCA\ServerInfo\OsStatus\Notifications\OsNotificationSender;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;

/**
 * Collects the operating system status once, when an admin asks for a check
 * on the operating system page.
 *
 * Like UpdateOsStatus it runs only from system cron, so the package manager
 * never holds a web server worker.
 *
 * @psalm-api
 */
class RefreshOsStatus extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private OsStatusCollector $collector,
		private OsNotificationSender $notifications,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
	}

	#[\Override]
	protected function run($argument): void {
		if ($this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax') !== 'cron') {
			return;
		}

		if ($this->collector->collectAndStore() !== null) {
			$this->notifications->sync();
		}
	}
}
