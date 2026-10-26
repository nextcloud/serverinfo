<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * @psalm-api
 */
#[CreateTable(table: 'serverinfo_samples', description: 'Load, memory, disk and usage history, one row per node, resolution and time bucket')]
class Version8000Date20261024000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('serverinfo_samples')) {
			return null;
		}

		$table = $schema->createTable('serverinfo_samples');
		$table->addColumn('id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
		$table->addColumn('node_name', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('resolution', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('bucket_start', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
		$table->addColumn('samples', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
		foreach (['load1', 'load5', 'load15', 'load1_max'] as $column) {
			$table->addColumn($column, Types::FLOAT, ['notnull' => false]);
		}
		foreach (['mem_used', 'mem_used_max', 'mem_total', 'swap_used', 'swap_total', 'disk_free', 'disk_total', 'files', 'db_size'] as $column) {
			$table->addColumn($column, Types::BIGINT, ['notnull' => false, 'length' => 20]);
		}
		foreach (['users5min', 'users5min_max', 'users24h'] as $column) {
			$table->addColumn($column, Types::INTEGER, ['notnull' => false]);
		}
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['resolution', 'bucket_start', 'node_name'], 'serverinfo_samples_bucket');

		return $schema;
	}
}
