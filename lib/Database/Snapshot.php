<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

/**
 * Frozen view of the database's status + variables at a point in time.
 *
 * Built by {@see DatabaseProbe}, consumed by {@see \OCA\ServerInfo\Database\Advisor}.
 *
 * Three name-spaces are merged into a single context map for the
 * expression evaluator (so a rule can write `Slow_queries / Questions`
 * without worrying which bucket either name lives in):
 *
 *  - `status`    — `SHOW GLOBAL STATUS`     (or pg_stat_* aggregates)
 *  - `variables` — `SHOW GLOBAL VARIABLES`  (or pg_settings)
 *  - `derived`   — pre-computed metrics rules need (uptime hours, …)
 *
 * On a name collision, `derived` wins, then `variables`, then `status`.
 * In practice no real status / variable name overlaps so the precedence
 * rule is mainly defensive.
 */
final class Snapshot {
	public const FLAVOUR_MYSQL = 'mysql';
	public const FLAVOUR_MARIADB = 'mariadb';
	public const FLAVOUR_PGSQL = 'pgsql';
	public const FLAVOUR_UNSUPPORTED = 'unsupported';

	/**
	 * @param array<string, scalar|null> $status
	 * @param array<string, scalar|null> $variables
	 * @param array<string, scalar|null> $derived
	 * @param array<string, list<string>> $details Non-scalar facts that
	 *                                             can't live in the expression context (which is numbers-only):
	 *                                             lists of names backing a numeric metric, keyed by the same
	 *                                             name as that metric (e.g. the table names behind the
	 *                                             `tables_without_index_use` count).  Rules opt in via
	 *                                             {@see \OCA\ServerInfo\Database\Rule::$detailsKey}.
	 */
	public function __construct(
		public readonly string $flavour,
		public readonly string $version,
		public readonly array $status,
		public readonly array $variables,
		public readonly array $derived,
		public readonly array $details = [],
	) {
	}

	/**
	 * @return array<string, scalar|null>
	 */
	public function context(): array {
		return $this->derived + $this->variables + $this->status;
	}

	public function has(string $key): bool {
		return array_key_exists($key, $this->status)
			|| array_key_exists($key, $this->variables)
			|| array_key_exists($key, $this->derived);
	}

	public function get(string $key): mixed {
		if (array_key_exists($key, $this->derived)) {
			return $this->derived[$key];
		}
		if (array_key_exists($key, $this->variables)) {
			return $this->variables[$key];
		}
		return $this->status[$key] ?? null;
	}

	public function isSupported(): bool {
		return $this->flavour !== self::FLAVOUR_UNSUPPORTED;
	}
}
