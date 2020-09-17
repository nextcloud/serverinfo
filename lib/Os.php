<?php
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

	/** @var */
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
	public function supported() {
		$data = $this->backend->supported();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getHostname() {
		return (string)gethostname();
	}

	/**
	 * @return string
	 */
	public function getOSName(): string {
		return PHP_OS . ' ' . php_uname('r') . ' ' . php_uname('m');
	}

	public function getMemory(): Memory {
		return $this->backend->getMemory();
	}

	public function getCpuName(): string {
		return $this->backend->getCpuName();
	}

	/**
	 * @return string
	 */
	public function getTime() {
		$data = $this->backend->getTime();
		return $data;
	}

	public function getUptime(): int {
		return $this->backend->getUptime();
	}

	/**
	 * @return string
	 */
	public function getTimeServers() {
		$data = $this->backend->getTimeServers();
		return explode("\n", $data);
	}

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
	 * @return string
	 */
	public function getNetworkInfo() {
		$data = $this->backend->getNetworkInfo();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getNetworkInterfaces() {
		$data = $this->backend->getNetworkInterfaces();
		return $data;
	}
}
