<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database\RuleSet;

use OCA\ServerInfo\Database\ApplyDescriptor;
use OCA\ServerInfo\Database\Rule;
use OCA\ServerInfo\Database\RuleSet;
use OCA\ServerInfo\Database\Snapshot;

/**
 * Curated PostgreSQL rule set (v1).  Six high-value checks chosen
 * for the Nextcloud workload — original to Nextcloud's DB Doctor app,
 * from which they were ported, not ports of phpMyAdmin rules.
 *
 * The metrics referenced here are computed in
 * {@see \OCA\ServerInfo\Database\DatabaseProbe::snapshotPostgres()}; if
 * a metric is added there, the corresponding rule(s) get to use it
 * automatically through the snapshot's context map.
 */
final class Postgres implements RuleSet {
	use NextcloudSchemaRules;

	/** @var list<Rule>|null */
	private ?array $cache = null;

	public function rules(): array {
		if ($this->cache === null) {
			$this->cache = $this->build();
		}
		return $this->cache;
	}

	public function flavour(): string {
		return Snapshot::FLAVOUR_PGSQL;
	}

	/**
	 * @return list<Rule>
	 */
	private function build(): array {
		$rules = [];

		$rules[] = new Rule(
			id: 'pg.cache_hit_ratio',
			name: 'Buffer cache hit ratio',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: 'cache_hit_ratio * 100',
			test: 'value < 99',
			issue: 'A significant share of page reads are missing the shared buffer cache.',
			recommendation: 'Increase shared_buffers (a common starting point is 25 % of system RAM).',
			justification: 'Cache hit ratio is %s%%; aim for 99%% or higher.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'shared_buffers',
				recommendedValue: static fn (Snapshot $s) => '256MB',
				runtimeWritable: false,
				configKey: 'shared_buffers',
				configFile: 'postgresql.conf',
				note: 'Server restart required for shared_buffers to take effect.',
			),
			requires: ['cache_hit_ratio'],
		);

		$rules[] = new Rule(
			id: 'pg.dead_tuples',
			name: 'Dead tuples in user tables',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: 'max_dead_tuples',
			test: 'value > 100000',
			issue: 'At least one user table has accumulated many dead tuples — autovacuum may not be keeping up.',
			recommendation: 'Tune autovacuum_naptime / autovacuum_vacuum_scale_factor, or run VACUUM ANALYZE on the worst tables.',
			justification: 'Largest table has %s dead tuples.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'autovacuum_naptime',
				recommendedValue: static fn (Snapshot $s) => '30',
				runtimeWritable: true,
				configKey: 'autovacuum_naptime',
				configFile: 'postgresql.conf',
				note: 'Postgres reload required (handled automatically). Value in seconds.',
			),
			requires: ['max_dead_tuples'],
		);

		$rules[] = new Rule(
			id: 'pg.tables_without_index_use',
			name: 'Tables scanning sequentially',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'tables_without_index_use',
			test: 'value > 0',
			issue: 'Some tables have many sequential scans and no index scans, suggesting a missing index.',
			recommendation: 'Add appropriate indexes to the affected tables (pg_stat_user_tables shows the full scan statistics).',
			justification: '%s table(s) have > 1M sequential scans without any index scans.',
			justificationFormula: 'value',
			requires: ['tables_without_index_use'],
			// The probe records the offending table names next to the
			// count; showing them saves the admin the pg_stat_user_tables
			// round-trip the recommendation would otherwise require.
			detailsKey: 'tables_without_index_use',
		);

		$rules[] = new Rule(
			id: 'pg.replication_lag',
			name: 'Replication lag',
			category: 'Replication',
			severity: Rule::SEVERITY_ALERT,
			formula: 'max_replay_lag_seconds',
			test: 'value > 30',
			issue: 'A standby is more than 30 seconds behind the primary.',
			recommendation: 'Check pg_stat_replication; investigate WAL apply bottlenecks on the standby.',
			justification: 'Worst replica is %s seconds behind.',
			justificationFormula: 'value',
			requires: ['max_replay_lag_seconds'],
		);

		$rules[] = new Rule(
			id: 'pg.connection_saturation',
			name: 'Connection saturation',
			category: 'Connections',
			severity: Rule::SEVERITY_ALERT,
			formula: 'active_connections / max_connections * 100',
			test: 'value > 80',
			issue: 'More than 80 % of max_connections are in use.',
			recommendation: 'Raise max_connections, deploy a connection pooler (e.g. PgBouncer), or shorten idle timeouts.',
			justification: '%s%% of max_connections in use.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'max_connections',
				recommendedValue: static fn (Snapshot $s) => (string)max(
					200,
					(int)((float)($s->variables['max_connections'] ?? 100) * 1.5),
				),
				runtimeWritable: false,
				configKey: 'max_connections',
				configFile: 'postgresql.conf',
				note: 'max_connections requires a server restart to take effect.',
			),
			requires: ['active_connections', 'max_connections'],
		);

		$rules[] = new Rule(
			id: 'pg.long_running_queries',
			name: 'Long-running queries',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: 'long_running_queries',
			test: 'value > 0',
			issue: 'One or more queries have been running for more than 5 minutes.',
			recommendation: 'Inspect pg_stat_activity for the offending queries; consider statement_timeout.',
			justification: '%s query/queries running longer than 5 minutes.',
			justificationFormula: 'value',
			requires: ['long_running_queries'],
		);

		// Nextcloud schema-integrity checks (missing indices / columns /
		// primary keys) apply to Postgres as much as MySQL.
		foreach ($this->nextcloudSchemaRules() as $rule) {
			$rules[] = $rule;
		}

		return $rules;
	}
}
