<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCP\DB\Events\AddMissingColumnsEvent;
use OCP\DB\Events\AddMissingIndicesEvent;
use OCP\DB\Events\AddMissingPrimaryKeyEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Computes Nextcloud-specific database facts that the generic
 * phpMyAdmin-derived rules can't express: whether the schema is missing
 * indices / columns / primary keys that Nextcloud's own migrations
 * expect, and (on MySQL/MariaDB) whether every table uses the utf8mb4
 * charset and the InnoDB engine, and whether the transaction isolation
 * level is READ-COMMITTED.
 *
 * The missing-schema detection deliberately reuses the exact mechanism
 * behind `occ db:add-missing-indices` / `-columns` / `-primary-keys`:
 * dispatch the same events every app listens to, then compare the
 * declared expectations against the live schema via {@see SchemaWrapper}.
 * That keeps the counts in lockstep with what those occ commands would
 * actually change — the fix DB Doctor recommends.
 *
 * All facts are read from Nextcloud's own (default) connection — these
 * are questions about *the Nextcloud database*, so a configured override
 * connection (which the advisor otherwise honours) is intentionally not
 * used here.  Everything is wrapped so a failure degrades to "fact
 * absent" (the rule then skips) rather than breaking a check run.
 *
 * The resulting keys are merged into the snapshot's derived context by
 * {@see DatabaseProbe::snapshot()}, so rules reference them like any
 * other status/variable identifier.
 */
final class NextcloudSchema {
	public function __construct(
		private Connection $connection,
		private IEventDispatcher $dispatcher,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array<string, scalar> Numeric derived facts (nc_* keys) for the given flavour.
	 *                               A key is omitted when it couldn't be determined, so the
	 *                               dependent rule skips instead of evaluating on a bad value.
	 */
	public function facts(string $flavour): array {
		$out = $this->missingSchemaCounts();

		if ($flavour === Snapshot::FLAVOUR_MYSQL || $flavour === Snapshot::FLAVOUR_MARIADB) {
			$out += $this->mysqlSchemaFacts();
		}

		return $out;
	}

	/**
	 * Counts of schema objects Nextcloud expects but the live database
	 * lacks — the same comparison `occ db:add-missing-*` performs.
	 *
	 * @return array<string, int>
	 */
	private function missingSchemaCounts(): array {
		try {
			$schema = new SchemaWrapper($this->connection);
			return [
				'nc_missing_indices' => $this->countMissingIndices($schema),
				'nc_missing_columns' => $this->countMissingColumns($schema),
				'nc_missing_primary_keys' => $this->countMissingPrimaryKeys($schema),
			];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'serverinfo: Nextcloud schema probe failed: {msg}',
				['msg' => $e->getMessage(), 'app' => 'serverinfo'],
			);
			return [];
		}
	}

	private function countMissingIndices(SchemaWrapper $schema): int {
		$event = new AddMissingIndicesEvent();
		$this->dispatcher->dispatchTyped($event);

		$count = 0;
		foreach ($event->getMissingIndices() as $missing) {
			if ($schema->hasTable($missing['tableName'])
				&& !$schema->getTable($missing['tableName'])->hasIndex($missing['indexName'])) {
				$count++;
			}
		}
		foreach ($event->getIndicesToReplace() as $replace) {
			if ($schema->hasTable($replace['tableName'])
				&& !$schema->getTable($replace['tableName'])->hasIndex($replace['newIndexName'])) {
				$count++;
			}
		}
		return $count;
	}

	private function countMissingColumns(SchemaWrapper $schema): int {
		$event = new AddMissingColumnsEvent();
		$this->dispatcher->dispatchTyped($event);

		$count = 0;
		foreach ($event->getMissingColumns() as $missing) {
			if ($schema->hasTable($missing['tableName'])
				&& !$schema->getTable($missing['tableName'])->hasColumn($missing['columnName'])) {
				$count++;
			}
		}
		return $count;
	}

	private function countMissingPrimaryKeys(SchemaWrapper $schema): int {
		$event = new AddMissingPrimaryKeyEvent();
		$this->dispatcher->dispatchTyped($event);

		$count = 0;
		foreach ($event->getMissingPrimaryKeys() as $missing) {
			if ($schema->hasTable($missing['tableName'])
				&& $schema->getTable($missing['tableName'])->getPrimaryKey() === null) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * MySQL/MariaDB-only facts about the Nextcloud tables: charset,
	 * storage engine, and transaction isolation level.  Scoped to the
	 * configured table prefix so unrelated tables sharing the schema
	 * don't skew the counts.
	 *
	 * @return array<string, int>
	 */
	private function mysqlSchemaFacts(): array {
		try {
			$prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
			// Escape LIKE wildcards in the prefix ('oc_' → 'oc\_') so the
			// underscore matches literally rather than as a single-char
			// wildcard.
			$like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%';

			$nonUtf8mb4 = (int)$this->scalar(
				"SELECT COUNT(*) FROM information_schema.tables
				 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
				   AND table_name LIKE ?
				   AND table_collation IS NOT NULL AND table_collation NOT LIKE 'utf8mb4%'",
				[$like],
			);

			$nonInnodb = (int)$this->scalar(
				"SELECT COUNT(*) FROM information_schema.tables
				 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
				   AND table_name LIKE ?
				   AND engine IS NOT NULL AND engine <> 'InnoDB'",
				[$like],
			);

			return [
				'nc_non_utf8mb4_tables' => $nonUtf8mb4,
				'nc_non_innodb_tables' => $nonInnodb,
				'nc_read_committed' => $this->isReadCommitted() ? 1 : 0,
			];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'serverinfo: Nextcloud charset/engine probe failed: {msg}',
				['msg' => $e->getMessage(), 'app' => 'serverinfo'],
			);
			return [];
		}
	}

	/**
	 * Reads the server-wide (global) transaction isolation level — the
	 * value the my.cnf recommendation targets.  We deliberately read the
	 * GLOBAL rather than the SESSION value: Nextcloud forces READ-COMMITTED
	 * per session on its own connection, so `SHOW VARIABLES` (session) would
	 * always report READ-COMMITTED and hide the server's real default.
	 *
	 * The variable is `transaction_isolation` on MySQL 5.7.20+ / MariaDB
	 * 11.1+ and `tx_isolation` on older servers; we accept either.
	 */
	private function isReadCommitted(): bool {
		// SHOW GLOBAL VARIABLES returns (Variable_name, Value) rows, which
		// fetchAllKeyValue() folds into a name → value map.
		$rows = $this->connection->fetchAllKeyValue(
			"SHOW GLOBAL VARIABLES WHERE Variable_name IN ('transaction_isolation', 'tx_isolation')",
		);
		$value = (string)($rows['transaction_isolation'] ?? $rows['tx_isolation'] ?? '');

		// Servers report "READ-COMMITTED"; normalise spacing defensively.
		return strtoupper(str_replace(' ', '-', trim($value))) === 'READ-COMMITTED';
	}

	/**
	 * @param list<mixed> $params
	 */
	private function scalar(string $sql, array $params): mixed {
		return $this->connection->fetchOne($sql, $params);
	}
}
