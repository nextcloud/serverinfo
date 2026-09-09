<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use OCA\ServerInfo\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

/**
 * Detects database flavour + version and assembles a {@see Snapshot}.
 *
 * Connection precedence:
 *   1. Override credentials configured in the app (encrypted via
 *      ICredentialsManager) — used when set, gives admins the option
 *      to point the database checks at a higher-privilege user without changing
 *      Nextcloud's main connection.
 *   2. Nextcloud's IDBConnection — the default.  Works for almost
 *      every check; rules that need PROCESS / REPLICATION CLIENT will
 *      gracefully skip when the privilege isn't there.
 *
 * The probe never mutates Nextcloud's main connection.  Override
 * connections are opened on demand and disposed at the end of each
 * snapshot.
 */
class DatabaseProbe {
	/**
	 * Cap for name lists attached to a snapshot as details (e.g. the
	 * tables behind the seq-scan count).  The numeric metric always
	 * carries the full count; the list is illustrative, and results are
	 * persisted per run so unbounded lists would bloat the history rows.
	 */
	private const MAX_DETAIL_ITEMS = 8;

	public function __construct(
		private IDBConnection $defaultConnection,
		private IConfig $config,
		private ICredentialsManager $credentials,
		private NextcloudSchema $nextcloudSchema,
		private LoggerInterface $logger,
	) {
	}

