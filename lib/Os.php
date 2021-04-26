<?php

declare(strict_types=1);

/**
 * @author Frank Karlitschek <frank@nextcloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\ServerInfo;

use OCA\ServerInfo\OperatingSystems\DefaultOs;
use OCA\ServerInfo\OperatingSystems\FreeBSD;
use OCA\ServerInfo\OperatingSystems\IOperatingSystem;
use OCA\ServerInfo\Resources\Memory;

class Os implements IOperatingSystem {

	/** @var IOperatingSystem */
	protected $backend;

	/**
	 * Os constructor.
	 */
	public function __construct() {
		if (PHP_OS === 'FreeBSD') {
			$this->backend = new FreeBSD();
		} else {
			$this->backend = new DefaultOs();
		}
	}

	/**
	 * @return bool
	 */
	public function supported(): bool {
		$data = $this->backend->supported();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getHostname(): string {
		return (string)gethostname();
	}

	/**
	 * Get name of the operating system.
	 *
	 * @return string
	 */
	public function getOSName(): string {
		return PHP_OS . ' ' . php_uname('r') . ' ' . php_uname('m');
	}

	/**
	 * @return Memory
	 */
	public function getMemory(): Memory {
		return $this->backend->getMemory();
	}

	/**
	 * @return string
	 */
	public function getCpuName(): string {
		return $this->backend->getCpuName();
	}

	/**
	 * @return string
	 */
	public function getTime(): string {
		$data = $this->backend->getTime();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getUptime(): int {
		return $this->backend->getUptime();
	}

	/**
	 * @return array
	 */
	public function getDiskInfo(): array {
		return $this->backend->getDiskInfo();
	}

	/**
	 * Get diskdata will return a numerical list with two elements for each disk (used and available) where all values are in gigabyte.
	 * [
	 *        [used => 0, available => 0],
	 *        [used => 0, available => 0],
	 * ]
	 *
	 * @return array
	 */
	public function getDiskData(): array {
		$data = [];

		foreach ($this->backend->getDiskInfo() as $disk) {
			$data[] = [
				round($disk->getUsed() / 1024 , 1),
				round($disk->getAvailable() / 1024, 1)
			];
		}

		return $data;
	}

	/**
	 * @return array
	 */
	public function getNetworkInfo(): array {
		$data = $this->backend->getNetworkInfo();
		return $data;
	}

	/**
	 * @return array
	 */
	public function getNetworkInterfaces(): array {
		$data = $this->backend->getNetworkInterfaces();
		return $data;
	}
}
