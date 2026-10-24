<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\ServerInfo\Database;

/**
 * Describes how a rule's recommendation can be applied to the running
 * server.  The Advisor returns one of these alongside a failing rule
 * when an apply is possible; the UI renders Apply / Snippet buttons
 * based on it.
 *
 * `recommendedValue` is a closure rather than a static string so it
 * can derive the right value from the current snapshot (e.g.
 * "innodb_buffer_pool_size = 60 % of total RAM" requires reading
 * `Mem_total` from the snapshot).
 *
 * `runtimeWritable` controls whether the "Apply now (live)" button is
 * shown.  Some MySQL variables (e.g. `innodb_log_file_size` on older
 * servers) can only be changed in `my.cnf` followed by a restart;
 * those flags are false here so the UI hides the live-apply button
 * but still offers the snippet.
 */
final class ApplyDescriptor {
	/**
	 * @param string $variable Name of the GLOBAL / GUC variable.
	 * @param \Closure(Snapshot):?string $recommendedValue Computes the suggested value from the snapshot;
	 *                                                     null when it gives none, which leaves the failing rule without an apply.
	 * @param bool $runtimeWritable Whether SET GLOBAL / ALTER SYSTEM SET reload-without-restart works.
	 * @param string $configKey Directive name as it appears in my.cnf / postgresql.conf / php.ini.
	 * @param string $configFile 'my.cnf' | 'postgresql.conf' | 'php.ini' | 'php-fpm.conf'
	 * @param string|(\Closure(Snapshot):?string)|null $note Optional human-readable caveat (e.g. "requires
	 *                                                       server restart"), or a closure deriving it from the snapshot.
	 */
	public function __construct(
		public readonly string $variable,
		public readonly \Closure $recommendedValue,
		public readonly bool $runtimeWritable,
		public readonly string $configKey,
		public readonly string $configFile,
		public readonly string|\Closure|null $note = null,
	) {
	}

	public function compute(Snapshot $snapshot): ?string {
		return ($this->recommendedValue)($snapshot);
	}

	public function noteFor(Snapshot $snapshot): ?string {
		return $this->note instanceof \Closure ? ($this->note)($snapshot) : $this->note;
	}
}
