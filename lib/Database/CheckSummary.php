<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Database;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCP\AppFramework\Services\IAppConfig;

/**
 * Remembers how the last run of the database checks went.
 *
 * The setup check on Settings → Overview needs an answer on every page load,
 * and running fifty-odd rules against a live server that often is not
 * reasonable. Recording the outcome whenever the checks do run gives that page
 * something cheap to read, and opening the database page refreshes it, so
 * fixing a finding clears the warning without waiting for anything.
 */
class CheckSummary {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param list<array<string, mixed>> $results as returned by the advisor
	 */
	public function record(bool $supported, array $results): void {
		$failing = array_filter($results, static fn (array $r): bool => ($r['status'] ?? '') === 'fail');

		$this->appConfig->setAppValueArray(ConfigLexicon::CACHED_DB_CHECK, [
			'ranAt' => time(),
			'supported' => $supported,
			'failing' => count($failing),
			'alerts' => $this->countSeverity($failing, 'alert'),
			'warnings' => $this->countSeverity($failing, 'warning'),
			'notices' => $this->countSeverity($failing, 'notice'),
		]);
	}

	/**
	 * @return array{ranAt: int, supported: bool, failing: int, alerts: int, warnings: int, notices: int}|null
	 *                                                                                                         null when nothing has been recorded yet or the record is older than $maxAge
	 */
	public function latest(int $maxAge): ?array {
		$stored = $this->appConfig->getAppValueArray(ConfigLexicon::CACHED_DB_CHECK);
		if ($stored === [] || !isset($stored['ranAt'])) {
			return null;
		}

		if (time() - (int)$stored['ranAt'] > $maxAge) {
			return null;
		}

		return [
			'ranAt' => (int)$stored['ranAt'],
			'supported' => (bool)($stored['supported'] ?? false),
			'failing' => (int)($stored['failing'] ?? 0),
			'alerts' => (int)($stored['alerts'] ?? 0),
			'warnings' => (int)($stored['warnings'] ?? 0),
			'notices' => (int)($stored['notices'] ?? 0),
		];
	}

	/**
	 * @param array<array<string, mixed>> $failing
	 */
	private function countSeverity(array $failing, string $severity): int {
		return count(array_filter($failing, static fn (array $r): bool => ($r['severity'] ?? '') === $severity));
	}
}
