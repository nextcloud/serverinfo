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
		];
	}

	#[\Override]
	public function getUserConfigs(): array {
		return [];
	}
}
