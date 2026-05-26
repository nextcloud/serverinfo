<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IConfig;
use OCP\IDBConnection;

class DbHealth {
	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	/**
	 * @return array{
	 *     driver: string,
	 *     largestTables: list<array{name: string, rows: int, sizeBytes: int}>,
	 *     available: bool
	 * }
	 */
	public function getDbHealth(): array {
		$driver = (string)$this->config->getSystemValue('dbtype', 'sqlite');

		try {
			$tables = match ($driver) {
				'mysql', 'mariadb' => $this->mysqlTables(),
				'pgsql' => $this->pgTables(),
				default => [],
			};
		} catch (\Throwable) {
			$tables = [];
		}

		return [
			'driver' => $driver,
			'largestTables' => $tables,
			'available' => $tables !== [] || $driver === 'sqlite',
		];
	}

	/**
	 * @return list<array{name: string, rows: int, sizeBytes: int}>
	 */
	private function mysqlTables(int $limit = 8): array {
		$dbName = (string)$this->config->getSystemValue('dbname', '');
		if ($dbName === '') {
			return [];
		}
		$sql = 'SELECT table_name AS name, table_rows AS rows, '
			. '(data_length + index_length) AS size_bytes '
			. 'FROM information_schema.TABLES WHERE table_schema = ? '
			. 'ORDER BY size_bytes DESC LIMIT ' . (int)$limit;
		$conn = $this->db;
		$stmt = $conn->prepare($sql);
		$stmt->bindValue(1, $dbName);
		$result = $stmt->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = [
				'name' => (string)($row['name'] ?? ''),
				'rows' => (int)($row['rows'] ?? 0),
				'sizeBytes' => (int)($row['size_bytes'] ?? 0),
			];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * @return list<array{name: string, rows: int, sizeBytes: int}>
	 */
	private function pgTables(int $limit = 8): array {
		$sql = 'SELECT relname AS name, n_live_tup AS rows, '
			. 'pg_total_relation_size(C.oid) AS size_bytes '
			. 'FROM pg_class C '
			. 'LEFT JOIN pg_namespace N ON N.oid = C.relnamespace '
			. "WHERE relkind = 'r' AND nspname NOT IN ('pg_catalog', 'information_schema') "
			. 'ORDER BY size_bytes DESC LIMIT ' . (int)$limit;
		$result = $this->db->prepare($sql)->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = [
				'name' => (string)($row['name'] ?? ''),
				'rows' => (int)($row['rows'] ?? 0),
				'sizeBytes' => (int)($row['size_bytes'] ?? 0),
			];
		}
		$result->closeCursor();
		return $out;
	}
}
