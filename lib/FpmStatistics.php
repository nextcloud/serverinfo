<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IDateTimeFormatter;

class FpmStatistics {
	public function __construct(
		private IDateTimeFormatter $dateTimeFormatter,
	) {
	}

	/**
	 * Returns FPM statistics, with these keys:
	 * "pool"
	 * "process-manager"
	 * "start-time"
	 * "start-since"
	 * "accepted-conn"
	 * "listen-queue"
	 * "max-listen-queue"
	 * "listen-queue-len"
	 * "idle-processes"
	 * "active-processes"
	 * "total-processes"
	 * "max-active-processes"
	 * "max-children-reached"
	 * "slow-requests"
	 *
	 */
	public function getFpmStatistics(): array|false {
		if (!function_exists('fpm_get_status')) {
			return false;
		}
		$status = fpm_get_status();
		$status['start-time'] = $this->dateTimeFormatter->formatDateTime($status['start-time']);
		unset($status['procs']);
		return $status;
	}
}