	public function detectFlavour(): string {
		try {
			$conn = $this->openConnection();
			$flavour = $this->flavourOf($conn);
			$this->closeIfOverride($conn);
			return $flavour;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'serverinfo: flavour detection failed: {msg}',
				['msg' => $e->getMessage(), 'app' => 'serverinfo'],
			);
			return Snapshot::FLAVOUR_UNSUPPORTED;
		}
	}

	public function detectVersion(string $flavour): string {
		if ($flavour === Snapshot::FLAVOUR_UNSUPPORTED) {
			return '';
		}
		try {
			$conn = $this->openConnection();
			$version = match ($flavour) {
				Snapshot::FLAVOUR_PGSQL => (string)$this->scalar($conn, 'SHOW server_version'),
				default => (string)$this->scalar($conn, 'SELECT VERSION()'),
			};
			$this->closeIfOverride($conn);
			return $version;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'serverinfo: version detection failed for {flavour}: {msg}',
				['flavour' => $flavour, 'msg' => $e->getMessage(), 'app' => 'serverinfo'],
			);
			return '';
		}
	}

	/**
	 * Run a cheap, read-only query so the caller can time the round-trip.
	 *
	 * Used by the live latency chart on the dashboard.  We deliberately
	 * use the same query the user requested ("SHOW TABLES") on
	 * MySQL/MariaDB and the closest catalog-read equivalent on Postgres
	 * — it exercises parsing, planner lookup, and result transit so the
	 * timing is meaningful rather than a pure round-trip ping.
	 *
	 * Throws on failure; the caller decides how to surface that.
	 */
	public function ping(): void {
		$conn = $this->openConnection();
		try {
			$flavour = $this->flavourOf($conn);
			$sql = $flavour === Snapshot::FLAVOUR_PGSQL
				? "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')"
				: 'SHOW TABLES';
			// Materialise the rowset so the timing reflects the full
			// query, not just sending the SQL.
			$this->all($conn, $sql);
		} finally {
			$this->closeIfOverride($conn);
		}
	}

	/**
	 * Total on-disk size of the probed database in bytes, or null when
	 * it cannot be determined.  Same approach as the serverinfo app
	 * (phpBB's get_database_size): sum of table data + index sizes on
	 * MySQL/MariaDB, pg_database_size() on PostgreSQL — but routed
	 * through our own connection so a configured override is honoured.
	 */
	public function databaseSizeBytes(): ?int {
		try {
			$conn = $this->openConnection();
			try {
				$flavour = $this->flavourOf($conn);
				$size = match ($flavour) {
					Snapshot::FLAVOUR_PGSQL => $this->scalar(
						$conn,
						'SELECT pg_database_size(current_database())',
					),
					Snapshot::FLAVOUR_MYSQL, Snapshot::FLAVOUR_MARIADB => $this->scalar(
						$conn,
						'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()',
					),
					default => null,
				};
				return is_numeric($size) ? (int)$size : null;
			} finally {
				$this->closeIfOverride($conn);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'serverinfo: database size probe failed: {msg}',
				['msg' => $e->getMessage(), 'app' => 'serverinfo'],
			);
			return null;
		}
	}

	public function snapshot(): Snapshot {
		$conn = $this->openConnection();
		try {
			$flavour = $this->flavourOf($conn);
			if ($flavour === Snapshot::FLAVOUR_UNSUPPORTED) {
				return new Snapshot($flavour, '', [], [], []);
			}

			$base = $flavour === Snapshot::FLAVOUR_PGSQL
				? $this->snapshotPostgres($conn)
				: $this->snapshotMysql($conn, $flavour);

			// Merge Nextcloud-specific schema facts (missing indices /
			// columns / primary keys, and MySQL charset/engine/isolation)
			// into the derived context so the Nextcloud rule group can
			// reference them.  These are read from Nextcloud's own
			// connection, independent of any advisor override.
			$ncFacts = $this->nextcloudSchema->facts($flavour);
			if ($ncFacts === []) {
				return $base;
			}
			return new Snapshot(
				$base->flavour,
				$base->version,
				$base->status,
				$base->variables,
				$base->derived + $ncFacts,
				$base->details,
			);
		} finally {
			$this->closeIfOverride($conn);
		}
	}

	// ── MySQL / MariaDB ─────────────────────────────────────────────

	private function snapshotMysql(Connection|IDBConnection $conn, string $flavour): Snapshot {
		$version = (string)$this->scalar($conn, 'SELECT VERSION()');

		$status = $this->keyValuePairs($conn, 'SHOW GLOBAL STATUS');
		$variables = $this->keyValuePairs($conn, 'SHOW GLOBAL VARIABLES');

		// Derived metrics that several rules reference.
		$derived = [
			'value' => 0.0, // populated per-rule by Advisor
			'Uptime_hours' => isset($status['Uptime']) ? (float)$status['Uptime'] / 3600.0 : 0.0,
			'Uptime_days' => isset($status['Uptime']) ? (float)$status['Uptime'] / 86400.0 : 0.0,
		];

		// `Questions` was renamed to `Questions` (unchanged) but
		// `Com_*` totals are useful for rules that need to compute
		// "ratio per query" without touching the slow cousin
		// `Queries`.  Pre-compute total questions so rules don't
		// need to.
		if (isset($status['Questions'], $status['Slow_queries'])) {
			$q = (float)$status['Questions'];
			$slow = (float)$status['Slow_queries'];
			$derived['slow_query_ratio'] = $q > 0 ? $slow / $q : 0.0;
		}

		return new Snapshot($flavour, $version, $status, $variables, $derived);
	}

	// ── PostgreSQL ──────────────────────────────────────────────────

	private function snapshotPostgres(Connection|IDBConnection $conn): Snapshot {
		$version = (string)$this->scalar($conn, 'SHOW server_version');

		// "Status"-equivalent: aggregate stats from pg_stat_database.
		// We sum across non-template databases so a single number
		// represents the whole server (matching the MySQL global
		// status convention).
		$status = [];
		$row = $this->row($conn, "
			SELECT
				COALESCE(SUM(blks_read), 0)   AS blks_read,
				COALESCE(SUM(blks_hit), 0)    AS blks_hit,
				COALESCE(SUM(xact_commit), 0) AS xact_commit,
				COALESCE(SUM(xact_rollback), 0) AS xact_rollback,
				COALESCE(SUM(temp_files), 0)  AS temp_files,
				COALESCE(SUM(temp_bytes), 0)  AS temp_bytes,
				COALESCE(SUM(deadlocks), 0)   AS deadlocks
			FROM pg_stat_database
			WHERE datname NOT LIKE 'template%'
		");
		foreach ($row ?? [] as $k => $v) {
			$status[$k] = $v;
		}

		// Active connection count.
		$status['active_connections'] = (int)$this->scalar(
			$conn,
			'SELECT count(*) FROM pg_stat_activity WHERE state IS NOT NULL',
		);

		// Long-running queries (>5 min).
		$status['long_running_queries'] = (int)$this->scalar(
			$conn,
			"SELECT count(*) FROM pg_stat_activity
			 WHERE state = 'active' AND now() - query_start > interval '5 minutes'",
		);

		// Worst replication lag in seconds (0 if no replicas).
		$status['max_replay_lag_seconds'] = (float)($this->scalar(
			$conn,
			'SELECT COALESCE(MAX(EXTRACT(EPOCH FROM replay_lag)), 0) FROM pg_stat_replication',
		) ?? 0);

		// Worst dead-tuple count across user tables.
		$status['max_dead_tuples'] = (int)$this->scalar(
			$conn,
			'SELECT COALESCE(MAX(n_dead_tup), 0) FROM pg_stat_user_tables',
		);

		// "Have any user tables ever benefitted from an index?"  We
		// flag tables with > 1M sequential scans and zero index scans,
		// and keep the worst offenders' names so the rule card can show
		// which tables to look at instead of just a count.
		$seqScanTables = $this->all(
			$conn,
			'SELECT relname FROM pg_stat_user_tables WHERE seq_scan > 1000000 AND idx_scan = 0 ORDER BY seq_scan DESC',
		);
		$status['tables_without_index_use'] = count($seqScanTables);
		$details = [];
		if ($seqScanTables !== []) {
			$names = array_map(static fn (array $row): string => (string)$row['relname'], $seqScanTables);
			$details['tables_without_index_use'] = count($names) > self::MAX_DETAIL_ITEMS
				? array_slice($names, 0, self::MAX_DETAIL_ITEMS)
				: $names;
		}

		// "Variables"-equivalent: pg_settings.
		$variables = [];
		foreach ($this->all($conn, 'SELECT name, setting FROM pg_settings') as $row) {
			$variables[$row['name']] = $row['setting'];
		}

		$blksHit = (float)($status['blks_hit'] ?? 0);
		$blksRead = (float)($status['blks_read'] ?? 0);
		$total = $blksHit + $blksRead;
		$derived = [
			'value' => 0.0,
			'cache_hit_ratio' => $total > 0 ? $blksHit / $total : 1.0,
		];

		return new Snapshot(Snapshot::FLAVOUR_PGSQL, $version, $status, $variables, $derived, $details);
	}

	// ── Connection plumbing ─────────────────────────────────────────

	private function openConnection(): Connection|IDBConnection {
		$override = $this->overrideParams();
		if ($override === null) {
			return $this->defaultConnection;
		}
		return DriverManager::getConnection($override);
	}

	private function closeIfOverride(Connection|IDBConnection $conn): void {
		// Doctrine\DBAL\Connection isn't an IDBConnection.  When we
		// opened a separate one for an override, dispose it.
		if ($conn instanceof Connection) {
			$conn->close();
		}
	}

	/**
	 * Whether connecting to $host is permitted by the operator.
	 *
	 * Admins configure overrides through the web UI, which makes the
	 * connection feature an internal-network probe primitive for a
	 * web-only admin.  Operators can pin the reachable hosts with a
	 * config.php entry:
	 *
	 *     'serverinfo.allowed_override_hosts' => ['db1.internal', '10.0.0.5'],
	 *
	 * When the entry is absent or empty every host is allowed (the
	 * historical behaviour); when present, only exact matches pass.
	 */
	public function isHostAllowed(string $host): bool {
		$allowed = $this->config->getSystemValue('serverinfo.allowed_override_hosts', []);
		if (!is_array($allowed) || $allowed === []) {
			return true;
		}
		return in_array($host, array_map('strval', $allowed), true);
	}

	/**
	 * @return array<string, mixed>|null Doctrine DBAL connection params or null when no override is configured.
	 */
	private function overrideParams(): ?array {
		$host = $this->config->getAppValue(Application::APP_ID, 'override.host', '');
		if ($host === '') {
			return null;
		}
		if (!$this->isHostAllowed($host)) {
			// A host that was stored before the operator tightened the
			// allow-list (or slipped past it) must not be dialled.
			$this->logger->warning(
				'serverinfo: override host {host} is not on serverinfo.allowed_override_hosts — falling back to the default connection',
				['host' => $host, 'app' => 'serverinfo'],
			);
			return null;
		}
		$port = (int)$this->config->getAppValue(Application::APP_ID, 'override.port', '0');
		$user = $this->config->getAppValue(Application::APP_ID, 'override.user', '');
		$dbname = $this->config->getAppValue(Application::APP_ID, 'override.database', '');
		$driver = $this->config->getAppValue(Application::APP_ID, 'override.driver', 'pdo_mysql');
		$password = (string)$this->credentials->retrieve('', Application::APP_ID . ':override.password');

		$params = [
			'driver' => $driver,
			'host' => $host,
			'user' => $user,
			'password' => $password,
			'dbname' => $dbname,
		];
		if ($port > 0) {
			$params['port'] = $port;
		}
		return $params;
	}

	/**
	 * Run $callback with an open (override-aware) connection and its
	 * detected flavour, disposing an override connection afterwards.
	 * Lets sibling services (e.g. InsightsService) reuse the exact same
	 * connection precedence and query helpers without re-implementing
	 * the plumbing.
	 *
	 * @template T
	 * @param callable(Connection|IDBConnection, string): T $callback
	 * @return T
	 */
	public function withConnection(callable $callback): mixed {
		$conn = $this->openConnection();
		try {
			$flavour = $this->flavourOf($conn);
			return $callback($conn, $flavour);
		} finally {
			$this->closeIfOverride($conn);
		}
	}

	private function flavourOf(Connection|IDBConnection $conn): string {
		try {
			// PostgreSQL has SHOW server_version; SHOW VERSION() is MySQL.
			// We probe the cheap MySQL form first; if it errors, it's
			// likely Postgres or unsupported.
			$version = (string)$this->scalar($conn, 'SELECT VERSION()');
			if ($version === '') {
				return Snapshot::FLAVOUR_UNSUPPORTED;
			}
			if (str_contains(strtolower($version), 'mariadb')) {
				return Snapshot::FLAVOUR_MARIADB;
			}
			if (str_contains(strtolower($version), 'postgresql')) {
				return Snapshot::FLAVOUR_PGSQL;
			}
			return Snapshot::FLAVOUR_MYSQL;
		} catch (\Throwable) {
			// Postgres returns a quoted version string from
			// SELECT version() — different syntax wouldn't normally
			// throw, but if our generic call fails for any reason
			// we fall through to a SHOW server_version probe.
			try {
				$pgver = (string)$this->scalar($conn, 'SHOW server_version');
				return $pgver !== '' ? Snapshot::FLAVOUR_PGSQL : Snapshot::FLAVOUR_UNSUPPORTED;
			} catch (\Throwable) {
				return Snapshot::FLAVOUR_UNSUPPORTED;
			}
		}
	}

	// ── Query helpers (work for both Doctrine\DBAL\Connection and IDBConnection) ──
	// Public so sibling services can query through withConnection().

	public function scalar(Connection|IDBConnection $conn, string $sql): mixed {
		$row = $this->row($conn, $sql);
		if ($row === null) {
			return null;
		}
		// First column, regardless of name.
		return reset($row);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function row(Connection|IDBConnection $conn, string $sql): ?array {
		if ($conn instanceof Connection) {
			$row = $conn->fetchAssociative($sql);
			return $row === false ? null : $row;
		}
		$result = $conn->prepare($sql)->execute();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $row;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function all(Connection|IDBConnection $conn, string $sql): array {
		if ($conn instanceof Connection) {
			return $conn->fetchAllAssociative($sql);
		}
		$result = $conn->prepare($sql)->execute();
		$rows = [];
		while (($r = $result->fetch()) !== false) {
			$rows[] = $r;
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * @return array<string, scalar|null>
	 */
	private function keyValuePairs(Connection|IDBConnection $conn, string $sql): array {
		$out = [];
		foreach ($this->all($conn, $sql) as $row) {
			$values = array_values($row);
			if (count($values) < 2) {
				continue;
			}
			$key = (string)$values[0];
			$val = $values[1];
			$out[$key] = is_scalar($val) || $val === null ? $val : (string)$val;
		}
		return $out;
	}
}
