<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: phpMyAdmin contributors (advisor rule data, GPL-2.0-or-later)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * This file ports advisory rules from phpMyAdmin
 * (https://www.phpmyadmin.net/), originally released under
 * GPL-2.0-or-later.  GPL-2.0-or-later is upward-compatible with
 * AGPL-3.0 via the "or-later" clause; the combined work is
 * distributed under AGPL-3.0-or-later.
 *
 * Each rule below carries its phpMyAdmin upstream id so future
 * syncs can be tracked.  Severity assignments (alert / warning /
 * notice) come from Nextcloud's DB Doctor app, from which this rule
 * set was ported; phpMyAdmin doesn't carry them.
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database\RuleSet;

use OCA\ServerInfo\Database\ApplyDescriptor;
use OCA\ServerInfo\Database\Rule;
use OCA\ServerInfo\Database\RuleSet;
use OCA\ServerInfo\Database\Snapshot;

/**
 * MySQL / MariaDB rule set.  Loaded for both flavours — the Advisor
 * skips rules whose required status / variable keys aren't present
 * on the current server, so MariaDB-specific or version-specific
 * rules naturally stay out of the way.
 */
final class Mysql implements RuleSet {
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
		return Snapshot::FLAVOUR_MYSQL;
	}

	/**
	 * @return list<Rule>
	 */
	private function build(): array {
		$rules = [];

		// ── Logging ─────────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Slow_query_log_off',
			name: 'Slow query log',
			category: 'Logging',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'slow_query_log',
			test: 'value == 0',
			issue: 'The slow query log is disabled, so problematic queries are not being recorded.',
			recommendation: 'Enable slow_query_log to capture queries that take longer than long_query_time to execute.',
			justification: 'slow_query_log is currently disabled.',
			justificationFormula: '',
			apply: new ApplyDescriptor(
				variable: 'slow_query_log',
				recommendedValue: static fn (Snapshot $s) => 'ON',
				runtimeWritable: true,
				configKey: 'slow_query_log',
				configFile: 'my.cnf',
			),
			requires: ['slow_query_log'],
		);

		$rules[] = new Rule(
			id: 'Long_query_time',
			name: 'Long query time',
			category: 'Logging',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'long_query_time',
			test: 'value > 5',
			issue: 'long_query_time is set high, so only the slowest queries are logged.',
			recommendation: 'Set long_query_time to 2 (or less) to catch problematic queries earlier.',
			justification: 'long_query_time is %s seconds; a value of 2 or less is recommended.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'long_query_time',
				recommendedValue: static fn (Snapshot $s) => '2',
				runtimeWritable: true,
				configKey: 'long_query_time',
				configFile: 'my.cnf',
			),
			requires: ['long_query_time'],
		);

		// ── Connections ─────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Max_used_connections_close_to_max',
			name: 'Connection saturation',
			category: 'Connections',
			severity: Rule::SEVERITY_ALERT,
			formula: 'Max_used_connections / max_connections * 100',
			test: 'value > 80',
			issue: 'The server has approached its max_connections limit.',
			recommendation: 'Increase max_connections to give the server more headroom under load spikes.',
			justification: 'Peak connection usage was %s%% of the configured max_connections.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'max_connections',
				recommendedValue: static fn (Snapshot $s) => (string)max(
					200,
					(int)((float)($s->variables['max_connections'] ?? 100) * 1.5),
				),
				runtimeWritable: true,
				configKey: 'max_connections',
				configFile: 'my.cnf',
			),
			requires: ['Max_used_connections', 'max_connections'],
		);

		$rules[] = new Rule(
			id: 'Aborted_connects',
			name: 'Aborted connections',
			category: 'Connections',
			severity: Rule::SEVERITY_WARNING,
			formula: 'Aborted_connects / Connections * 100',
			test: 'value > 1',
			issue: 'A noticeable share of connection attempts are aborting before completing.',
			recommendation: 'Investigate clients connecting with bad credentials, network drops, or insufficient max_allowed_packet.',
			justification: '%s%% of connection attempts failed (Aborted_connects=%s, Connections=%s).',
			justificationFormula: 'value, Aborted_connects, Connections',
			requires: ['Aborted_connects', 'Connections'],
		);

		$rules[] = new Rule(
			id: 'Thread_cache_hit_rate',
			name: 'Thread cache hit rate',
			category: 'Connections',
			severity: Rule::SEVERITY_WARNING,
			formula: '100 - (Threads_created / Connections * 100)',
			test: 'value < 90',
			issue: 'Many incoming connections are creating new threads instead of reusing cached ones.',
			recommendation: 'Increase thread_cache_size so most connections are served from the thread cache.',
			justification: 'Thread cache hit rate is %s%%; aim for 90%% or higher.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'thread_cache_size',
				recommendedValue: static fn (Snapshot $s) => (string)max(
					16,
					(int)((float)($s->variables['max_connections'] ?? 100) / 4),
				),
				runtimeWritable: true,
				configKey: 'thread_cache_size',
				configFile: 'my.cnf',
			),
			requires: ['Threads_created', 'Connections'],
		);

		// ── Performance: queries ────────────────────────────────────

		$rules[] = new Rule(
			id: 'Slow_query_ratio',
			name: 'Slow query ratio',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: 'Slow_queries / Questions * 100',
			test: 'value > 0.05',
			issue: 'A measurable fraction of queries are exceeding long_query_time.',
			recommendation: 'Inspect the slow query log; add indexes or rewrite the worst offenders.',
			justification: '%s%% of queries are flagged as slow (Slow_queries=%s, Questions=%s).',
			justificationFormula: 'value, Slow_queries, Questions',
			requires: ['Slow_queries', 'Questions'],
		);

		$rules[] = new Rule(
			id: 'Select_full_join',
			name: 'Joins without indexes',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: 'Select_full_join / Questions * 100',
			test: 'value > 0.05',
			issue: 'Some joins are scanning entire tables because no usable index exists on the join column.',
			recommendation: 'Identify the offending queries (set log_queries_not_using_indexes) and add the missing indexes.',
			justification: '%s%% of queries did a full join without an index.',
			justificationFormula: 'value',
			requires: ['Select_full_join', 'Questions'],
		);

		$rules[] = new Rule(
			id: 'Sort_merge_passes',
			name: 'Sort buffer passes',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Sort_merge_passes / Sort_scan',
			test: 'value > 0.2',
			issue: 'Sorts are spilling to disk because sort_buffer_size is too small.',
			recommendation: 'Increase sort_buffer_size for sessions that run heavy sorts (per-thread; do not over-allocate).',
			justification: 'sort_buffer_size is too small for some sorts (Sort_merge_passes=%s).',
			justificationFormula: 'Sort_merge_passes',
			apply: new ApplyDescriptor(
				variable: 'sort_buffer_size',
				recommendedValue: static fn (Snapshot $s) => '4M',
				runtimeWritable: true,
				configKey: 'sort_buffer_size',
				configFile: 'my.cnf',
				note: 'Per-thread buffer — keep modest to avoid memory blow-up at high connection counts.',
			),
			requires: ['Sort_merge_passes', 'Sort_scan'],
		);

		// ── Memory: temp tables ─────────────────────────────────────

		$rules[] = new Rule(
			id: 'Created_tmp_disk_tables',
			name: 'On-disk temp tables',
			category: 'Memory',
			severity: Rule::SEVERITY_WARNING,
			formula: 'Created_tmp_disk_tables / Created_tmp_tables * 100',
			test: 'value > 25',
			issue: 'A large share of temporary tables are being created on disk instead of in memory.',
			recommendation: 'Increase tmp_table_size and max_heap_table_size to keep more temp tables in memory.',
			justification: '%s%% of temp tables ended up on disk.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'tmp_table_size',
				recommendedValue: static fn (Snapshot $s) => '64M',
				runtimeWritable: true,
				configKey: 'tmp_table_size',
				configFile: 'my.cnf',
			),
			requires: ['Created_tmp_disk_tables', 'Created_tmp_tables'],
		);

		// ── Table cache ─────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Table_open_cache',
			name: 'Table open cache hit rate',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Table_open_cache_misses / (Table_open_cache_hits + Table_open_cache_misses) * 100',
			test: 'value > 1',
			issue: 'The table open cache is too small; tables are being opened from disk frequently.',
			recommendation: 'Increase table_open_cache (and table_definition_cache) until the miss rate drops below 1%.',
			justification: '%s%% of table opens missed the cache.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'table_open_cache',
				recommendedValue: static fn (Snapshot $s) => (string)max(
					4000,
					(int)((float)($s->variables['table_open_cache'] ?? 2000) * 2),
				),
				runtimeWritable: true,
				configKey: 'table_open_cache',
				configFile: 'my.cnf',
			),
			requires: ['Table_open_cache_misses', 'Table_open_cache_hits'],
		);

		// ── InnoDB buffer pool ──────────────────────────────────────

		$rules[] = new Rule(
			id: 'Innodb_buffer_pool_hit_rate',
			name: 'InnoDB buffer pool hit rate',
			category: 'InnoDB',
			severity: Rule::SEVERITY_WARNING,
			formula: '100 - (Innodb_buffer_pool_reads / Innodb_buffer_pool_read_requests * 100)',
			test: 'value < 99',
			issue: 'The InnoDB buffer pool is too small — pages are being fetched from disk too often.',
			recommendation: 'Increase innodb_buffer_pool_size; aim for ~60% of total RAM on a dedicated DB host.',
			justification: 'Hit rate is %s%%; aim for 99%% or higher.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'innodb_buffer_pool_size',
				recommendedValue: static fn (Snapshot $s) => (string)max(
					128 * 1024 * 1024,
					(int)((float)($s->variables['innodb_buffer_pool_size'] ?? 134217728) * 1.5),
				),
				runtimeWritable: true,
				configKey: 'innodb_buffer_pool_size',
				configFile: 'my.cnf',
				note: 'Dynamic on MySQL 5.7+ — but persisting to my.cnf is recommended so the value survives restarts.',
			),
			requires: ['Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests'],
		);

		$rules[] = new Rule(
			id: 'Innodb_buffer_pool_pages_dirty',
			name: 'InnoDB dirty page ratio',
			category: 'InnoDB',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Innodb_buffer_pool_pages_dirty / Innodb_buffer_pool_pages_total * 100',
			test: 'value > 75',
			issue: 'A large share of buffer pool pages are dirty — flushing is falling behind writes.',
			recommendation: 'Increase innodb_io_capacity (or io_capacity_max) to let the flusher keep pace.',
			justification: '%s%% of buffer pool pages are dirty.',
			justificationFormula: 'value',
			requires: ['Innodb_buffer_pool_pages_dirty', 'Innodb_buffer_pool_pages_total'],
		);

		// ── InnoDB log ──────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Innodb_log_file_size',
			name: 'InnoDB log file size',
			category: 'InnoDB',
			severity: Rule::SEVERITY_WARNING,
			formula: 'innodb_log_file_size',
			test: 'value < 134217728',
			issue: 'innodb_log_file_size is small; a small log forces frequent checkpoints and limits write throughput.',
			recommendation: 'Increase innodb_log_file_size to at least 128 MB on busy servers.',
			justification: 'innodb_log_file_size is %s bytes; a value of at least 128 MB is recommended.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'innodb_log_file_size',
				recommendedValue: static fn (Snapshot $s) => '256M',
				runtimeWritable: false, // Older MySQL requires restart; MySQL 8.0+ accepts SET GLOBAL but only resizes after a flush.
				configKey: 'innodb_log_file_size',
				configFile: 'my.cnf',
				note: 'Server restart recommended after changing this setting.',
			),
			requires: ['innodb_log_file_size'],
		);

		$rules[] = new Rule(
			id: 'Innodb_flush_log_at_trx_commit',
			name: 'InnoDB log flush mode',
			category: 'InnoDB',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'innodb_flush_log_at_trx_commit',
			test: 'value != 1',
			issue: 'innodb_flush_log_at_trx_commit is not set to the durable value (1).',
			recommendation: 'Use 1 unless you accept losing up to one second of writes on crash. Values 0 or 2 trade durability for throughput.',
			justification: 'innodb_flush_log_at_trx_commit is currently %s.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'innodb_flush_log_at_trx_commit',
				recommendedValue: static fn (Snapshot $s) => '1',
				runtimeWritable: true,
				configKey: 'innodb_flush_log_at_trx_commit',
				configFile: 'my.cnf',
			),
			requires: ['innodb_flush_log_at_trx_commit'],
		);

		// ── Network ─────────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Max_allowed_packet',
			name: 'max_allowed_packet',
			category: 'Connections',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'max_allowed_packet',
			test: 'value < 16777216',
			issue: 'max_allowed_packet is small; large file uploads or BLOBs may be rejected.',
			recommendation: 'Set max_allowed_packet to at least 16 MB. Nextcloud benefits from 64 MB.',
			justification: 'max_allowed_packet is %s bytes.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'max_allowed_packet',
				recommendedValue: static fn (Snapshot $s) => '64M',
				runtimeWritable: true,
				configKey: 'max_allowed_packet',
				configFile: 'my.cnf',
			),
			requires: ['max_allowed_packet'],
		);

		// ── Replication ─────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Slave_lag',
			name: 'Replica lag',
			category: 'Replication',
			severity: Rule::SEVERITY_ALERT,
			formula: 'Slave_running',
			test: 'value == 0',
			issue: 'A configured replica is not running.',
			recommendation: 'Check the replica with SHOW REPLICA STATUS; resolve any IO/SQL errors and restart replication.',
			justification: 'Slave_running is reporting OFF.',
			justificationFormula: '',
			requires: ['Slave_running'],
		);

		// ── Security ────────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Skip_name_resolve',
			name: 'Skip name resolve',
			category: 'Security',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'skip_name_resolve',
			test: 'value == 0',
			issue: 'Reverse DNS is performed on every connection — slow DNS makes connection setup slow.',
			recommendation: 'Set skip_name_resolve=1 and grant by IP. Removes a network round-trip per connection.',
			justification: 'skip_name_resolve is currently OFF.',
			justificationFormula: '',
			requires: ['skip_name_resolve'],
		);

		// ── Memory: query cache (MySQL 5.x / MariaDB only — auto-skipped on 8.0+) ──

		$rules[] = new Rule(
			id: 'Query_cache_efficiency',
			name: 'Query cache efficiency',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Qcache_hits / (Qcache_hits + Com_select + 1) * 100',
			test: 'value < 20',
			issue: 'The query cache is enabled but has poor hit rates; it may be hurting more than helping.',
			recommendation: 'Disable the query cache (query_cache_type=OFF) or accept the inefficiency. The query cache was removed in MySQL 8.0.',
			justification: 'Query cache hit rate is %s%%.',
			justificationFormula: 'value',
			requires: ['Qcache_hits', 'Com_select'],
		);

		// ── MyISAM ──────────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Key_buffer_hit_rate',
			name: 'MyISAM key buffer hit rate',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: '100 - (Key_reads / Key_read_requests * 100)',
			test: 'value < 99',
			issue: 'MyISAM index reads are not finding their pages in the key buffer.',
			recommendation: 'Increase key_buffer_size, or migrate the affected tables to InnoDB.',
			justification: 'Key buffer hit rate is %s%%.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'key_buffer_size',
				recommendedValue: static fn (Snapshot $s) => '64M',
				runtimeWritable: true,
				configKey: 'key_buffer_size',
				configFile: 'my.cnf',
			),
			requires: ['Key_reads', 'Key_read_requests'],
		);

		// ── Locks ───────────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Table_locks_waited',
			name: 'Table lock contention',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: 'Table_locks_waited / Table_locks_immediate * 100',
			test: 'value > 1',
			issue: 'A noticeable share of table-level lock requests had to wait.',
			recommendation: 'Migrate locking-heavy MyISAM tables to InnoDB; review long-running transactions.',
			justification: '%s%% of table locks had to wait.',
			justificationFormula: 'value',
			requires: ['Table_locks_waited', 'Table_locks_immediate'],
		);

		// ── Open files ──────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Open_files_limit',
			name: 'Open files limit',
			category: 'Connections',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Open_files / open_files_limit * 100',
			test: 'value > 80',
			issue: 'The server has approached its OS open-files limit.',
			recommendation: 'Raise open_files_limit and / or systemd LimitNOFILE.',
			justification: '%s%% of open_files_limit is in use.',
			justificationFormula: 'value',
			requires: ['Open_files', 'open_files_limit'],
		);

		// ── Binlog cache ────────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Binlog_cache_disk_use',
			name: 'Binlog cache spilled to disk',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Binlog_cache_disk_use / Binlog_cache_use * 100',
			test: 'value > 10',
			issue: 'Some transactions are exceeding binlog_cache_size and spilling to a temporary file.',
			recommendation: 'Increase binlog_cache_size if your transactions are large.',
			justification: '%s%% of binlog cache uses spilled to disk.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'binlog_cache_size',
				recommendedValue: static fn (Snapshot $s) => '4M',
				runtimeWritable: true,
				configKey: 'binlog_cache_size',
				configFile: 'my.cnf',
			),
			requires: ['Binlog_cache_disk_use', 'Binlog_cache_use'],
		);

		$rules[] = new Rule(
			id: 'Sync_binlog',
			name: 'Binary log durability',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'sync_binlog',
			test: 'value != 1',
			issue: 'sync_binlog is not 1 — replicas can lose transactions on a primary crash.',
			recommendation: 'Set sync_binlog=1 unless you knowingly accept replica drift on crash.',
			justification: 'sync_binlog is currently %s.',
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'sync_binlog',
				recommendedValue: static fn (Snapshot $s) => '1',
				runtimeWritable: true,
				configKey: 'sync_binlog',
				configFile: 'my.cnf',
			),
			requires: ['sync_binlog'],
		);

		// ── Additional rules ported from phpMyAdmin's advisor ───────
		//
		// These mirror upstream rules that didn't have a local
		// equivalent in the original 24-rule port.  Upstream's English
		// `id` is preserved in the trailing comment so future sync
		// passes can match by id verbatim.  Rules that depend on
		// `fired()` (cross-rule predicate), `substr/preg_match` on
		// version strings, or `system_memory` (not exposed via
		// `SHOW VARIABLES` on most installs) are intentionally not
		// ported — porting them faithfully would require either
		// rule-graph evaluation or string ops that the safe expression
		// evaluator deliberately doesn't support.

		// Server / data-quality
		$rules[] = new Rule(
			// upstream id: "Questions below 1,000"
			id: 'Questions_below_1000',
			name: 'Too few queries to analyse',
			category: 'Server',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Questions',
			test: 'value < 1000',
			issue: 'Fewer than 1,000 queries have run against this server, so statistical advice may be inaccurate.',
			recommendation: 'Let the server run for longer before relying on the recommendations below.',
			justification: 'Current Questions: %s.',
			justificationFormula: 'value',
			requires: ['Questions'],
		);

		// Performance / queries
		$rules[] = new Rule(
			// upstream id: "Slow query rate"
			id: 'Slow_query_rate',
			name: 'Slow query rate',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: '(Slow_queries / Questions * 100) / Uptime',
			test: 'value * 60 * 60 > 1',
			issue: 'A high rate of slow queries relative to server uptime.',
			recommendation: 'Increase long_query_time, or optimize the queries listed in the slow query log.',
			justification: 'Slow query rate is %s%% per hour; aim for less than 1%% per hour.',
			justificationFormula: 'round(value * 3600, 2)',
			requires: ['Slow_queries', 'Questions', 'Uptime'],
		);

		// Performance / sorts
		$rules[] = new Rule(
			// upstream id: "Sort rows"
			id: 'Sort_rows',
			name: 'High sorted-row rate',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Sort_rows / Uptime',
			test: 'value * 60 >= 1',
			issue: 'Many rows are being sorted server-side.',
			recommendation: 'Make sure ORDER BY columns are indexed so sorts use indexes instead of merge passes.',
			justification: 'Sorted rows average: %s per hour.',
			justificationFormula: 'round(value * 3600, 1)',
			requires: ['Sort_rows', 'Uptime'],
		);

		$rules[] = new Rule(
			// upstream id: "Rate of sorts that cause temporary tables"
			id: 'Sort_merge_passes_rate',
			name: 'Sort merge-pass rate',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Sort_merge_passes / Uptime',
			test: 'value * 60 * 60 > 1',
			issue: 'Sorts are spilling to temporary tables faster than once per hour.',
			recommendation: 'Increase sort_buffer_size and/or read_rnd_buffer_size, depending on your memory budget.',
			justification: 'Merge-pass rate: %s per hour; aim for less than 1.',
			justificationFormula: 'round(value * 3600, 2)',
			requires: ['Sort_merge_passes', 'Uptime'],
		);

		// Performance / joins + scans (rate-based companions to existing absolute checks)
		$rules[] = new Rule(
			// upstream id: "Rate of joins without indexes"
			id: 'Join_without_index_rate',
			name: 'Join-without-index rate',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: '(Select_range_check + Select_scan + Select_full_join) / Uptime',
			test: 'value * 60 * 60 > 1',
			issue: 'Many SELECTs are doing full table scans or joins without indexes.',
			recommendation: 'Add indexes for the columns used in JOIN conditions and WHERE filters.',
			justification: 'Index-less join+scan rate: %s per hour; aim for less than 1.',
			justificationFormula: 'round(value * 3600, 2)',
			requires: ['Select_range_check', 'Select_scan', 'Select_full_join', 'Uptime'],
		);

		$rules[] = new Rule(
			// upstream id: "Rate of reading first index entry"
			id: 'Handler_read_first_rate',
			name: 'Full-index-scan rate',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Handler_read_first / Uptime',
			test: 'value * 60 * 60 > 1',
			issue: 'Many queries are reading the first index entry, suggesting frequent full-index scans.',
			recommendation: 'Investigate whether the indexes match query predicates; rewrite queries that scan the whole index.',
			justification: 'Full-index-scan rate: %s per hour; aim for less than 1.',
			justificationFormula: 'round(value * 3600, 2)',
			requires: ['Handler_read_first', 'Uptime'],
		);

		$rules[] = new Rule(
			// upstream id: "Rate of reading fixed position"
			id: 'Handler_read_rnd_rate',
			name: 'Fixed-position read rate',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Handler_read_rnd / Uptime',
			test: 'value * 60 * 60 > 1',
			issue: 'Many queries are reading rows by fixed position — usually a sign of full table scans + sorts.',
			recommendation: 'Add indexes that match the queries\' ORDER BY / JOIN columns.',
			justification: 'Fixed-position read rate: %s per hour; aim for less than 1.',
			justificationFormula: 'round(value * 3600, 2)',
			requires: ['Handler_read_rnd', 'Uptime'],
		);

		$rules[] = new Rule(
			// upstream id: "Rate of reading next table row"
			id: 'Handler_read_rnd_next_rate',
			name: 'Sequential-row read rate',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Handler_read_rnd_next / Uptime',
			test: 'value * 60 * 60 > 1',
			issue: 'Many queries are scanning tables sequentially.',
			recommendation: 'Add indexes that match common WHERE / JOIN predicates.',
			justification: 'Sequential-row read rate: %s per hour; aim for less than 1.',
			justificationFormula: 'round(value * 3600, 2)',
			requires: ['Handler_read_rnd_next', 'Uptime'],
		);

		// Memory / temp tables
		$rules[] = new Rule(
			// upstream id: "Different tmp_table_size and max_heap_table_size"
			id: 'Tmp_max_heap_size_mismatch',
			name: 'tmp_table_size and max_heap_table_size differ',
			category: 'Memory',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'tmp_table_size - max_heap_table_size',
			test: 'value != 0',
			issue: 'tmp_table_size and max_heap_table_size are not equal.',
			recommendation: 'Set both to the same value; the server uses the lower of the two as the in-memory limit.',
			justification: 'tmp_table_size = %s, max_heap_table_size = %s.',
			justificationFormula: 'tmp_table_size, max_heap_table_size',
			requires: ['tmp_table_size', 'max_heap_table_size'],
		);

		// Memory / MyISAM key buffer
		$rules[] = new Rule(
			// upstream id: "MyISAM key buffer size"
			id: 'Key_buffer_size_zero',
			name: 'MyISAM key buffer disabled',
			category: 'Memory',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'key_buffer_size',
			test: 'value == 0',
			issue: 'key_buffer_size is 0; MyISAM indexes will not be cached.',
			recommendation: 'Set key_buffer_size based on your MyISAM index footprint; 64M is a reasonable starting point.',
			justification: 'key_buffer_size is 0.',
			justificationFormula: '',
			apply: new ApplyDescriptor(
				variable: 'key_buffer_size',
				recommendedValue: static fn (Snapshot $s) => (string)(64 * 1024 * 1024),
				runtimeWritable: true,
				configKey: 'key_buffer_size',
				configFile: 'my.cnf',
			),
			requires: ['key_buffer_size'],
		);

		$rules[] = new Rule(
			// upstream id: "Max % MyISAM key buffer ever used"
			id: 'Key_buffer_peak_usage_low',
			name: 'MyISAM key buffer peak usage low',
			category: 'Memory',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Key_blocks_used * key_cache_block_size / key_buffer_size * 100',
			test: 'value < 95 && key_buffer_size > 0',
			issue: 'The MyISAM key buffer has rarely been more than 95%% full.',
			recommendation: 'Consider lowering key_buffer_size, or check whether expected indexes still exist.',
			justification: 'Peak key buffer usage: %s%%; aim for >= 95%%.',
			justificationFormula: 'round(value, 1)',
			requires: ['Key_blocks_used', 'key_cache_block_size', 'key_buffer_size'],
		);

		// Performance / open files
		$rules[] = new Rule(
			// upstream id: "Rate of open files"
			id: 'Open_files_rate',
			name: 'File-open rate',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Open_files / Uptime',
			test: 'value * 60 * 60 > 5',
			issue: 'Files are being opened at a high rate.',
			recommendation: 'Increase open_files_limit and verify that the OS limit is not throttling the server.',
			justification: 'File-open rate: %s per hour; aim for less than 5.',
			justificationFormula: 'round(value * 3600, 1)',
			requires: ['Open_files', 'Uptime'],
		);

		// Performance / locks
		$rules[] = new Rule(
			// upstream id: "Table lock wait rate"
			id: 'Table_lock_wait_rate',
			name: 'Table-lock wait rate',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Table_locks_waited / Uptime',
			test: 'value * 60 * 60 > 1',
			issue: 'Table locks are being waited on at a high rate.',
			recommendation: 'Move busy MyISAM tables to InnoDB, or optimize the queries that hold the locks.',
			justification: 'Lock-wait rate: %s per hour; aim for less than 1.',
			justificationFormula: 'round(value * 3600, 2)',
			requires: ['Table_locks_waited', 'Uptime'],
		);

		// Performance / threads
		$rules[] = new Rule(
			// upstream id: "Thread cache"
			id: 'Thread_cache_size_zero',
			name: 'Thread cache disabled',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: 'thread_cache_size',
			test: 'value < 1',
			issue: 'thread_cache_size is 0; every new connection creates a new thread.',
			recommendation: 'Set thread_cache_size to a non-zero value (max_connections / 4 is a reasonable start).',
			justification: 'thread_cache_size is 0.',
			justificationFormula: '',
			apply: new ApplyDescriptor(
				variable: 'thread_cache_size',
				recommendedValue: static fn (Snapshot $s) => (string)max(
					16,
					(int)((float)($s->variables['max_connections'] ?? 100) / 4),
				),
				runtimeWritable: true,
				configKey: 'thread_cache_size',
				configFile: 'my.cnf',
			),
			requires: ['thread_cache_size'],
		);

		$rules[] = new Rule(
			// upstream id: "Slow launch time"
			id: 'Slow_launch_time_high',
			name: 'slow_launch_time threshold high',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'slow_launch_time',
			test: 'value > 2',
			issue: 'slow_launch_time is set higher than 2 seconds.',
			recommendation: 'Set slow_launch_time to 1 or 2 so the Slow_launch_threads counter is meaningful.',
			justification: 'slow_launch_time is %s seconds.',
			justificationFormula: 'value',
			requires: ['slow_launch_time'],
		);

		$rules[] = new Rule(
			// upstream id: "Threads that are slow to launch"
			id: 'Slow_launch_threads',
			name: 'Threads slow to launch',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: 'Slow_launch_threads',
			test: 'value > 0 && slow_launch_time > 0',
			issue: 'Some threads have taken longer than slow_launch_time to start.',
			recommendation: 'Investigate system load — slow thread launches usually indicate broader contention.',
			justification: '%s thread(s) took longer than slow_launch_time to start.',
			justificationFormula: 'value',
			requires: ['Slow_launch_threads', 'slow_launch_time'],
		);

		// Connections (rate companions to existing absolute checks)
		$rules[] = new Rule(
			// upstream id: "Rate of aborted connections"
			id: 'Aborted_connects_rate',
			name: 'Aborted-connection rate',
			category: 'Connections',
			severity: Rule::SEVERITY_WARNING,
			formula: 'Aborted_connects / Uptime',
			test: 'value * 60 * 60 > 1',
			issue: 'Connection attempts are aborting at a high rate.',
			recommendation: 'Check for clients with bad credentials, network drops, or insufficient max_allowed_packet.',
			justification: 'Aborted-connection rate: %s per hour; aim for less than 1.',
			justificationFormula: 'round(value * 3600, 2)',
			requires: ['Aborted_connects', 'Uptime'],
		);

		$rules[] = new Rule(
			// upstream id: "Percentage of aborted clients"
			id: 'Aborted_clients_percentage',
			name: 'Aborted-client percentage',
			category: 'Connections',
			severity: Rule::SEVERITY_WARNING,
			formula: 'Aborted_clients / Connections * 100',
			test: 'value > 2',
			issue: 'A noticeable share of connected clients are dropping without closing the connection.',
			recommendation: 'Check for network issues or client code that does not close DB handles cleanly.',
			justification: '%s%% of all clients aborted (Aborted_clients=%s, Connections=%s).',
			justificationFormula: 'round(value, 1), Aborted_clients, Connections',
			requires: ['Aborted_clients', 'Connections'],
		);

		$rules[] = new Rule(
			// upstream id: "Rate of aborted clients"
			id: 'Aborted_clients_rate',
			name: 'Aborted-client rate',
			category: 'Connections',
			severity: Rule::SEVERITY_WARNING,
			formula: 'Aborted_clients / Uptime',
			test: 'value * 60 * 60 > 1',
			issue: 'Clients are aborting at a high rate.',
			recommendation: 'Check for network issues or client code that does not close DB handles cleanly.',
			justification: 'Aborted-client rate: %s per hour; aim for less than 1.',
			justificationFormula: 'round(value * 3600, 2)',
			requires: ['Aborted_clients', 'Uptime'],
		);

		// InnoDB
		$rules[] = new Rule(
			// upstream id: "InnoDB log size"
			id: 'Innodb_log_size_small',
			name: 'InnoDB log size small relative to buffer pool',
			category: 'InnoDB',
			severity: Rule::SEVERITY_NOTICE,
			// `innodb_log_files_in_group` is 1 on MariaDB 10.5+ and not all
			// installs expose it as a status/variable.  We rely on the
			// requires list to skip the rule on installs where the value
			// isn't reported.
			formula: '(innodb_log_file_size * innodb_log_files_in_group) / innodb_buffer_pool_size * 100',
			test: 'value < 20 && innodb_log_file_size / (1024 * 1024) < 256',
			issue: 'InnoDB log size is small relative to the buffer pool.',
			recommendation: 'Aim for innodb_log_file_size around 25%% of innodb_buffer_pool_size, capped at 256 MiB.',
			justification: 'InnoDB log is %s%% of the buffer pool; aim for >= 20%%.',
			justificationFormula: 'round(value, 1)',
			requires: ['innodb_log_file_size', 'innodb_log_files_in_group', 'innodb_buffer_pool_size'],
		);

		$rules[] = new Rule(
			// upstream id: "Max InnoDB log size"
			id: 'Innodb_log_size_large',
			name: 'InnoDB log size very large',
			category: 'InnoDB',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'innodb_log_file_size / (1024 * 1024)',
			test: 'value > 256',
			issue: 'innodb_log_file_size is set above 256 MiB.',
			recommendation: 'A very large log slows recovery after a crash; 25%% of the buffer pool is usually enough.',
			justification: 'innodb_log_file_size is %s MiB.',
			justificationFormula: 'round(value, 1)',
			requires: ['innodb_log_file_size'],
		);

		// MyISAM
		$rules[] = new Rule(
			// upstream id: "MyISAM concurrent inserts"
			id: 'Concurrent_insert_off',
			name: 'MyISAM concurrent inserts disabled',
			category: 'MyISAM',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'concurrent_insert',
			test: 'value == 0',
			issue: 'concurrent_insert is set to 0, blocking concurrent INSERT/SELECT on MyISAM tables.',
			recommendation: 'Set concurrent_insert to 1 (or AUTO/2) to reduce reader/writer contention on MyISAM.',
			justification: 'concurrent_insert is 0.',
			justificationFormula: '',
			requires: ['concurrent_insert'],
		);

		// Query cache (legacy — these rules will skip on MySQL 8.0+ via
		// `requires`, since the QC variables don't exist there.  The
		// upstream `fired()` precondition that hides them when the QC
		// is fully disabled is intentionally not modelled — at worst,
		// users see two QC-related findings instead of one.)
		$rules[] = new Rule(
			// upstream id: "Query cache disabled"
			id: 'Query_cache_disabled',
			name: 'Query cache disabled',
			category: 'QueryCache',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'query_cache_size',
			test: 'value == 0',
			issue: 'The query cache is disabled (query_cache_size == 0).',
			recommendation: 'On read-heavy MySQL <= 5.7, sizing query_cache_size and setting query_cache_type=ON can help. (Removed in MySQL 8.0+.)',
			justification: 'query_cache_size is 0.',
			justificationFormula: '',
			requires: ['query_cache_size'],
		);

		$rules[] = new Rule(
			// upstream id: "Query Cache usage"
			id: 'Query_cache_usage_low',
			name: 'Query cache underutilized',
			category: 'QueryCache',
			severity: Rule::SEVERITY_NOTICE,
			formula: '100 - Qcache_free_memory / query_cache_size * 100',
			test: 'value < 80 && query_cache_size > 0',
			issue: 'Less than 80%% of the query cache is in use.',
			recommendation: 'query_cache_limit may be too low; raising it can let larger results enter the cache.',
			justification: 'Query cache is %s%% utilized.',
			justificationFormula: 'round(value, 1)',
			requires: ['Qcache_free_memory', 'query_cache_size'],
		);

		$rules[] = new Rule(
			// upstream id: "Query cache fragmentation"
			id: 'Query_cache_fragmentation',
			name: 'Query cache fragmented',
			category: 'QueryCache',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Qcache_free_blocks / (Qcache_total_blocks / 2) * 100',
			test: 'value > 20 && Qcache_total_blocks > 0',
			issue: 'The query cache is significantly fragmented.',
			recommendation: 'Tune query_cache_min_res_unit, or temporarily flush the cache to defragment.',
			justification: 'Cache fragmentation: %s%%; aim for less than 20%%.',
			justificationFormula: 'round(value, 1)',
			requires: ['Qcache_free_blocks', 'Qcache_total_blocks'],
		);

		$rules[] = new Rule(
			// upstream id: "Query cache low memory prunes"
			id: 'Query_cache_lowmem_prunes',
			name: 'Query cache evicting under memory pressure',
			category: 'QueryCache',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Qcache_lowmem_prunes / Qcache_inserts * 100',
			test: 'value > 0.1 && Qcache_inserts > 0',
			issue: 'Cached queries are being evicted because the query cache is full.',
			recommendation: 'Increase query_cache_size — but in small increments; an oversized cache adds maintenance overhead.',
			justification: 'Lowmem prune ratio: %s%% of inserts.',
			justificationFormula: 'round(value, 2)',
			requires: ['Qcache_lowmem_prunes', 'Qcache_inserts'],
		);

		$rules[] = new Rule(
			// upstream id: "Query cache max size"
			id: 'Query_cache_size_too_large',
			name: 'Query cache size very large',
			category: 'QueryCache',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'query_cache_size',
			test: 'value > 1024 * 1024 * 128',
			issue: 'query_cache_size is above 128 MiB; cache maintenance overhead grows with size.',
			recommendation: 'Reducing query_cache_size to 64-128 MiB is usually enough on MySQL <= 5.7.',
			justification: 'query_cache_size is %s bytes.',
			justificationFormula: 'value',
			requires: ['query_cache_size'],
		);

		// ── Uptime sanity ───────────────────────────────────────────

		$rules[] = new Rule(
			id: 'Short_uptime',
			name: 'Short server uptime',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'Uptime_hours',
			test: 'value < 24',
			issue: 'The server has been up for less than a day; statistics-driven rules will be less reliable.',
			recommendation: 'Re-run DB Doctor after the server has been running for at least a day.',
			justification: 'Uptime is %s hours; rules using counters are most accurate after 24+ hours.',
			justificationFormula: 'Uptime_hours',
			requires: ['Uptime_hours'],
		);

		// ── Nextcloud-specific database requirements ─────────────────

		$rules[] = new Rule(
			id: 'nc.transaction_isolation',
			name: 'Transaction isolation level',
			category: 'Nextcloud',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'nc_read_committed',
			test: 'value == 0',
			issue: 'The global transaction isolation level is not READ-COMMITTED. Nextcloud recommends READ-COMMITTED on MySQL/MariaDB to reduce lock contention and deadlocks under concurrent load.',
			recommendation: 'Set "transaction_isolation = READ-COMMITTED" in the [mysqld] section of my.cnf and restart the server.',
			justification: 'The server-wide transaction isolation level is not READ-COMMITTED.',
			justificationFormula: '',
			apply: new ApplyDescriptor(
				variable: 'transaction_isolation',
				recommendedValue: static fn (Snapshot $s) => 'READ-COMMITTED',
				runtimeWritable: false,
				configKey: 'transaction_isolation',
				configFile: 'my.cnf',
				note: 'Nextcloud already forces READ-COMMITTED per session; setting it globally is belt-and-suspenders and needs a server restart.',
			),
			requires: ['nc_read_committed'],
			docUrl: 'https://docs.nextcloud.com/server/latest/admin_manual/configuration_database/linux_database_configuration.html',
		);

		$rules[] = new Rule(
			id: 'nc.utf8mb4_charset',
			name: '4-byte charset (utf8mb4)',
			category: 'Nextcloud',
			severity: Rule::SEVERITY_WARNING,
			formula: 'nc_non_utf8mb4_tables',
			test: 'value > 0',
			issue: 'Some Nextcloud tables are not using the 4-byte utf8mb4 charset, so they cannot store emoji and some characters, and may break file names.',
			recommendation: 'Enable "mysql.utf8mb4" => true in config.php, then run "occ maintenance:repair --include-expensive" to convert the tables (requires innodb_file_per_table and Barracuda/DYNAMIC row format).',
			justification: '%s Nextcloud table(s) are not using utf8mb4.',
			justificationFormula: 'nc_non_utf8mb4_tables',
			requires: ['nc_non_utf8mb4_tables'],
			docUrl: 'https://docs.nextcloud.com/server/latest/admin_manual/configuration_database/mysql_4byte_support.html',
		);

		$rules[] = new Rule(
			id: 'nc.innodb_engine',
			name: 'InnoDB storage engine',
			category: 'Nextcloud',
			severity: Rule::SEVERITY_ALERT,
			formula: 'nc_non_innodb_tables',
			test: 'value > 0',
			issue: 'Some Nextcloud tables are not using the InnoDB storage engine. Nextcloud requires InnoDB; MyISAM and others lack transactions and row-level locking and can corrupt under load.',
			recommendation: 'Convert each affected table with "ALTER TABLE <name> ENGINE=InnoDB;" during a maintenance window.',
			justification: '%s Nextcloud table(s) are not using InnoDB.',
			justificationFormula: 'nc_non_innodb_tables',
			requires: ['nc_non_innodb_tables'],
			docUrl: 'https://docs.nextcloud.com/server/latest/admin_manual/configuration_database/linux_database_configuration.html',
		);

		// Shared Nextcloud schema-integrity checks (missing indices /
		// columns / primary keys).
		foreach ($this->nextcloudSchemaRules() as $rule) {
			$rules[] = $rule;
		}

		return $rules;
	}
}
