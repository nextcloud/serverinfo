<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo;

use bantu\IniGetWrapper\IniGetWrapper;

/**
 * Class php
 *
 * @package OCA\Survey_Client\Categories
 */
class PhpStatistics {
	protected IniGetWrapper $phpIni;

	public function __construct(IniGetWrapper $phpIni) {
		$this->phpIni = $phpIni;
	}

	public function getPhpStatistics(): array {
		return [
			'version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
			'memory_limit' => $this->phpIni->getBytes('memory_limit'),
			'max_execution_time' => $this->phpIni->getNumeric('max_execution_time'),
			'upload_max_filesize' => $this->phpIni->getBytes('upload_max_filesize'),
			'opcache_revalidate_freq' => $this->phpIni->getNumeric('opcache.revalidate_freq'),
			// NOTE: If access to add'l OPcache *config* parameters is desired consider
			//   implementing a getOPcacheConfig() wrapper for PHP's opcache_get_configuration()
			//   like we do for PHP's opcache_get_status() already below
			'opcache' => $this->getOPcacheStatus(),
			'apcu' => $this->getAPCuStatus(),
			'extensions' => $this->getLoadedPhpExtensions(),
		];
	}

	/**
	 * Get status information about the cache from the OPcache extension
	 *
	 * @return array with an array of state information about the cache instance
	 */
	protected function getOPcacheStatus(): array {
		// Test if the OPcache module is installed
		if (!extension_loaded('Zend OPcache')) {
			// module not loaded, returning back empty array to prevent any errors on JS side.
			return [];
		}

		// get status information about the cache
		$status = (function_exists('opcache_get_status')) ? opcache_get_status(false) : false;

		if ($status === false) {
			// no array, returning back empty array to prevent any errors on JS side.
			$status = [];
		}

		return $status;
	}

	/**
	 * Get status information about the cache from the APCu extension
	 *
	 * @return array with an array of state information about the cache instance
	 */
	protected function getAPCuStatus(): array {
		// Test if the APCu module is installed
		if (!extension_loaded('apcu')) {
			// module not loaded, returning back empty array to prevent any errors on JS side.
			return [];
		}

		// get cached information from APCu data store
		$cacheInfo = apcu_cache_info(true);

		// get APCu Shared Memory Allocation information
		$smaInfo = apcu_sma_info(true);

		if ($cacheInfo === false) {
			// no array, returning back N/A to prevent any errors on JS side.
			$cacheInfo = 'N/A';
		}

		if ($smaInfo === false) {
			// no array, returning back N/A to prevent any errors on JS side.
			$smaInfo = 'N/A';
		}

		// return the array
		return [
			'cache' => $cacheInfo,
			'sma' => $smaInfo,
		];
	}

	/**
	 * Get all loaded php extensions
	 *
	 * @return array of strings with the names of the loaded extensions
	 */
	protected function getLoadedPhpExtensions(): ?array {
		return (function_exists('get_loaded_extensions') ? get_loaded_extensions() : null);
	}
}
