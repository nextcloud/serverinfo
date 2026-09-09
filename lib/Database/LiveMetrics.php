<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

use Doctrine\DBAL\Connection;
use OCP\IDBConnection;

/**
 * Read-only queries behind the live boxes on the database page: connection
 * saturation, cache hit ratio and throughput. Nothing here mutates anything.
 *
 * Ported from Nextcloud's DB Doctor app. Every query goes through
 * DatabaseProbe::withConnection(), so a configured override connection is
 * honoured exactly as it is for the checks.
 */
final class LiveMetrics {
	public function __construct(
		private DatabaseProbe $probe,
	) {
	}

	/**
	 * A cheap snapshot of the headline live metrics.  Returns cumulative
	 * counters (throughput) that the client turns into a per-second rate.
	 *
	 * @return array{
	 *   connections: array{used:int, max:int},
	 *   cacheHitRatio: float|null,
	 *   throughput: array{counter: float, label: string},
	 *   threadsRunning: int,
	 * }
	 */
	public function collect(): array {
		return $this->probe->withConnection(function (Connection|IDBConnection $conn, string $flavour): array {
			if ($flavour === Snapshot::FLAVOUR_PGSQL) {
				return $this->liveMetricsPostgres($conn);
			}
			return $this->liveMetricsMysql($conn);
		});
	}

	private function liveMetricsMysql(Connection|IDBConnection $conn): array {
		$status = $this->statusMap($conn,
			'SHOW GLOBAL STATUS WHERE Variable_name IN '
			. "('Threads_connected','Threads_running','Innodb_buffer_pool_read_requests','Innodb_buffer_pool_reads','Questions')");
		$maxConn = $this->variableInt($conn, 'max_connections');

		$readReq = (float)($status['Innodb_buffer_pool_read_requests'] ?? 0);
		$reads = (float)($status['Innodb_buffer_pool_reads'] ?? 0);
		$hit = $readReq > 0 ? 1.0 - ($reads / $readReq) : null;

		return [
			'connections' => [
				'used' => (int)($status['Threads_connected'] ?? 0),
				'max' => $maxConn,
			],
			'cacheHitRatio' => $hit,
			'throughput' => [
				'counter' => (float)($status['Questions'] ?? 0),
				'label' => 'Queries/s',
			],
			'threadsRunning' => (int)($status['Threads_running'] ?? 0),
		];
	}

	private function liveMetricsPostgres(Connection|IDBConnection $conn): array {
		$used = (int)$this->probe->scalar($conn, 'SELECT count(*) FROM pg_stat_activity');
		$max = (int)$this->probe->scalar($conn, "SELECT setting FROM pg_settings WHERE name = 'max_connections'");
		$active = (int)$this->probe->scalar($conn, "SELECT count(*) FROM pg_stat_activity WHERE state = 'active'");

		$row = $this->probe->row($conn, "
			SELECT COALESCE(SUM(blks_hit), 0) AS hit,
			       COALESCE(SUM(blks_read), 0) AS rd,
			       COALESCE(SUM(xact_commit + xact_rollback), 0) AS xacts
			FROM pg_stat_database WHERE datname NOT LIKE 'template%'");
		$hitB = (float)($row['hit'] ?? 0);
		$readB = (float)($row['rd'] ?? 0);
		$total = $hitB + $readB;

		return [
			'connections' => ['used' => $used, 'max' => $max],
			'cacheHitRatio' => $total > 0 ? $hitB / $total : null,
			'throughput' => [
				'counter' => (float)($row['xacts'] ?? 0),
				'label' => 'Transactions/s',
			],
			'threadsRunning' => $active,
		];
	}

	// ── helpers ─────────────────────────────────────────────────────

	/**
	 * MySQL 8 returns unaliased information_schema column headers in
	 * UPPERCASE (they are data-dictionary views), so normalise keys to
	 * lowercase before any field access.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function all(Connection|IDBConnection $conn, string $sql): array {
		return array_map(
			static fn (array $row): array => array_change_key_case($row, CASE_LOWER),
			$this->probe->all($conn, $sql),
		);
	}

	/**
	 * Turn a SHOW ... STATUS/VARIABLES result into a Variable_name→Value map.
	 *
	 * @return array<string, string>
	 */
	private function statusMap(Connection|IDBConnection $conn, string $sql): array {
		$map = [];
		foreach ($this->all($conn, $sql) as $row) {
			$vals = array_values($row);
			if (isset($vals[0], $vals[1])) {
				$map[(string)$vals[0]] = (string)$vals[1];
			}
		}
		return $map;
	}

	private function variableInt(Connection|IDBConnection $conn, string $name): int {
		$map = $this->statusMap($conn, "SHOW VARIABLES WHERE Variable_name = '" . $name . "'");
		return (int)($map[$name] ?? 0);
	}
}
