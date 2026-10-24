<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Php;

use bantu\IniGetWrapper\IniGetWrapper;
use OCA\ServerInfo\Collector\Opcache;
use OCA\ServerInfo\Database\Snapshot;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\OsStatus\Host\IHost;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;

/**
 * Assembles the {@see Snapshot} the {@see PhpRuleSet} runs on: the PHP-FPM
 * pool status, OPcache usage, memory_limit and the server's memory.
 *
 * PHP-FPM and OPcache state belongs to the SAPI that runs the code, so this
 * only means something inside a web request; from cron or occ it would
 * describe the CLI.
 *
 * Every value is a number in the snapshot's `derived` map. A value that cannot
 * be read is left out, with the reason in `unavailable`, so that the rules
 * needing it are skipped and say why.
 *
 * @psalm-api
 * @psalm-import-type ServerInfoOpcacheStatus from Opcache
 */
class PhpProbe {
	/** Share of the currently available memory a pm.max_children suggestion may plan for. */
	public const MEMORY_SHARE = 0.8;

	/** Upper bound on the /proc reads for one snapshot. */
	private const MAX_WORKERS_MEASURED = 64;

	private const FPM_KEYS = [
		'fpm_max_children_reached',
		'fpm_listen_queue',
		'fpm_max_listen_queue',
		'fpm_total_processes',
		'fpm_peak_processes',
		'fpm_worker_mb',
		'fpm_worker_measured',
		'fpm_suggested_max_children',
		'fpm_headroom',
	];

	private const OPCACHE_KEYS = [
		'opcache_memory_used_mb',
		'opcache_memory_wasted_mb',
		'opcache_memory_total_mb',
		'opcache_memory_consumption_mb',
		'opcache_oom_restarts',
		'opcache_strings_used_mb',
		'opcache_strings_total_mb',
		'opcache_keys_used',
		'opcache_keys_max',
		'opcache_validate_timestamps',
		'opcache_revalidate_freq',
	];

	public function __construct(
		private Opcache $opcache,
		private Os $os,
		private IHost $host,
		private IAppConfig $appConfig,
		private IniGetWrapper $iniGetWrapper,
		private IL10N $l,
	) {
	}

	public function snapshot(): Snapshot {
		$derived = [];
		$unavailable = [];
		$details = [];

		$memoryLimit = $this->iniGetWrapper->getBytes('memory_limit');
		$derived['php_memory_limit_mb'] = $memoryLimit === null || $memoryLimit < 0
			? -1
			: self::megabytes($memoryLimit);

		$memory = $this->os->getMemory();
		$reason = $this->l->t('The memory of the server could not be read.');
		if ($memory->getMemTotal() > 0) {
			$derived['mem_total_mb'] = $memory->getMemTotal();
		} else {
			$unavailable['mem_total_mb'] = $reason;
		}
		if ($memory->getMemAvailable() > 0) {
			$derived['mem_available_mb'] = $memory->getMemAvailable();
		} else {
			$unavailable['mem_available_mb'] = $reason;
		}

		$fpm = $this->readFpmStatus();
		if (is_array($fpm)) {
			$details['fpm_pool'] = [(string)($fpm['pool'] ?? '')];
			$this->addFpm($fpm, (float)$derived['php_memory_limit_mb'], $derived, $unavailable);
		} else {
			// TRANSLATORS: %s is the name of PHP's server API, such as apache2handler or cli
			$reason = $this->l->t('PHP-FPM does not run this page; the PHP server API is %s.', [PHP_SAPI]);
			foreach (self::FPM_KEYS as $key) {
				$unavailable[$key] = $reason;
			}
		}

		$opcache = $this->opcache->getData();
		if ($opcache['status'] === 'ok') {
			$this->addOpcache($opcache, $derived);
		} else {
			$reason = match ($opcache['status']) {
				'not_loaded' => $this->l->t('OPcache is not loaded.'),
				'disabled' => $this->l->t('OPcache is disabled.'),
				'api_restricted' => $this->l->t('opcache.restrict_api keeps Nextcloud from reading the OPcache status.'),
				default => $this->l->t('The OPcache status is not available.'),
			};
			foreach (self::OPCACHE_KEYS as $key) {
				$unavailable[$key] = $reason;
			}
		}

		return new Snapshot(
			Snapshot::FLAVOUR_PHP,
			PHP_VERSION,
			[],
			[],
			$derived,
			$details,
			$unavailable,
		);
	}

	/**
	 * @return array|false fpm_get_status(), false outside PHP-FPM
	 */
	protected function readFpmStatus(): array|false {
		return function_exists('fpm_get_status') ? fpm_get_status() : false;
	}

