<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\ServerInfo\OperatingSystems;

use OCA\ServerInfo\Resources\CPU;
use OCA\ServerInfo\Resources\Disk;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\Resources\NetInterface;
use OCA\ServerInfo\Resources\ThermalZone;
use RuntimeException;

class Linux implements IOperatingSystem {
	private const AF_INET = 2;
	private const AF_INET6 = 10;

	/** Mount point of both the cgroup v2 unified hierarchy and the v1 controllers. */
	private const CGROUP_ROOT = '/sys/fs/cgroup';

	#[\Override]
	public function supported(): bool {
		return true;
	}

	#[\Override]
	public function getCPU(): CPU {
		$default = new CPU('Unknown Processor', 1);

		try {
			$cpuinfo = $this->readContent('/proc/cpuinfo');
		} catch (RuntimeException) {
			return $default;
		}

		$matches = [];

		if (str_contains($cpuinfo, 'Raspberry Pi')) {
			$pattern = '/Model\s+:\s(.+)/';
		} elseif (str_contains($cpuinfo, 'PowerNV') || str_contains($cpuinfo, 'CHRP IBM pSeries')) {
			$pattern = '/cpu\s+:\s+(.+)/';
		} else {
			$pattern = '/model name\s:\s(.+)/';
		}

		$result = preg_match_all($pattern, $cpuinfo, $matches);
		if ($result === 0 || $result === false) {
			return $default;
		}

		return new CPU($matches[1][0], substr_count($cpuinfo, "processor\t"));
	}

	#[\Override]
	public function getMemory(): Memory {
		$data = new Memory();

		try {
			$meminfo = $this->readContent('/proc/meminfo');
		} catch (RuntimeException $e) {
			return $data;
		}

		$matches = [];
		$pattern = '/(?<Key>(?:MemTotal|MemFree|MemAvailable|SwapTotal|SwapFree)+):\s+(?<Value>\d+)\s+(?<Unit>\w{2})/';

		$result = preg_match_all($pattern, $meminfo, $matches);
		if ($result === 0 || $result === false) {
			return $data;
		}

		foreach ($matches['Key'] as $i => $key) {
			// Value is always in KB: https://github.com/torvalds/linux/blob/c70672d8d316ebd46ea447effadfe57ab7a30a50/fs/proc/meminfo.c#L58-L60
			$value = (int)((int)$matches['Value'][$i] / 1024);

			switch ($key) {
				case 'MemTotal':
					$data->setMemTotal($value);
					break;
				case 'MemFree':
					$data->setMemFree($value);
					break;
				case 'MemAvailable':
					$data->setMemAvailable($value);
					break;
				case 'SwapTotal':
					$data->setSwapTotal($value);
					break;
				case 'SwapFree':
					$data->setSwapFree($value);
					break;
			}
		}

		$this->applyCgroupMemory($data);

		return $data;
	}

	/**
	 * A container still sees the host's /proc/meminfo, so a memory limited
	 * cgroup would be reported as if the whole machine were available to
	 * Nextcloud. Where a cgroup limits us further, report that instead.
	 *
	 * Tries the cgroup v2 unified hierarchy first and falls back to v1.
	 */
	private function applyCgroupMemory(Memory $data): void {
		if ($this->applyCgroupV2Memory($data)) {
			return;
		}

		$this->applyCgroupV1Memory($data);
	}

	/**
	 * @return bool whether a limit was found and applied
	 */
	private function applyCgroupV2Memory(Memory $data): bool {
		$limit = $this->readCgroupBytes(self::CGROUP_ROOT . '/memory.max');
		if ($limit === null || !$this->isConstrainingLimit($limit, $data->getMemTotal())) {
			return false;
		}

		$current = $this->readCgroupBytes(self::CGROUP_ROOT . '/memory.current');
		if ($current === null) {
			return false;
		}

		$cache = $this->readCgroupStat(self::CGROUP_ROOT . '/memory.stat', 'inactive_file') ?? 0;
		$this->setCgroupMemory($data, $limit, $current, $cache);

		$swapLimit = $this->readCgroupBytes(self::CGROUP_ROOT . '/memory.swap.max');
		$swapCurrent = $this->readCgroupBytes(self::CGROUP_ROOT . '/memory.swap.current');
		if ($swapLimit !== null && $swapCurrent !== null) {
			$data->setSwapTotal($this->bytesToMebibytes($swapLimit));
			$data->setSwapFree($this->bytesToMebibytes(max(0, $swapLimit - $swapCurrent)));
		}

		return true;
	}

