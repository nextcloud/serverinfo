<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Php;

use OCA\ServerInfo\Database\ApplyDescriptor;
use OCA\ServerInfo\Database\Rule;
use OCA\ServerInfo\Database\RuleSet;
use OCA\ServerInfo\Database\Snapshot;
use OCP\IL10N;

/**
 * Why a Nextcloud server is slow on the PHP side: PHP-FPM running out of
 * workers, OPcache running out of space, and memory_limit compared with RAM.
 *
 * The rules read the keys {@see PhpProbe} puts into the snapshot. A
 * justification is a template the Advisor fills from the comma-separated
 * expressions after it; the placeholders are handed back to t() as its own
 * parameters, so that translators can move them and they stay in place for
 * the Advisor.
 *
 * @psalm-api
 */
class PhpRuleSet implements RuleSet {
	/** Nextcloud's recommended memory_limit in MB, as \OC\MemoryInfo::RECOMMENDED_MEMORY_LIMIT. */
	public const RECOMMENDED_MEMORY_LIMIT_MB = 512;

	/**
	 * The hash table sizes OPcache rounds opcache.max_accelerated_files up to.
	 */
	public const OPCACHE_PRIMES = [223, 463, 983, 1979, 3907, 7963, 16229, 32531, 65407, 130987, 262237, 524521, 1048793];

	/** The highest opcache.max_accelerated_files PHP accepts. */
	public const OPCACHE_MAX_FILES = 1000000;

	private const DOC_FPM = 'https://www.php.net/manual/en/install.fpm.configuration.php';
	private const DOC_OPCACHE = 'https://www.php.net/manual/en/opcache.configuration.php';

	/** @var list<Rule>|null */
	private ?array $cache = null;

	public function __construct(
		private IL10N $l,
	) {
	}

	#[\Override]
	public function rules(): array {
		if ($this->cache === null) {
			$this->cache = $this->build();
		}
		return $this->cache;
	}

	#[\Override]
	public function flavour(): string {
		return Snapshot::FLAVOUR_PHP;
	}

	/**
	 * The opcache.max_accelerated_files OPcache really uses for a configured value.
	 */
	public static function effectiveMaxFiles(int $configured): int {
		$configured = min(max($configured, 200), self::OPCACHE_MAX_FILES);
		foreach (self::OPCACHE_PRIMES as $prime) {
			if ($prime >= $configured) {
				return $prime;
			}
		}
		return self::OPCACHE_PRIMES[count(self::OPCACHE_PRIMES) - 1];
	}

	/**
	 * A opcache.max_accelerated_files with room for twice the keys in use, as
	 * one of the sizes OPcache uses as they are, or null when the current one
	 * is already the largest.
	 */
	public static function recommendedMaxFiles(int $used, int $current): ?string {
		$wanted = max(2 * $used, $current + 1);
		foreach (self::OPCACHE_PRIMES as $prime) {
			if ($prime >= $wanted) {
				return (string)min($prime, self::OPCACHE_MAX_FILES);
			}
		}
		return $current < self::effectiveMaxFiles(self::OPCACHE_MAX_FILES) ? (string)self::OPCACHE_MAX_FILES : null;
	}

