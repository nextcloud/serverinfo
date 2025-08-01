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

		return $data;
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
		];

		if (function_exists('shell_exec')) {
			$result['gateway'] = shell_exec('ip route | awk \'/default/ { print $3 }\'');
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
		$pattern = '/^(?<Filesystem>[\S]+)\s*(?<Type>[\S]+)\s*(?<Blocks>\d+)\s*(?<Used>\d+)\s*(?<Available>\d+)\s*(?<Capacity>\d+%)\s*(?<Mounted>[\w\/\-\.]+)$/m';

		$result = preg_match_all($pattern, $disks, $matches);
		if ($result === 0 || $result === false) {
			return $data;
		}

		foreach ($matches['Filesystem'] as $i => $filesystem) {
			if (in_array($matches['Type'][$i], ['tmpfs', 'devtmpfs', 'squashfs', 'overlay'], false)) {
				continue;
			} elseif (in_array($matches['Mounted'][$i], ['/etc/hostname', '/etc/hosts'], false)) {
				continue;
			}

			$disk = new Disk();
			$disk->setDevice($filesystem);
			$disk->setFs($matches['Type'][$i]);
			$used = (int)((int)$matches['Blocks'][$i] - (int)$matches['Available'][$i]);
			$disk->setUsed((int)ceil($used / 1024));
			$disk->setAvailable((int)floor((int)$matches['Available'][$i] / 1024));
			$disk->setPercent(round(($used * 100 / (int)$matches['Blocks'][$i]), 2) . '%');
			$disk->setMount($matches['Mounted'][$i]);

			$data[] = $disk;
		}

		return $data;
	}

	#[\Override]
	public function getThermalZones(): array {
		$data = [];

		$zones = glob('/sys/class/thermal/thermal_zone*');
		if ($zones === false) {
			return $data;
		}

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
