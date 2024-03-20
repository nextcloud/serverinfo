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

use OCA\ServerInfo\OperatingSystems\Dummy;
use OCA\ServerInfo\OperatingSystems\FreeBSD;
use OCA\ServerInfo\OperatingSystems\IOperatingSystem;
use OCA\ServerInfo\OperatingSystems\Linux;
use OCA\ServerInfo\Resources\Memory;
use OCP\IConfig;

class Os implements IOperatingSystem {
	private IOperatingSystem $backend;
	private IConfig $config;

	public function __construct(IConfig $config) {
		$restrictedMode = $config->getAppValue('serverinfo', 'restricted_mode', 'no') === 'yes';
		$this->backend = $this->getBackend($restrictedMode ? 'Dummy' : PHP_OS);
		$this->config = $config;
	}

	public function supported(): bool {
		return $this->backend->supported();
	}

	public function getHostname(): string {
		return (string)gethostname();
	}

	/**
	 * Get name of the operating system.
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

	public function getTime(): string {
		return $this->backend->getTime();
	}

	public function getUptime(): int {
		return $this->backend->getUptime();
	}

	public function getDiskInfo(): array {
		$disks = $this->backend->getDiskInfo();
		$filters = $this->config->getSystemValue('serverinfo_disk_filter_paths', null);
		if ($filters === null) {
			return $disks;
		}
		// apply defined filters to restrict the list of disks according to their mount point
		$filtered_disks = [];
		foreach (explode(':', $filters) as $filter) {
			// convert special filters to their corresponding paths
			switch ($filter) {
				case 'DOCROOT': $path = isset($_SERVER['SCRIPT_FILENAME']) ? dirname($_SERVER['SCRIPT_FILENAME']) : (isset(
$_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '');
					break;
				case 'DATADIR': $path = $this->config->getSystemValue('datadirectory', '');
					break;
				case 'TEMPDIR': $path = $this->config->getSystemValue('tempdirectory', '');
					break;
				case 'LOGSDIR': $path = dirname($this->config->getSystemValue('logfile', ''));
					break;
				default: $path = $filter;
			}
			// find the disk whose mount point contains the filtering path
			$find_index = null;
			$find_mount = '';
			foreach ($disks as $index => $disk) {
				$mount = $disk->getMount();
				if ((strncmp($path, $mount, strlen($mount)) === 0) && (strlen($mount) >= strlen($find_mount))) {
					$find_mount = $mount;
					$find_index = $index;
				}
			}
			if ($find_index !== null) {
				$filtered_disks[ $find_index ] = $disks[ $find_index ];
			}
		}
		return $filtered_disks;
	}

	/**
	 * Get diskdata will return a numerical list with two elements for each disk (used and available) where all values are in gigabyte.
	 * [
	 *        [used => 0, available => 0],
	 *        [used => 0, available => 0],
	 * ]
	 *
	 * @return array<array-key, array>
	 */
	public function getDiskData(): array {
		$data = [];

		foreach ($this->backend->getDiskInfo() as $disk) {
			$data[] = [
				round($disk->getUsed() / 1024, 1),
				round($disk->getAvailable() / 1024, 1)
			];
		}

		return $data;
	}

	public function getNetworkInfo(): array {
		return $this->backend->getNetworkInfo();
	}

	public function getNetworkInterfaces(): array {
		return $this->backend->getNetworkInterfaces();
	}

	public function getThermalZones(): array {
		return $this->backend->getThermalZones();
	}

	private function getBackend(string $os): IOperatingSystem {
		return match ($os) {
			'Linux' => new Linux(),
			'FreeBSD' => new FreeBSD(),
			default => new Dummy(),
		};
	}
}