	/**
	 * @param array<string, scalar|null> $derived
	 * @param array<string, string> $unavailable
	 */
	private function addFpm(array $fpm, float $memoryLimitMb, array &$derived, array &$unavailable): void {
		$total = (int)($fpm['total-processes'] ?? 0);
		$peak = max($total, (int)($fpm['max-active-processes'] ?? 0));

		$derived['fpm_max_children_reached'] = (int)($fpm['max-children-reached'] ?? 0);
		$derived['fpm_listen_queue'] = (int)($fpm['listen-queue'] ?? 0);
		$derived['fpm_max_listen_queue'] = (int)($fpm['max-listen-queue'] ?? 0);
		$derived['fpm_total_processes'] = $total;
		$derived['fpm_peak_processes'] = $peak;

		$pids = [];
		foreach ((array)($fpm['procs'] ?? []) as $proc) {
			$pid = is_array($proc) ? (int)($proc['pid'] ?? 0) : 0;
			if ($pid > 0) {
				$pids[] = $pid;
			}
		}

		$measured = $this->measureWorkers($pids);
		if ($measured !== []) {
			$derived['fpm_worker_mb'] = round(array_sum($measured) / (float)count($measured), 1);
			$derived['fpm_worker_measured'] = count($measured);
		} elseif ($memoryLimitMb > 0) {
			$derived['fpm_worker_mb'] = $memoryLimitMb;
			$derived['fpm_worker_measured'] = 0;
		} else {
			$reason = $this->l->t('The memory of the PHP-FPM workers could not be read, and memory_limit sets no upper bound.');
			foreach (['fpm_worker_mb', 'fpm_worker_measured', 'fpm_suggested_max_children', 'fpm_headroom'] as $key) {
				$unavailable[$key] = $reason;
			}
			return;
		}

		if (!isset($derived['mem_available_mb'])) {
			$unavailable['fpm_suggested_max_children'] = $unavailable['mem_available_mb'];
			$unavailable['fpm_headroom'] = $unavailable['mem_available_mb'];
			return;
		}

		// The running workers already hold their memory, so only the memory
		// still available has to fit the additional ones.
		$spare = (int)floor(self::MEMORY_SHARE * (float)$derived['mem_available_mb'] / $derived['fpm_worker_mb']);
		$derived['fpm_suggested_max_children'] = $total + $spare;
		$derived['fpm_headroom'] = $total + $spare - $peak;
	}

	/**
	 * Private memory of each readable worker in MB: RssAnon, which leaves out
	 * the OPcache segment and libraries every worker shares, or VmRSS on
	 * kernels that do not report it.
	 *
	 * @param list<int> $pids
	 * @return list<float>
	 */
	private function measureWorkers(array $pids): array {
		if ($this->appConfig->getAppValueString('restricted_mode', 'no') === 'yes'
			|| $this->host->osFamily() !== 'Linux') {
			return [];
		}

		$sizes = [];
		foreach (array_slice($pids, 0, self::MAX_WORKERS_MEASURED) as $pid) {
			$status = $this->host->readFile('/proc/' . $pid . '/status', 65536);
			if ($status === null) {
				continue;
			}
			if (preg_match('/^RssAnon:\s+(\d+)\s+kB/m', $status, $match) === 1
				|| preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $match) === 1) {
				$sizes[] = (float)$match[1] / 1024.0;
			}
		}
		return $sizes;
	}

	/**
	 * @param ServerInfoOpcacheStatus $opcache
	 * @param array<string, scalar|null> $derived
	 */
	private function addOpcache(array $opcache, array &$derived): void {
		$memory = $opcache['memory'] ?? ['used' => 0, 'wasted' => 0, 'free' => 0, 'total' => 0];
		$strings = $opcache['internedStrings'] ?? ['used' => 0, 'free' => 0, 'total' => 0];
		$keys = $opcache['keys'] ?? ['used' => 0, 'max' => 0];

		$derived['opcache_memory_used_mb'] = self::megabytes($memory['used']);
		$derived['opcache_memory_wasted_mb'] = self::megabytes($memory['wasted']);
		$derived['opcache_memory_total_mb'] = self::megabytes($memory['total']);
		$derived['opcache_memory_consumption_mb'] = (int)($this->iniGetWrapper->getNumeric('opcache.memory_consumption') ?? 0);
		$derived['opcache_oom_restarts'] = $opcache['oomRestarts'] ?? 0;
		$derived['opcache_strings_used_mb'] = self::megabytes($strings['used']);
		$derived['opcache_strings_total_mb'] = self::megabytes($strings['total']);
		$derived['opcache_keys_used'] = $keys['used'];
		$derived['opcache_keys_max'] = $keys['max'];
		$derived['opcache_validate_timestamps'] = ($opcache['validateTimestamps'] ?? true) ? 1 : 0;
		$derived['opcache_revalidate_freq'] = $opcache['revalidateFreq'] ?? 2;
	}

	private static function megabytes(int|float $bytes): float {
		return round((float)$bytes / 1048576.0, 1);
	}
}