	/**
	 * @return list<Rule>
	 */
	private function build(): array {
		$rules = [];

		$rules[] = new Rule(
			id: 'php.fpm_max_children',
			name: $this->l->t('PHP-FPM worker limit'),
			category: 'PHP-FPM',
			severity: Rule::SEVERITY_WARNING,
			formula: 'fpm_max_children_reached',
			test: 'value > 0',
			issue: $this->l->t('Requests had to wait because every PHP-FPM worker was busy.'),
			recommendation: $this->l->t('Raise pm.max_children in the pool configuration as far as the memory allows, then reload PHP-FPM.'),
			// TRANSLATORS: %1$s is how often PHP-FPM hit pm.max_children, %2$s the most PHP-FPM processes that ran at once
			justification: $this->l->t('PHP-FPM reached pm.max_children %1$s times since it started, with up to %2$s processes at once.', ['%1$s', '%2$s']),
			justificationFormula: 'value, fpm_peak_processes',
			apply: new ApplyDescriptor(
				variable: 'pm.max_children',
				recommendedValue: static fn (Snapshot $s): ?string => isset($s->derived['fpm_headroom'], $s->derived['fpm_suggested_max_children'])
					&& $s->derived['fpm_headroom'] >= 1
					? (string)$s->derived['fpm_suggested_max_children']
					: null,
				runtimeWritable: false,
				configKey: 'pm.max_children',
				configFile: 'php-fpm.conf',
				note: fn (Snapshot $s): ?string => $this->maxChildrenNote($s),
			),
			requires: ['fpm_max_children_reached', 'fpm_peak_processes'],
			docUrl: self::DOC_FPM,
		);

		$rules[] = new Rule(
			id: 'php.fpm_memory_headroom',
			name: $this->l->t('Memory for more PHP-FPM workers'),
			category: 'PHP-FPM',
			severity: Rule::SEVERITY_WARNING,
			formula: 'fpm_headroom',
			test: 'fpm_max_children_reached > 0 && value < 1',
			issue: $this->l->t('PHP-FPM ran out of workers, and the server has no free memory for more.'),
			recommendation: $this->l->t('Add memory, reduce what other services on the server use, or lower memory_limit if Nextcloud\'s requests do not need it.'),
			// TRANSLATORS: %1$s and %2$s are sizes in megabytes
			justification: $this->l->t('%1$s MB of memory is available, and a PHP-FPM worker takes about %2$s MB.', ['%1$s', '%2$s']),
			justificationFormula: 'mem_available_mb, fpm_worker_mb',
			requires: ['fpm_max_children_reached', 'fpm_worker_mb', 'mem_available_mb', 'fpm_headroom'],
			docUrl: self::DOC_FPM,
		);

		$rules[] = new Rule(
			id: 'php.fpm_listen_queue',
			name: $this->l->t('PHP-FPM listen queue'),
			category: 'PHP-FPM',
			severity: Rule::SEVERITY_WARNING,
			formula: 'fpm_listen_queue',
			test: 'value > 0 || fpm_max_listen_queue > 0',
			issue: $this->l->t('Requests queued up before a PHP-FPM worker could take them.'),
			recommendation: $this->l->t('Raise pm.max_children if the memory allows it. With pm = dynamic, more pm.min_spare_servers absorb bursts; slow requests that keep workers busy are worth a look too.'),
			// TRANSLATORS: %1$s is the number of requests waiting now, %2$s the most that waited at once
			justification: $this->l->t('%1$s requests are waiting now; at most %2$s waited at once since PHP-FPM started.', ['%1$s', '%2$s']),
			justificationFormula: 'value, fpm_max_listen_queue',
			requires: ['fpm_listen_queue', 'fpm_max_listen_queue'],
			docUrl: self::DOC_FPM,
		);

		$rules[] = new Rule(
			id: 'php.opcache_memory',
			name: $this->l->t('OPcache memory'),
			category: 'OPcache',
			severity: Rule::SEVERITY_WARNING,
			formula: 'round((opcache_memory_used_mb + opcache_memory_wasted_mb) / opcache_memory_total_mb * 100)',
			test: 'value > 90 || opcache_oom_restarts > 0',
			issue: $this->l->t('OPcache is (nearly) out of memory, so PHP compiles scripts again instead of reusing them.'),
			recommendation: $this->l->t('Raise opcache.memory_consumption, then restart PHP-FPM or the web server.'),
			// TRANSLATORS: %1$s and %2$s are sizes in megabytes, %3$s a count
			justification: $this->l->t('%1$s of %2$s MB are used or wasted; OPcache restarted %3$s times because it ran out of memory.', ['%1$s', '%2$s', '%3$s']),
			justificationFormula: 'opcache_memory_used_mb + opcache_memory_wasted_mb, opcache_memory_total_mb, opcache_oom_restarts',
			apply: new ApplyDescriptor(
				variable: 'opcache.memory_consumption',
				recommendedValue: static fn (Snapshot $s): string => (string)self::doubled(
					(float)(($s->derived['opcache_memory_consumption_mb'] ?? 0) ?: ($s->derived['opcache_memory_total_mb'] ?? 0)),
					256,
				),
				runtimeWritable: false,
				configKey: 'opcache.memory_consumption',
				configFile: 'php.ini',
				note: $this->l->t('In megabytes.'),
			),
			requires: ['opcache_memory_used_mb', 'opcache_memory_wasted_mb', 'opcache_memory_total_mb', 'opcache_oom_restarts'],
			docUrl: self::DOC_OPCACHE . '#ini.opcache.memory-consumption',
		);

		$rules[] = new Rule(
			id: 'php.opcache_interned_strings',
			name: $this->l->t('OPcache interned strings'),
			category: 'OPcache',
			severity: Rule::SEVERITY_WARNING,
			formula: 'round(opcache_strings_used_mb / opcache_strings_total_mb * 100)',
			test: 'value > 90',
			issue: $this->l->t('The interned strings buffer is (nearly) full, so every worker keeps its own copy of strings that could be shared.'),
			recommendation: $this->l->t('Raise opcache.interned_strings_buffer, then restart PHP-FPM or the web server.'),
			// TRANSLATORS: %1$s and %2$s are sizes in megabytes
			justification: $this->l->t('%1$s of %2$s MB are in use.', ['%1$s', '%2$s']),
			justificationFormula: 'opcache_strings_used_mb, opcache_strings_total_mb',
			apply: new ApplyDescriptor(
				variable: 'opcache.interned_strings_buffer',
				recommendedValue: static fn (Snapshot $s): string => (string)self::doubled((float)($s->derived['opcache_strings_total_mb'] ?? 0), 16),
				runtimeWritable: false,
				configKey: 'opcache.interned_strings_buffer',
				configFile: 'php.ini',
				note: $this->l->t('In megabytes.'),
			),
			requires: ['opcache_strings_used_mb', 'opcache_strings_total_mb'],
			docUrl: self::DOC_OPCACHE . '#ini.opcache.interned-strings-buffer',
		);

		$rules[] = new Rule(
			id: 'php.opcache_max_accelerated_files',
			name: $this->l->t('OPcache script slots'),
			category: 'OPcache',
			severity: Rule::SEVERITY_WARNING,
			formula: 'round(opcache_keys_used / opcache_keys_max * 100)',
			test: 'value > 90',
			issue: $this->l->t('OPcache has (nearly) no room for more scripts, so new ones are compiled on every request.'),
			recommendation: $this->l->t('Raise opcache.max_accelerated_files, then restart PHP-FPM or the web server.'),
			// TRANSLATORS: %1$s and %2$s are counts of cached PHP scripts
			justification: $this->l->t('%1$s of %2$s keys are in use. OPcache rounds opcache.max_accelerated_files up to the next of its fixed sizes, so %2$s is the real limit.', ['%1$s', '%2$s']),
			justificationFormula: 'opcache_keys_used, opcache_keys_max',
			apply: new ApplyDescriptor(
				variable: 'opcache.max_accelerated_files',
				recommendedValue: static fn (Snapshot $s): ?string => self::recommendedMaxFiles(
					(int)($s->derived['opcache_keys_used'] ?? 0),
					(int)($s->derived['opcache_keys_max'] ?? 0),
				),
				runtimeWritable: false,
				configKey: 'opcache.max_accelerated_files',
				configFile: 'php.ini',
				note: $this->l->t('One of the sizes OPcache uses as it is, with room for twice the scripts cached now.'),
			),
			requires: ['opcache_keys_used', 'opcache_keys_max'],
			docUrl: self::DOC_OPCACHE . '#ini.opcache.max-accelerated-files',
		);

		$rules[] = new Rule(
			id: 'php.opcache_revalidate_freq',
			name: $this->l->t('OPcache file checks'),
			category: 'OPcache',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'opcache_revalidate_freq',
			test: 'opcache_validate_timestamps > 0 && value < 2',
			issue: $this->l->t('OPcache checks scripts for changes on (almost) every request.'),
			recommendation: $this->l->t('A higher opcache.revalidate_freq saves file system calls on every request.'),
			// TRANSLATORS: %1$s is a number of seconds
			justification: $this->l->t('opcache.revalidate_freq is %1$s seconds.', ['%1$s']),
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'opcache.revalidate_freq',
				recommendedValue: static fn (): string => '60',
				runtimeWritable: false,
				configKey: 'opcache.revalidate_freq',
				configFile: 'php.ini',
				note: $this->l->t('Changed PHP files then take up to a minute to take effect.'),
			),
			requires: ['opcache_validate_timestamps', 'opcache_revalidate_freq'],
			docUrl: self::DOC_OPCACHE . '#ini.opcache.revalidate-freq',
		);

