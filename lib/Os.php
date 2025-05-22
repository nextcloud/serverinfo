<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\ServerInfo;

use OCA\ServerInfo\OperatingSystems\Dummy;
use OCA\ServerInfo\OperatingSystems\FreeBSD;
use OCA\ServerInfo\OperatingSystems\IOperatingSystem;
use OCA\ServerInfo\OperatingSystems\Linux;
use OCA\ServerInfo\Resources\CPU;
use OCA\ServerInfo\Resources\Memory;
use OCP\IConfig;

class Os implements IOperatingSystem {
	private IOperatingSystem $backend;

	public function __construct(IConfig $config) {
		$restrictedMode = $config->getAppValue('serverinfo', 'restricted_mode', 'no') === 'yes';
		$this->backend = $this->getBackend($restrictedMode ? 'Dummy' : PHP_OS);
	}

	#[\Override]
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

	#[\Override]
	public function getCPU(): CPU {
		return $this->backend->getCPU();
	}

	#[\Override]
	public function getMemory(): Memory {
		return $this->backend->getMemory();
	}

	#[\Override]
	public function getTime(): string {
		return $this->backend->getTime();
	}

	#[\Override]
	public function getUptime(): int {
		return $this->backend->getUptime();
	}

	#[\Override]
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

	#[\Override]
	public function getNetworkInfo(): array {
		return $this->backend->getNetworkInfo();
	}

	#[\Override]
	public function getNetworkInterfaces(): array {
		return $this->backend->getNetworkInterfaces();
	}

	#[\Override]
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
