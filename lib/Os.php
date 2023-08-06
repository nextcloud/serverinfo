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

	public function __construct(IConfig $config) {
		$restrictedMode = $config->getAppValue('serverinfo', 'restricted_mode', 'no') === 'yes';
		$this->backend = $this->getBackend($restrictedMode ? 'Dummy' : PHP_OS);
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
		return $this->backend->getDiskInfo();
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