		$rules[] = new Rule(
			id: 'php.memory_limit',
			name: $this->l->t('PHP memory limit'),
			category: 'PHP',
			severity: Rule::SEVERITY_WARNING,
			formula: 'php_memory_limit_mb',
			test: 'value >= 0 && value < ' . self::RECOMMENDED_MEMORY_LIMIT_MB,
			issue: $this->l->t('memory_limit is below what Nextcloud recommends; large requests can fail.'),
			recommendation: $this->l->t('Raise memory_limit to at least 512 MB.'),
			// TRANSLATORS: %1$s is a size in megabytes
			justification: $this->l->t('memory_limit is %1$s MB.', ['%1$s']),
			justificationFormula: 'value',
			apply: new ApplyDescriptor(
				variable: 'memory_limit',
				recommendedValue: static fn (): string => self::RECOMMENDED_MEMORY_LIMIT_MB . 'M',
				runtimeWritable: false,
				configKey: 'memory_limit',
				configFile: 'php.ini',
				note: $this->l->t('A php_admin_value[memory_limit] in the PHP-FPM pool takes precedence over php.ini.'),
			),
			requires: ['php_memory_limit_mb'],
			docUrl: 'https://www.php.net/manual/en/ini.core.php#ini.memory-limit',
		);

		$rules[] = new Rule(
			id: 'php.memory_limit_ram',
			name: $this->l->t('Memory limit compared with RAM'),
			category: 'PHP',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'fpm_peak_processes * php_memory_limit_mb',
			test: 'php_memory_limit_mb > 0 && value > mem_total_mb',
			issue: $this->l->t('PHP-FPM runs more workers than the RAM could hold if each used its full memory_limit.'),
			recommendation: $this->l->t('Most requests stay far below memory_limit, but many large ones at once would make the server swap or stop processes. Lower pm.max_children or memory_limit, or add RAM.'),
			// TRANSLATORS: %1$s is a number of processes, %2$s, %3$s and %4$s are sizes in megabytes
			justification: $this->l->t('%1$s processes × %2$s MB memory_limit = %3$s MB, more than the %4$s MB of RAM.', ['%1$s', '%2$s', '%3$s', '%4$s']),
			justificationFormula: 'fpm_peak_processes, php_memory_limit_mb, value, mem_total_mb',
			requires: ['fpm_peak_processes', 'php_memory_limit_mb', 'mem_total_mb'],
		);

