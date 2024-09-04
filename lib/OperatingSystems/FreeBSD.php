<?php

declare(strict_types=1);

/**
 * @author Matthew Wener <matthew@wener.org>
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
 * along with this program.  If not, see <https://www.gnu.org/licenses/>
 *
 */

namespace OCA\ServerInfo\OperatingSystems;

use OCA\ServerInfo\Resources\Disk;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\Resources\NetInterface;
use RuntimeException;

class FreeBSD implements IOperatingSystem {
	private const AF_INET = 2;
	private const AF_INET6 = 28;

	public function supported(): bool {
		return false;
	}

	public function getMemory(): Memory {
		$data = new Memory();

		try {
			$swapinfo = $this->executeCommand('/usr/sbin/swapinfo -k');
		} catch (RuntimeException $e) {
			$swapinfo = '';
		}

		$matches = [];
		$pattern = '/(?>\/dev\/\S+)\s+(?>\d+)\s+(?<Used>\d+)\s+(?<Avail>\d+)\s+(?<Capacity>\d+)/';

		$result = preg_match_all($pattern, $swapinfo, $matches);
		if ($result !== 0) {
			$data->setSwapTotal((int)((int)array_sum($matches['Avail']) / 1024));
			$data->setSwapFree(($data->getSwapTotal() - (int)((int)array_sum($matches['Used']) / 1024)));
		}

		unset($matches, $result);

		try {
			$meminfo = $this->executeCommand('/sbin/sysctl -n hw.realmem hw.pagesize vm.stats.vm.v_inactive_count vm.stats.vm.v_cache_count vm.stats.vm.v_free_count');
		} catch (RuntimeException $e) {
			$meminfo = '';
		}

		$lines = array_map('intval', explode("\n", $meminfo));
		if (count($lines) > 4) {
			$data->setMemTotal((int)($lines[0] / 1024 / 1024));
			$data->setMemAvailable((int)(($lines[1] * ($lines[2] + $lines[3] + $lines[4])) / 1024 / 1024));
		}

		unset($lines);

		return $data;
	}

	public function getCpuName(): string {
		$data = 'Unknown Processor';

		try {
			$model = $this->executeCommand('/sbin/sysctl -n hw.model');
			$threads = $this->executeCommand('/sbin/sysctl -n kern.smp.cpus');

			if ((int)$threads === 1) {
				$data = $model . ' (1 thread)';
			} else {
				$data = $model . ' (' . $threads . ' threads)';
			}
		} catch (RuntimeException $e) {
			return $data;
		}
		return $data;
	}

	public function getTime(): string {
		try {
			return $this->executeCommand('date');
		} catch (RuntimeException $e) {
			return '';
		}
	}

	public function getUptime(): int {
		$uptime = -1;

		try {
			$shell_boot = $this->executeCommand('/sbin/sysctl -n kern.boottime');
			preg_match("/[\d]+/", $shell_boot, $boottime);
			$time = $this->executeCommand('date +%s');
			$uptime = (int)$time - (int)$boottime[0];
		} catch (RuntimeException $e) {
			return $uptime;
		}
		return $uptime;
	}

	public function getNetworkInfo(): array {
		$result = [];
		$result['hostname'] = \gethostname();

		try {
			$dns = $this->executeCommand('cat /etc/resolv.conf 2>/dev/null');
			preg_match_all("/(?<=^nameserver ).\S*/m", $dns, $matches);
			$alldns = implode(' ', $matches[0]);
			$result['dns'] = $alldns;
			$netstat = $this->executeCommand('netstat -rn');
			preg_match_all("/(?<=^default)\s*[0-9a-fA-f\.:]+/m", $netstat, $gw);
			if (count($gw[0]) > 0) {
				$result['gateway'] = implode(", ", array_map("trim", $gw[0]));
			} else {
				$result['gateway'] = '';
			}
		} catch (RuntimeException $e) {
			return $result;
		}
		return $result;
	}

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
				if ($unicast['family'] === self::AF_INET) {
					$netInterface->addIPv4($unicast['address']);
				}
				if ($unicast['family'] === self::AF_INET6) {
					$netInterface->addIPv6($unicast['address']);
				}
			}

			if ($netInterface->isLoopback()) {
				continue;
			}

			try {
				$details = $this->executeCommand('/sbin/ifconfig ' . $interfaceName);
			} catch (RuntimeException $e) {
				continue;
			}

			preg_match("/(?<=ether ).*/m", $details, $mac);
			if (isset($mac[0])) {
				$netInterface->setMAC($mac[0]);
			}

			preg_match("/\b[0-9].*?(?=base)/m", $details, $speed);
			if (isset($speed[0])) {
				if (substr($speed[0], -1) === 'G') {
					$netInterface->setSpeed(rtrim($speed[0], 'G') . ' Gbps');
				} else {
					$netInterface->setSpeed($speed[0] . ' Mbps');
				}
			}

			preg_match("/(?<=\<).*(?=-)/m", $details, $duplex);
			if (isset($duplex[0])) {
				$netInterface->setDuplex($duplex[0]);
			}

			unset($mac, $speed, $duplex);
		}

		return $data;
	}

	public function getDiskInfo(): array {
		$data = [];

		try {
			$disks = $this->executeCommand('df -TPk');
		} catch (RuntimeException $e) {
			return $data;
		}

		$matches = [];
		$pattern = '/^(?<Filesystem>[\S]+)\s*(?<Type>[\S]+)\s*(?<Blocks>\d+)\s*(?<Used>\d+)\s*(?<Available>\d+)\s*(?<Capacity>\d+%)\s*(?<Mounted>[\w\/-]+)$/m';

		$result = preg_match_all($pattern, $disks, $matches);
		if ($result === 0 || $result === false) {
			return $data;
		}

		$excluded = ['devfs', 'fdescfs', 'tmpfs', 'devtmpfs', 'procfs', 'linprocfs', 'linsysfs'];
		foreach ($matches['Filesystem'] as $i => $filesystem) {
			if (in_array($matches['Type'][$i], $excluded, false)) {
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

	public function getThermalZones(): array {
		return [];
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
