<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

use OC\DB\Connection;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Computes Nextcloud-specific database facts that the generic
 * phpMyAdmin-derived rules can't express: on MySQL and MariaDB, whether
 * every table uses the utf8mb4 charset and the InnoDB engine.
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
		if ($flavour === Snapshot::FLAVOUR_MYSQL || $flavour === Snapshot::FLAVOUR_MARIADB) {
			return $this->mysqlSchemaFacts();
		}

		return [];
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
	 * @param list<mixed> $params
	 */
	private function scalar(string $sql, array $params): mixed {
		return $this->connection->fetchOne($sql, $params);
	}
}
