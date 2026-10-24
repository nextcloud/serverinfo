<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

use Doctrine\DBAL\Connection;
use OCA\ServerInfo\AppInfo\Application;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Read-only queries behind the live boxes on the database page: round-trip
 * latency, connection saturation and throughput. Nothing here mutates
 * anything.
 *
 * Ported from Nextcloud's DB Doctor app. Every query goes through
 * DatabaseProbe::withConnection(), so a configured override connection is
 * honoured exactly as it is for the checks.
 */
final class LiveMetrics {
	public function __construct(
		private DatabaseProbe $probe,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A cheap snapshot of the live metrics, taken over one connection whose
	 * flavour is detected once. Returns cumulative counters (throughput) that
	 * the client turns into a per-second rate.
	 *
	 * The page polls this every few seconds, so it opens at most one override
	 * connection and keeps the number of queries to a handful.
	 *
	 * @return array{
	 *   available: bool,
	 *   latencyMs: float|null,
	 *   connections?: array{used:int, max:int},
	 *   throughput?: array{counter: float, label: string},
	 *   threadsRunning?: int,
	 * }
	 */
	public function collect(): array {
		return $this->probe->withConnection(function (Connection|IDBConnection $conn, string $flavour): array {
			if ($flavour === Snapshot::FLAVOUR_UNSUPPORTED) {
				return ['available' => false, 'latencyMs' => null];
			}

			$latencyMs = $this->latencyMs($conn);

			try {
				$metrics = $flavour === Snapshot::FLAVOUR_PGSQL
					? $this->liveMetricsPostgres($conn)
					: $this->liveMetricsMysql($conn);
			} catch (\Throwable $e) {
				$this->logger->debug('serverinfo: live database metrics failed: {msg}', [
					'msg' => $e->getMessage(), 'app' => Application::APP_ID,
				]);

				return ['available' => false, 'latencyMs' => $latencyMs];
			}

			return ['available' => true, 'latencyMs' => $latencyMs] + $metrics;
		});
	}

	/**
	 * Round trip of the cheapest query there is, on the connection that is
	 * already open, so it measures the database and the network to it rather
	 * than connection setup or catalogue size.
	 */
	private function latencyMs(Connection|IDBConnection $conn): ?float {
		$started = hrtime(true);
		try {
			$this->probe->scalar($conn, 'SELECT 1');
		} catch (\Throwable) {
			return null;
		}

		return round((hrtime(true) - $started) / 1_000_000, 2);
	}

	/**
	 * @return array{connections: array{used:int, max:int}, throughput: array{counter: float, label: string}, threadsRunning: int}
	 */
	private function liveMetricsMysql(Connection|IDBConnection $conn): array {
		$status = $this->statusMap($conn,
			'SHOW GLOBAL STATUS WHERE Variable_name IN '
			. "('Threads_connected','Threads_running','Questions')");
		$maxConn = $this->variableInt($conn, 'max_connections');

		return [
			'connections' => [
				'used' => (int)($status['Threads_connected'] ?? 0),
				'max' => $maxConn,
			],
			'throughput' => [
				'counter' => (float)($status['Questions'] ?? 0),
				'label' => 'Queries/s',
			],
			'threadsRunning' => (int)($status['Threads_running'] ?? 0),
		];
	}

	/**
	 * @return array{connections: array{used:int, max:int}, throughput: array{counter: float, label: string}, threadsRunning: int}
	 */
	private function liveMetricsPostgres(Connection|IDBConnection $conn): array {
		$activity = $this->probe->row($conn, "
			SELECT count(*) AS used,
			       count(*) FILTER (WHERE state = 'active') AS active,
			       current_setting('max_connections') AS max_connections
			FROM pg_stat_activity");
		$xacts = $this->probe->scalar($conn, "
			SELECT COALESCE(SUM(xact_commit + xact_rollback), 0)
			FROM pg_stat_database WHERE datname NOT LIKE 'template%'");

		return [
			'connections' => [
				'used' => (int)($activity['used'] ?? 0),
				'max' => (int)($activity['max_connections'] ?? 0),
			],
			'throughput' => [
				'counter' => (float)($xacts ?? 0),
				'label' => 'Transactions/s',
			],
			'threadsRunning' => (int)($activity['active'] ?? 0),
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
