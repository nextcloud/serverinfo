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

use bantu\IniGetWrapper\IniGetWrapper;
use OCA\ServerInfo\OperatingSystems\DefaultOs;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

class Os {

	/** @var IClientService */
	protected $clientService;

	/** @var IConfig */
	protected $config;

	/** @var IDBConnection */
	protected $connection;

	/** @var IniGetWrapper */
	protected $phpIni;

	/** @var \OCP\IL10N */
	protected $l;

	/** @var */
	protected $backend;

	/**
	 * Os constructor.
	 *
	 * @param IClientService $clientService
	 * @param IConfig $config
	 * @param IDBConnection $connection
	 * @param IniGetWrapper $phpIni
	 * @param IL10N $l
	 */
	public function __construct(IClientService $clientService,
								IConfig $config,
								IDBConnection $connection,
								IniGetWrapper $phpIni,
								IL10N $l) {
		$this->clientService = $clientService;
		$this->config        = $config;
		$this->connection    = $connection;
		$this->phpIni        = $phpIni;
		$this->l             = $l;
		$this->backend = new DefaultOs();
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

	/**
	 * Get memory will return a list key => value where all values are in bytes.
	 * [MemTotal => 0, MemFree => 0, MemAvailable => 0, SwapTotal => 0, SwapFree => 0].
	 *
	 * @return array
	 */
	public function getMemory(): array {
		return $this->backend->getMemory();
	}

	/**
	 * Get name of the processor
	 *
	 * @return string
	 */
	public function getCPUName(): string {
		return $this->backend->getCPUName();
	}

	/**
	 * @return string
	 */
	public function getTime() {
		$data = $this->backend->getTime();
		return $data;
	}

	/**
	 * Get the total number of seconds the system has been up
	 *
	 * @return int
	 */
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

	/**
	 * Get diskInfo will return a list of disks. Used and Available in bytes.
	 *
	 * [
	 * 	[device => /dev/mapper/homestead--vg-root, fs => ext4, used => 6205468, available => 47321220, percent => 12%, mount => /]
	 * ]
	 *
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
		$disks = $this->backend->getDiskInfo();

		foreach ($disks as $disk) {
			$data[] = [
				round($disk['used'] / 1024 / 1024 / 1024, 1),
				round($disk['available'] / 1024 / 1024 / 1024, 1)
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
