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
 */
class Php {
	public function __construct(
		private IniGetWrapper $iniGetWrapper,
	) {
	}

	/**
	 * @return array{
	 *     version: string,
	 *     sapi: string,
	 *     memoryLimit: int,
	 *     maxExecutionTime: int,
	 *     uploadMaxFilesize: int,
	 *     postMaxSize: int,
	 *     extensions: list<string>|null
	 * }
	 */
	public function getData(): array {
		return [
			'version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
			'sapi' => PHP_SAPI,
			'memoryLimit' => (int)$this->iniGetWrapper->getBytes('memory_limit'),
			'maxExecutionTime' => (int)$this->iniGetWrapper->getNumeric('max_execution_time'),
			'uploadMaxFilesize' => (int)$this->iniGetWrapper->getBytes('upload_max_filesize'),
			'postMaxSize' => (int)$this->iniGetWrapper->getBytes('post_max_size'),
			'extensions' => $this->getLoadedPhpExtensions(),
		];
	}

	/**
	 * @return list<string>|null null if PHP forbids enumeration
	 */
	private function getLoadedPhpExtensions(): ?array {
		if (!function_exists('get_loaded_extensions')) {
			return null;
		}

		// `get_loaded_extensions(true)` returns Zend extensions (OPcache, Xdebug,
		// etc.) which are otherwise hidden from the regular call.
		$extensions = array_unique(array_map('strtolower', array_merge(
			get_loaded_extensions(false),
			get_loaded_extensions(true),
		)));
		natcasesort($extensions);

		return array_values($extensions);
	}
}
