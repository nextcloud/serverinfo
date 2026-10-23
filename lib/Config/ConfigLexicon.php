<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Config;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;

/**
 * Config Lexicon for serverinfo.
 *
 * {@see ILexicon}
 */
class ConfigLexicon implements ILexicon {
	public const CACHED_SLOWEST_JOBS = 'cached_slowest_jobs';
	public const JOB_INTERVAL_JOB_STATS = 'job_interval_job_stats';

	public const CACHED_BOOT_TIME = 'cached_boot_time';
	public const CACHED_BOOT_TIME_SAMPLED_AT = 'cached_boot_time_sampled_at';

	public const CACHED_DB_CHECK = 'cached_db_check';

	public const CACHED_OS_FIRST_SEEN = 'cached_os_first_seen';
	public const CACHED_OS_PHP_PROCESS = 'cached_os_php_process';
	public const CACHED_OS_PHP_OUTDATED_SINCE = 'cached_os_php_outdated_since';
	public const JOB_INTERVAL_OS_STATUS = 'job_interval_os_status';
	public const OS_HELPER_REPORT_PATH = 'os_helper_report_path';
	public const OS_SECURITY_WARNING_DAYS = 'os_security_warning_days';
	public const OS_SECURITY_ERROR_DAYS = 'os_security_error_days';
	public const OS_REBOOT_WARNING_DAYS = 'os_reboot_warning_days';
	public const OS_REBOOT_ERROR_DAYS = 'os_reboot_error_days';
	public const OS_RESTART_WARNING_DAYS = 'os_restart_warning_days';
	public const OS_EOL_WARNING_DAYS = 'os_eol_warning_days';
	public const OS_LISTS_WARNING_DAYS = 'os_lists_warning_days';
	public const OS_LISTS_ERROR_DAYS = 'os_lists_error_days';
	public const OS_ALLOW_NETWORK = 'os_allow_network';

	#[\Override]
	public function getStrictness(): Strictness {
		// The app predates the lexicon and still writes keys that are not listed here
		return Strictness::IGNORE;
	}

	#[\Override]
	public function getAppConfigs(): array {
		return [
			new Entry(
				self::CACHED_SLOWEST_JOBS,
				ValueType::ARRAY,
				[],
				'Slowest background jobs, as collected by OCA\ServerInfo\Jobs\UpdateJobStats',
				lazy: true,
			),
			new Entry(
				self::CACHED_BOOT_TIME,
				ValueType::INT,
				0,
				'Boot time as a unix timestamp, remembered so that uptime does not cost a process fork on every poll',
				lazy: true,
			),
			new Entry(
				self::CACHED_BOOT_TIME_SAMPLED_AT,
				ValueType::INT,
				0,
				'When the boot time above was last measured',
				lazy: true,
			),
			new Entry(
				self::JOB_INTERVAL_JOB_STATS,
				ValueType::INT,
				60 * 60 * 6,
				'How often the background job statistics are recomputed, in seconds',
			),
			new Entry(
				self::CACHED_DB_CHECK,
				ValueType::ARRAY,
				[],
				'Outcome of the last database check run, read by the setup check on Settings > Overview',
				lazy: true,
			),
			new Entry(
				self::CACHED_OS_FIRST_SEEN,
				ValueType::ARRAY,
				[],
				'When each pending security update and reboot reason was first seen, for the alert thresholds',
				lazy: true,
			),
			new Entry(
				self::CACHED_OS_PHP_PROCESS,
				ValueType::ARRAY,
				[],
				'What the last web request saw of its own PHP process: libraries replaced on disk since it started',
				lazy: true,
			),
			new Entry(
				self::CACHED_OS_PHP_OUTDATED_SINCE,
				ValueType::INT,
				0,
				'When web requests first saw their PHP process running replaced libraries, 0 when they do not',
				lazy: true,
			),
			new Entry(
				self::JOB_INTERVAL_OS_STATUS,
				ValueType::INT,
				60 * 60,
				'How often the operating system status is collected, in seconds',
			),
			new Entry(
				self::OS_HELPER_REPORT_PATH,
				ValueType::STRING,
				'/var/lib/serverinfo/os-report.json',
				'Where the optional root helper bin/serverinfo-os-report writes its report; an absolute path ending in .json',
				lazy: true,
			),
			new Entry(self::OS_SECURITY_WARNING_DAYS, ValueType::INT, 3, 'Days a security update may be pending before a warning', lazy: true),
			new Entry(self::OS_SECURITY_ERROR_DAYS, ValueType::INT, 14, 'Days a security update may be pending before an error', lazy: true),
			new Entry(self::OS_REBOOT_WARNING_DAYS, ValueType::INT, 7, 'Days a reboot may be pending before a warning', lazy: true),
			new Entry(self::OS_REBOOT_ERROR_DAYS, ValueType::INT, 30, 'Days a reboot may be pending before an error', lazy: true),
			new Entry(self::OS_RESTART_WARNING_DAYS, ValueType::INT, 3, 'Days a service restart may be pending before a warning', lazy: true),
			new Entry(self::OS_EOL_WARNING_DAYS, ValueType::INT, 90, 'Days before the end of security support that a warning starts', lazy: true),
			new Entry(self::OS_LISTS_WARNING_DAYS, ValueType::INT, 7, 'Days without a package list refresh before a warning', lazy: true),
			new Entry(self::OS_LISTS_ERROR_DAYS, ValueType::INT, 30, 'Days without a package list refresh before an error', lazy: true),
			new Entry(
				self::OS_ALLOW_NETWORK,
				ValueType::BOOL,
				true,
				'Whether the operating system status may use the network as the web server user: on Arch, checkupdates syncs its own package databases and arch-audit fetches the security advisories',
				lazy: true,
			),
		];
	}

	#[\Override]
	public function getUserConfigs(): array {
		return [];
	}
}
