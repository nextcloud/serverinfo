<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Collector;

use bantu\IniGetWrapper\IniGetWrapper;

/**
 * @psalm-api
 *
 * @psalm-type ServerInfoOpcacheStatus = array{
 *     status: 'ok'|'not_loaded'|'disabled'|'api_restricted'|'status_unavailable',
 *     memory?: array{used: int, wasted: int, free: int, total: int},
 *     internedStrings?: array{used: int, free: int, total: int},
 *     keys?: array{used: int, max: int},
 *     hitRate?: float,
 *     cachedScripts?: int,
 *     oomRestarts?: int,
 *     cacheFull?: bool,
 *     revalidateFreq?: int,
 *     validateTimestamps?: bool,
 *     lastRestart?: int|null,
 *     jit?: array{enabled: bool, bufferUsed: int, bufferTotal: int}|null
 * }
 */
class Opcache {
	public function __construct(
		private IniGetWrapper $iniGetWrapper,
	) {
	}

	/**
	 * @return ServerInfoOpcacheStatus
	 */
	public function getData(): array {
		if (!extension_loaded('Zend OPcache')) {
			return ['status' => 'not_loaded'];
		}

		if (!$this->iniGetWrapper->getBool('opcache.enable')) {
			return ['status' => 'disabled'];
		}

		if (!$this->isApiPermitted()) {
			return ['status' => 'api_restricted'];
		}

		$disabledFunctions = (string)$this->iniGetWrapper->getString('disable_functions');
		if (str_contains($disabledFunctions, 'opcache_get_status')) {
			return ['status' => 'status_unavailable'];
		}

		$status = $this->readStatus();
		if (!is_array($status)) {
			return ['status' => 'status_unavailable'];
		}

		return array_merge(['status' => 'ok'], $this->mapStatus($status));
	}

	/**
	 * @return array|false the raw opcache_get_status(false) result
	 */
	protected function readStatus(): array|false {
		return function_exists('opcache_get_status') ? opcache_get_status(false) : false;
	}

	/**
	 * Nextcloud may be denied access to the OPcache API for its own directories,
	 * see the `opcache.restrict_api` ini setting.
	 */
	private function isApiPermitted(): bool {
		$restrictPath = rtrim((string)$this->iniGetWrapper->getString('opcache.restrict_api'), '/');
		return $restrictPath === ''
			|| \OC::$SERVERROOT === $restrictPath
			|| str_starts_with(\OC::$SERVERROOT, $restrictPath . '/');
	}

	/**
	 * @return array{
	 *     memory: array{used: int, wasted: int, free: int, total: int},
	 *     internedStrings: array{used: int, free: int, total: int},
	 *     keys: array{used: int, max: int},
	 *     hitRate: float,
	 *     cachedScripts: int,
	 *     oomRestarts: int,
	 *     cacheFull: bool,
	 *     revalidateFreq: int,
	 *     validateTimestamps: bool,
	 *     lastRestart: int|null,
	 *     jit: array{enabled: bool, bufferUsed: int, bufferTotal: int}|null
	 * }
	 */
	private function mapStatus(array $status): array {
		$memory = $status['memory_usage'];
		$strings = $status['interned_strings_usage'];
		$stats = $status['opcache_statistics'];

		$jit = null;
		if (isset($status['jit']['buffer_size']) && $status['jit']['buffer_size'] > 0) {
			$jit = [
				'enabled' => (bool)($status['jit']['enabled'] ?? false),
				'bufferUsed' => (int)$status['jit']['buffer_size'] - (int)$status['jit']['buffer_free'],
				'bufferTotal' => (int)$status['jit']['buffer_size'],
			];
		}

		return [
			'memory' => [
				'used' => (int)$memory['used_memory'],
				'wasted' => (int)$memory['wasted_memory'],
				'free' => (int)$memory['free_memory'],
				'total' => (int)$memory['used_memory'] + (int)$memory['wasted_memory'] + (int)$memory['free_memory'],
			],
			'internedStrings' => [
				'used' => (int)$strings['used_memory'],
				'free' => (int)$strings['free_memory'],
				'total' => (int)$strings['buffer_size'],
			],
			'keys' => [
				'used' => (int)$stats['num_cached_keys'],
				'max' => (int)$stats['max_cached_keys'],
			],
			'hitRate' => (float)$stats['opcache_hit_rate'],
			'cachedScripts' => (int)$stats['num_cached_scripts'],
			'oomRestarts' => (int)$stats['oom_restarts'],
			'cacheFull' => (bool)$status['cache_full'],
			'revalidateFreq' => (int)$this->iniGetWrapper->getNumeric('opcache.revalidate_freq'),
			'validateTimestamps' => (bool)$this->iniGetWrapper->getBool('opcache.validate_timestamps'),
			'lastRestart' => ((int)($stats['last_restart_time'] ?? 0)) ?: null,
			'jit' => $jit,
		];
	}
}