	private function applyCgroupV1Memory(Memory $data): void {
		$root = self::CGROUP_ROOT . '/memory';

		$limit = $this->readCgroupBytes($root . '/memory.limit_in_bytes');
		if ($limit === null || !$this->isConstrainingLimit($limit, $data->getMemTotal())) {
			return;
		}

		$current = $this->readCgroupBytes($root . '/memory.usage_in_bytes');
		if ($current === null) {
			return;
		}

		$cache = $this->readCgroupStat($root . '/memory.stat', 'total_inactive_file') ?? 0;
		$this->setCgroupMemory($data, $limit, $current, $cache);

		// memsw accounts memory and swap together, so the swap share is whatever
		// is left once the memory limit is taken off.
		$memswLimit = $this->readCgroupBytes($root . '/memory.memsw.limit_in_bytes');
		$memswUsage = $this->readCgroupBytes($root . '/memory.memsw.usage_in_bytes');
		if ($memswLimit !== null && $memswUsage !== null && $memswLimit > $limit) {
			$swapTotal = $memswLimit - $limit;
			$swapUsed = max(0, $memswUsage - $current);
			$data->setSwapTotal($this->bytesToMebibytes($swapTotal));
			$data->setSwapFree($this->bytesToMebibytes(max(0, $swapTotal - $swapUsed)));
		}
	}

	/**
	 * Page cache is reclaimable, so it counts towards available memory the same
	 * way MemAvailable accounts for it in /proc/meminfo, while MemFree does not.
	 */
	private function setCgroupMemory(Memory $data, int $limit, int $current, int $cache): void {
		$used = max(0, $current - $cache);

		$data->setMemTotal($this->bytesToMebibytes($limit));
		$data->setMemFree($this->bytesToMebibytes(max(0, $limit - $current)));
		$data->setMemAvailable($this->bytesToMebibytes(max(0, $limit - $used)));
	}

	/**
	 * A cgroup without a memory limit reports "max" on v2 and a value near
	 * PHP_INT_MAX on v1. Either way a limit that is not below the host's memory
	 * adds nothing to what /proc/meminfo has already told us.
	 */
	private function isConstrainingLimit(int $limitBytes, int $hostTotal): bool {
		if ($hostTotal <= 0) {
			// The host total is unknown, so the cgroup is the better source.
			return true;
		}

		return $limitBytes < $hostTotal * 1024 * 1024;
	}

	/**
	 * Read a cgroup file holding a single byte count.
	 *
	 * @return int|null null when the file is missing or holds no plain number,
	 *                  which is how cgroup v2 spells "no limit" ("max").
	 */
	private function readCgroupBytes(string $filename): ?int {
		try {
			$value = $this->readContent($filename);
		} catch (RuntimeException) {
			return null;
		}

		if (preg_match('/^\d+$/', $value) !== 1) {
			return null;
		}

		return (int)$value;
	}

	/**
	 * Read a single counter out of a cgroup memory.stat file.
	 */
	private function readCgroupStat(string $filename, string $key): ?int {
		try {
			$stat = $this->readContent($filename);
		} catch (RuntimeException) {
			return null;
		}

		if (preg_match('/^' . preg_quote($key, '/') . ' (\d+)$/m', $stat, $matches) !== 1) {
			return null;
		}

		return (int)$matches[1];
	}

	private function bytesToMebibytes(int $bytes): int {
		return intdiv($bytes, 1024 * 1024);
	}

	#[\Override]
	public function getTime(): string {
		try {
			return $this->executeCommand('date');
		} catch (RuntimeException $e) {
			return '';
		}
	}

	#[\Override]
	public function getUptime(): int {
		$data = -1;

		try {
			$uptime = $this->readContent('/proc/uptime');
		} catch (RuntimeException $e) {
			return $data;
		}

		[$uptimeInSeconds,] = array_map('intval', explode(' ', $uptime));

		return $uptimeInSeconds;
	}

	#[\Override]
	public function getNetworkInfo(): array {
		$result = [
			'gateway' => '',
			'hostname' => \gethostname(),
			'dns' => '',
		];

		if (function_exists('shell_exec')) {
			$result['gateway'] = shell_exec('ip route | awk \'/default/ { print $3 }\'');
		}

		try {
			$resolvConf = $this->readContent('/etc/resolv.conf');
			if (preg_match_all('/^\s*nameserver\s+(\S+)/m', $resolvConf, $matches)) {
				$result['dns'] = implode(', ', array_unique($matches[1]));
			}
		} catch (RuntimeException) {
			// okay
		}

		return $result;
	}

	#[\Override]
	public function getNetworkInterfaces(): array {
		$data = [];

		try {
			$interfaces = $this->getNetInterfaces();
		} catch (RuntimeException) {
			return $data;
		}

		foreach ($interfaces as $interfaceName => $interface) {
			$netInterface = new NetInterface($interfaceName, $interface['up']);
			$data[] = $netInterface;

			foreach ($interface['unicast'] as $unicast) {
				if (isset($unicast['family'])) {
					if ($unicast['family'] === self::AF_INET) {
						$netInterface->addIPv4($unicast['address']);
					}
					if ($unicast['family'] === self::AF_INET6) {
						$netInterface->addIPv6($unicast['address']);
					}
				}
			}

			if ($netInterface->isLoopback()) {
				continue;
			}

			$interfacePath = '/sys/class/net/' . $interfaceName;

			try {
				$netInterface->setMAC($this->readContent($interfacePath . '/address'));

				$speed = (int)$this->readContent($interfacePath . '/speed');
				if ($speed >= 1000) {
					$netInterface->setSpeed($speed / 1000 . ' Gbps');
				} else {
					$netInterface->setSpeed($speed . ' Mbps');
				}

				$netInterface->setDuplex($this->readContent($interfacePath . '/duplex'));
			} catch (RuntimeException $e) {
				// unable to read interface data
			}
		}

		return $data;
	}