		return $rules;
	}

	private function maxChildrenNote(Snapshot $s): ?string {
		$worker = $s->derived['fpm_worker_mb'] ?? null;
		$available = $s->derived['mem_available_mb'] ?? null;
		if ($worker === null || $available === null) {
			return null;
		}

		$share = (string)round(PhpProbe::MEMORY_SHARE * 100.0);
		$measured = (int)($s->derived['fpm_worker_measured'] ?? 0);
		if ($measured > 0) {
			// TRANSLATORS: %1$s is a percentage, %2$s and %3$s are sizes in megabytes, %4$s a number of processes
			return $this->l->t('Planned with %1$s %% of the %2$s MB of memory available now and %3$s MB of private memory per worker, the average of %4$s PHP-FPM processes.', [
				$share,
				(string)$available,
				(string)$worker,
				(string)$measured,
			]);
		}

		// TRANSLATORS: %1$s is a size in megabytes, %2$s a percentage, %3$s a size in megabytes
		return $this->l->t('The memory of the PHP-FPM workers could not be read, so every new worker is planned with the full memory_limit of %1$s MB, an upper bound, and %2$s %% of the %3$s MB of memory available now. There is likely room for more workers.', [
			(string)$worker,
			$share,
			(string)$available,
		]);
	}

	/**
	 * Twice the current size in MB, at least the minimum, in steps of 8 MB.
	 */
	private static function doubled(float $current, int $minimum): int {
		return max($minimum, (int)(ceil($current * 2.0 / 8.0) * 8.0));
	}
}
