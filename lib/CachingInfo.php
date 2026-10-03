<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IConfig;

class CachingInfo {
	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @return array{
	 *     opcache: array{enabled: bool, hits: int, misses: int, hitRate: float, memoryUsedMB: float, memoryFreeMB: float, cachedScripts: int},
	 *     apcu: array{enabled: bool, hits: int, misses: int, hitRate: float, memoryUsedMB: float, memoryFreeMB: float},
	 *     redis: array{configured: bool, distributed: string, locking: string},
	 *     memcache: array{local: string, distributed: string, locking: string}
	 * }
	 */
	public function getCachingInfo(): array {
		return [
			'opcache' => $this->opcacheInfo(),
			'apcu' => $this->apcuInfo(),
			'redis' => $this->redisInfo(),
			'memcache' => [
				'local' => $this->shortClassName($this->config->getSystemValue('memcache.local', '')),
				'distributed' => $this->shortClassName($this->config->getSystemValue('memcache.distributed', '')),
				'locking' => $this->shortClassName($this->config->getSystemValue('memcache.locking', '')),
			],
		];
	}

	/**
	 * @return array{enabled: bool, hits: int, misses: int, hitRate: float, memoryUsedMB: float, memoryFreeMB: float, cachedScripts: int}
	 */
	private function opcacheInfo(): array {
		if (!extension_loaded('Zend OPcache') || !function_exists('opcache_get_status')) {
			return ['enabled' => false, 'hits' => 0, 'misses' => 0, 'hitRate' => 0.0, 'memoryUsedMB' => 0.0, 'memoryFreeMB' => 0.0, 'cachedScripts' => 0];
		}
		$status = @opcache_get_status(false);
		if (!is_array($status)) {
			return ['enabled' => false, 'hits' => 0, 'misses' => 0, 'hitRate' => 0.0, 'memoryUsedMB' => 0.0, 'memoryFreeMB' => 0.0, 'cachedScripts' => 0];
		}
		$stats = $status['opcache_statistics'] ?? [];
		$mem = $status['memory_usage'] ?? [];
		$hits = (int)($stats['hits'] ?? 0);
		$misses = (int)($stats['misses'] ?? 0);
		$total = $hits + $misses;
		return [
			'enabled' => (bool)($status['opcache_enabled'] ?? false),
			'hits' => $hits,
			'misses' => $misses,
			'hitRate' => $total > 0 ? ($hits / $total) * 100 : 0.0,
			'memoryUsedMB' => isset($mem['used_memory']) ? round($mem['used_memory'] / (1024 * 1024), 2) : 0.0,
			'memoryFreeMB' => isset($mem['free_memory']) ? round($mem['free_memory'] / (1024 * 1024), 2) : 0.0,
			'cachedScripts' => (int)($stats['num_cached_scripts'] ?? 0),
		];
	}

	/**
	 * @return array{enabled: bool, hits: int, misses: int, hitRate: float, memoryUsedMB: float, memoryFreeMB: float}
	 */
	private function apcuInfo(): array {
		if (!extension_loaded('apcu') || !function_exists('apcu_cache_info')) {
			return ['enabled' => false, 'hits' => 0, 'misses' => 0, 'hitRate' => 0.0, 'memoryUsedMB' => 0.0, 'memoryFreeMB' => 0.0];
		}
		$cache = @apcu_cache_info(true);
		$sma = function_exists('apcu_sma_info') ? @apcu_sma_info(true) : false;
		if (!is_array($cache)) {
			return ['enabled' => false, 'hits' => 0, 'misses' => 0, 'hitRate' => 0.0, 'memoryUsedMB' => 0.0, 'memoryFreeMB' => 0.0];
		}
		$hits = (int)($cache['num_hits'] ?? 0);
		$misses = (int)($cache['num_misses'] ?? 0);
		$total = $hits + $misses;
		$used = is_array($sma) && isset($sma['seg_size'], $sma['avail_mem']) ? (int)$sma['seg_size'] - (int)$sma['avail_mem'] : 0;
		$free = is_array($sma) && isset($sma['avail_mem']) ? (int)$sma['avail_mem'] : 0;
		return [
			'enabled' => true,
			'hits' => $hits,
			'misses' => $misses,
			'hitRate' => $total > 0 ? ($hits / $total) * 100 : 0.0,
			'memoryUsedMB' => round($used / (1024 * 1024), 2),
			'memoryFreeMB' => round($free / (1024 * 1024), 2),
		];
	}

	/**
	 * @return array{configured: bool, distributed: string, locking: string}
	 */
	private function redisInfo(): array {
		$distributed = (string)$this->config->getSystemValue('memcache.distributed', '');
		$locking = (string)$this->config->getSystemValue('memcache.locking', '');
		$usingRedis = stripos($distributed, 'Redis') !== false || stripos($locking, 'Redis') !== false;
		return [
			'configured' => $usingRedis,
			'distributed' => $this->shortClassName($distributed),
			'locking' => $this->shortClassName($locking),
		];
	}

	private function shortClassName(string $cls): string {
		if ($cls === '') {
			return '';
		}
		$parts = explode('\\', $cls);
		return end($parts) ?: $cls;
	}
}
