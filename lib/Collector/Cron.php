<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Collector;

use OCP\IAppConfig;

class Cron {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * `now` is the server clock: a skewed workstation would otherwise raise a
	 * false "cron is not running" alarm.
	 *
	 * @return array{
	 *     mode: string,
	 *     lastRun: int,
	 *     now: int
	 * }
	 */
	public function get(): array {
		return [
			'mode' => $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax'),
			'lastRun' => $this->appConfig->getValueInt('core', 'lastcron', 0),
			'now' => time(),
		];
	}
}
