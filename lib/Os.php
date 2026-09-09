<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\ServerInfo;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\OperatingSystems\Dummy;
use OCA\ServerInfo\OperatingSystems\FreeBSD;
use OCA\ServerInfo\OperatingSystems\IOperatingSystem;
use OCA\ServerInfo\OperatingSystems\Linux;
use OCA\ServerInfo\Resources\CPU;
use OCA\ServerInfo\Resources\Memory;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;

class Os implements IOperatingSystem {
	/** How long a measured boot time is trusted before it is checked again. */
	private const BOOT_TIME_TTL = 60;

	private IOperatingSystem $backend;

	public function __construct(
		IConfig $config,
		private IAppConfig $appConfig,
	) {
		$restrictedMode = $config->getAppValue('serverinfo', 'restricted_mode', 'no') === 'yes';
		$this->backend = $this->getBackend($restrictedMode ? 'Dummy' : PHP_OS);
	}

	/**
	 * The load average comes from sys_getloadavg() and needs nothing that
	 * getCPU() collects, which is why it does not go through it: getCPU() parses
	 * /proc/cpuinfo, or forks nproc, and this runs on a two-second poll.
	 *
	 * @return array{0: float, 1: float, 2: float}|false
	 */
	public function getAverageLoad(): array|false {
		return sys_getloadavg();
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
		$uptime = $this->backend->getUptime();
		if ($uptime >= 0) {
			return $uptime;
		}

		return $this->getSampledUptime();
	}

	#[\Override]
	public function sampleUptime(): int {
		return $this->backend->sampleUptime();
	}

	/**
	 * Sampling the uptime costs a process fork, and this is reached from a
	 * two-second poll, so the boot time it implies is remembered and only
	 * re-measured once a minute. In between the answer is still exact — it is
	 * wall-clock arithmetic on a constant — except for up to a minute after a
	 * reboot.
	 */
	private function getSampledUptime(): int {
		$now = time();
		$bootTime = $this->appConfig->getAppValueInt(ConfigLexicon::CACHED_BOOT_TIME);
		$sampledAt = $this->appConfig->getAppValueInt(ConfigLexicon::CACHED_BOOT_TIME_SAMPLED_AT);

		if ($bootTime > 0 && $now - $sampledAt < self::BOOT_TIME_TTL) {
			return max(0, $now - $bootTime);
		}

		$uptime = $this->backend->sampleUptime();
		if ($uptime < 0) {
			// Nothing new to learn, so keep answering from what is remembered.
			return $bootTime > 0 ? max(0, $now - $bootTime) : -1;
		}

		$this->appConfig->setAppValueInt(ConfigLexicon::CACHED_BOOT_TIME, $now - $uptime);
		$this->appConfig->setAppValueInt(ConfigLexicon::CACHED_BOOT_TIME_SAMPLED_AT, $now);

		return $uptime;
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
