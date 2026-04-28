<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IAppConfig;
use OCP\IConfig;

class CronInfo {
	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @return array{
	 *     mode: string,
	 *     lastRun: int,
	 *     secondsSince: int,
	 *     status: string
	 * }
	 */
	public function getCronInfo(): array {
		$mode = $this->config->getAppValue('core', 'backgroundjobs_mode', 'ajax');
		$lastRun = $this->appConfig->getValueInt('core', 'lastcron', 0);
		$secondsSince = $lastRun > 0 ? max(0, time() - $lastRun) : -1;

		return [
			'mode' => $mode,
			'lastRun' => $lastRun,
			'secondsSince' => $secondsSince,
			'status' => $this->statusFor($mode, $secondsSince),
		];
	}

	private function statusFor(string $mode, int $secondsSince): string {
		if ($secondsSince < 0) {
			return 'critical';
		}
		// Cron job runs every 5 minutes; allow a generous grace period.
		if ($mode === 'cron') {
			if ($secondsSince > 3600) {
				return 'critical';
			}
			if ($secondsSince > 900) {
				return 'warning';
			}
			return 'ok';
		}
		// Webcron / ajax modes: looser thresholds.
		if ($secondsSince > 7200) {
			return 'critical';
		}
		if ($secondsSince > 3600) {
			return 'warning';
		}
		return 'ok';
	}
}