	#[\Override]
	public function getDiskInfo(): array {
		$data = [];

		try {
			$disks = $this->executeCommand('df -TPk');
		} catch (RuntimeException $e) {
			return $data;
		}

		$matches = [];
		// `df -P` prints one record per line with the mount point last, so the mount
		// point is everything after the capacity column. Used and Capacity are loose
		// because nothing reads them and `df` prints "-" in those two columns when
		// it has no usage numbers.
		$pattern = '/^(?<Filesystem>\S+)[ \t]+(?<Type>\S+)[ \t]+(?<Blocks>\d+)[ \t]+(?<Used>\S+)[ \t]+(?<Available>\d+)[ \t]+(?<Capacity>\S+)[ \t]+(?<Mounted>\S.*?)[ \t\r]*$/m';

		$result = preg_match_all($pattern, $disks, $matches);
		if ($result === 0 || $result === false) {
			return $data;
		}

		foreach ($matches['Filesystem'] as $i => $filesystem) {
			if (in_array($matches['Type'][$i], ['tmpfs', 'devtmpfs', 'squashfs', 'overlay', 'efivarfs'], false)) {
				continue;
			} elseif (in_array($matches['Mounted'][$i], ['/etc/hostname', '/etc/hosts'], false)) {
				continue;
			}

			$blocks = (int)$matches['Blocks'][$i];
			$available = (int)$matches['Available'][$i];
			// Clamped: more available than total would give a negative size
			$used = max(0, $blocks - $available);

			$disk = new Disk();
			$disk->setDevice($filesystem);
			$disk->setFs($matches['Type'][$i]);
			$disk->setUsed((int)ceil($used / 1024));
			$disk->setAvailable((int)floor($available / 1024));
			// busybox df lists zero-block filesystems, coreutils does not
			$disk->setPercent($blocks > 0 ? (string)round($used * 100 / $blocks, 2) . '%' : '0%');
			$disk->setMount($matches['Mounted'][$i]);

			$data[] = $disk;
		}

		return $data;
	}

	#[\Override]
	public function getThermalZones(): array {
		return array_merge($this->readHwmonSensors(), $this->readThermalZoneSensors());
	}

	/**
	 * Read temperatures from the hwmon sensor interface (/sys/class/hwmon).
	 *
	 * @return ThermalZone[]
	 */
	protected function readHwmonSensors(): array {
		$data = [];

		$drivers = glob('/sys/class/hwmon/hwmon*') ?: [];
		foreach ($drivers as $driver) {
			try {
				$name = $this->readContent($driver . '/name');
			} catch (RuntimeException) {
				// driver without a name, skip it
				continue;
			}

			$zones = glob($driver . '/temp*_label') ?: [];
			foreach ($zones as $zone) {
				try {
					$type = $name . ' ' . $this->readContent($zone);
					$temp = (float)((int)$this->readContent(str_replace('_label', '_input', $zone)) / 1000);
					$data[] = new ThermalZone(md5($zone), $type, $temp);
				} catch (RuntimeException) {
					// unable to read sensor
				}
			}
		}

		return $data;
	}

	/**
	 * Read temperatures from the thermal zone interface (/sys/class/thermal).
	 *
	 * @return ThermalZone[]
	 */
	protected function readThermalZoneSensors(): array {
		$data = [];

		$zones = glob('/sys/class/thermal/thermal_zone*') ?: [];
		foreach ($zones as $zone) {
			try {
				$type = $this->readContent($zone . '/type');
				$temp = (float)((int)($this->readContent($zone . '/temp')) / 1000);
				$data[] = new ThermalZone(md5($zone), $type, $temp);
			} catch (RuntimeException) {
				// unable to read thermal zone
			}
		}

		return $data;
	}

	/**
	 * @throws RuntimeException
	 */
	protected function readContent(string $filename): string {
		$data = @file_get_contents($filename);
		if ($data === false || $data === '') {
			throw new RuntimeException('Unable to read: "' . $filename . '"');
		}
		return trim($data);
	}

	/**
	 * Execute a command with shell_exec.
	 *
	 * The command will be escaped with escapeshellcmd.
	 *
	 * @throws RuntimeException if shell_exec is unavailable, the command failed or an empty response.
	 */
	protected function executeCommand(string $command): string {
		if (function_exists('shell_exec') === false) {
			throw new RuntimeException('shell_exec unavailable');
		}

		$output = shell_exec(escapeshellcmd($command));
		if ($output === false || $output === null || $output === '') {
			throw new RuntimeException('No output for command: "' . $command . '"');
		}

		return $output;
	}

	/**
	 * Wrapper for net_get_interfaces
	 *
	 * @throws RuntimeException
	 */
	protected function getNetInterfaces(): array {
		$data = net_get_interfaces();
		if ($data === false) {
			throw new RuntimeException('Unable to get network interfaces');
		}
		return $data;
	}
}
