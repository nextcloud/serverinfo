<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IL10N;

class UptimeFormatter {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * Return the uptime of the system as human readable value
	 */
	public function format(int $uptime): string {
		if ($uptime === -1) {
			return $this->l10n->t('Unknown');
		}

		try {
			$boot = new \DateTime($uptime . ' seconds ago');
		} catch (\Exception $e) {
			return $this->l10n->t('Unknown');
		}

		$interval = $boot->diff(new \DateTime());
		$days = $interval->days;
		$hours = $interval->h;
		$minutes = $interval->i;
		$seconds = $interval->s;

		if ($days > 0) {
			return $this->l10n->t('%1$d days, %2$d hours, %3$d minutes, %4$d seconds', [$days, $hours, $minutes, $seconds]);
		}
		return $this->l10n->t('%1$d hours, %2$d minutes, %3$d seconds', [$hours, $minutes, $seconds]);
	}
}
