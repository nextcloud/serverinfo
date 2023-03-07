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

class FreeBSD implements IOperatingSystem {
	public function supported(): bool {
		return false;
	}

	public function getMemory(): Memory {
		$data = new Memory();

		try {
			$swapinfo = $this->executeCommand('/usr/sbin/swapinfo -k');
		} catch (\RuntimeException $e) {
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
		} catch (\RuntimeException $e) {
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
			$cores = $this->executeCommand('/sbin/sysctl -n kern.smp.cpus');

			if ((int)$cores === 1) {
				$data = $model . ' (1 core)';
			} else {
				$data = $model . ' (' . $cores . ' cores)';
			}
		} catch (\RuntimeException $e) {
			return $data;
		}
		return $data;
	}

	public function getTime(): string {
		try {
			return $this->executeCommand('date');
		} catch (\RuntimeException $e) {
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
		} catch (\RuntimeException $e) {
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
			}
		} catch (\RuntimeException $e) {
			return $result;
		}
		return $result;
	}

	public function getNetworkInterfaces(): array {
		$result = [];

		try {
			$ifconfig = $this->executeCommand('/sbin/ifconfig -a');
		} catch (\RuntimeException $e) {
			return $result;
		}

		preg_match_all("/^(?<=(?!\t)).*(?=:)/m", $ifconfig, $interfaces);

		foreach ($interfaces[0] as $interface) {
			$iface = [];
			$iface['interface'] = $interface;

			try {
				$intface = $this->executeCommand('/sbin/ifconfig ' . $iface['interface']);
			} catch (\RuntimeException $e) {
				continue;
			}

			preg_match_all("/(?<=inet ).\S*/m", $intface, $ipv4);
			preg_match_all("/(?<=inet6 )((.*(?=%))|(.\S*))/m", $intface, $ipv6);
			$iface['ipv4'] = implode(' ', $ipv4[0]);
			$iface['ipv6'] = implode(' ', $ipv6[0]);

			if ($iface['interface'] !== 'lo0') {
				preg_match_all("/(?<=ether ).*/m", $intface, $mac);
				preg_match("/(?<=status: ).*/m", $intface, $status);
				preg_match("/\b[0-9].*?(?=base)/m", $intface, $speed);
				preg_match("/(?<=\<).*(?=-)/m", $intface, $duplex);

				if (isset($mac[0])) {
					$iface['mac'] = implode(' ', $mac[0]);
				}

				if (isset($speed[0])) {
					$iface['speed'] = $speed[0];
				}

				if (isset($status[0])) {
					$iface['status'] = $status[0];
				} else {
					$iface['status'] = 'active';
				}

				if (isset($iface['speed'])) {
					if (strpos($iface['speed'], 'G')) {
						$iface['speed'] = rtrim($iface['speed'], 'G');
						$iface['speed'] = $iface['speed'] . ' Gbps';
					} else {
						$iface['speed'] = $iface['speed'] . ' Mbps';
					}
				} else {
					$iface['speed'] = 'unknown';
				}

				if (isset($duplex[0])) {
					$iface['duplex'] = 'Duplex: ' . $duplex[0];
				} else {
					$iface['duplex'] = '';
				}
			} else {
				$iface['status'] = 'active';
				$iface['speed'] = 'unknown';
				$iface['duplex'] = '';
			}
			$result[] = $iface;
		}

		return $result;
	}

	public function getDiskInfo(): array {
		$data = [];

		try {
			$disks = $this->executeCommand('df -TPk');
		} catch (\RuntimeException $e) {
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
			$disk->setUsed((int)((int)$matches['Used'][$i] / 1024));
			$disk->setAvailable((int)((int)$matches['Available'][$i] / 1024));
			$disk->setPercent($matches['Capacity'][$i]);
			$disk->setMount($matches['Mounted'][$i]);

			$data[] = $disk;
		}

		return $data;
	}

	public function getThermalZones(): array {
		return [];
	}

	protected function executeCommand(string $command): string {
		$output = @shell_exec(escapeshellcmd($command));
		if ($output === null || $output === '' || $output === false) {
			throw new \RuntimeException('No output for command: "' . $command . '"');
		}
		return $output;
	}
}
