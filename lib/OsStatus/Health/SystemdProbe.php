<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Health;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Asks systemd about units. Listing units and their state needs no privilege.
 *
 * @psalm-api
 */
class SystemdProbe {
	public function __construct(
		private IHost $host,
	) {
	}

	/**
	 * @return list<string>|null failed unit names, null when systemd cannot be asked
	 */
	public function getFailedUnits(): ?array {
		$result = $this->host->run('systemctl', ['list-units', '--failed', '--all', '--no-legend', '--plain', '--no-pager'], 10);
		if ($result === null || !$result->succeeded()) {
			return null;
		}

		return self::parseUnitList($result->stdout);
	}

	/**
	 * @return list<string>
	 */
	public static function parseUnitList(string $output): array {
		$units = [];
		foreach (preg_split('/\R/', $output) ?: [] as $line) {
			// Older systemd puts a status bullet in front of each unit even with --plain.
			$line = trim((string)preg_replace('/^[\x{25CF}\x{00D7}*]\s*/u', '', trim($line)));
			if (preg_match('/^([A-Za-z0-9@._:\\\\-]+\.(service|socket|timer|mount|path|target|scope|slice|automount|swap|device))\s/', $line . ' ', $matches) === 1) {
				$units[] = $matches[1];
			}
		}

		return array_values(array_unique($units));
	}

	/**
	 * @return string|null "enabled", "disabled", "static", "masked"… or null when unknown
	 */
	public function getUnitFileState(string $unit): ?string {
		if (preg_match('/^[A-Za-z0-9@._-]+$/', $unit) !== 1) {
			return null;
		}

		$result = $this->host->run('systemctl', ['is-enabled', $unit], 5);
		if ($result === null) {
			return null;
		}

		$state = trim($result->stdout);

		return preg_match('/^[a-z-]{2,20}$/', $state) === 1 ? $state : null;
	}

	public function isRunning(): bool {
		return $this->host->fileExists('/run/systemd/system');
	}
}
