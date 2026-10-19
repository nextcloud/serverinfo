<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Health;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Whether the system clock is kept in sync. A drifting clock breaks TOTP
 * second factors, signed federation requests and S3 storage.
 *
 * @psalm-api
 */
class TimeSyncProbe {
	public function __construct(
		private IHost $host,
	) {
	}

	/**
	 * @return array{synchronized: ?bool, source: ?string}
	 */
	public function probe(): array {
		$result = $this->host->run('timedatectl', ['show', '--property=NTPSynchronized', '--value'], 5);
		if ($result !== null && $result->succeeded()) {
			$value = trim($result->stdout);
			if ($value === 'yes' || $value === 'no') {
				return ['synchronized' => $value === 'yes', 'source' => 'timedatectl'];
			}
		}

		// systemd-timesyncd touches this file once it has synchronised.
		if ($this->host->fileExists('/run/systemd/timesync/synchronized')) {
			return ['synchronized' => true, 'source' => 'systemd-timesyncd'];
		}

		$result = $this->host->run('chronyc', ['-n', 'tracking'], 5);
		if ($result !== null && $result->succeeded() && preg_match('/^Leap status\s*:\s*(.+)$/m', $result->stdout, $matches) === 1) {
			return ['synchronized' => trim($matches[1]) === 'Normal', 'source' => 'chrony'];
		}

		return ['synchronized' => null, 'source' => null];
	}
}
