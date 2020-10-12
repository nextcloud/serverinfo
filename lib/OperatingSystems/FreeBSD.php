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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\ServerInfo\OperatingSystems;

use OCA\ServerInfo\Resources\Disk;
use OCA\ServerInfo\Resources\Memory;

/**
 * Class FreeBSD
 *
 * @package OCA\ServerInfo\OperatingSystems
 */
class FreeBSD implements IOperatingSystem {

	/**
	 * @return bool
	 */
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
		$pattern = '/(?>\/dev\/\w+)\s+(?>\d+)\s+(?<Used>\d+)\s+(?<Avail>\d+)\s+(?<Capacity>\d+)/';

		$result = preg_match_all($pattern, $swapinfo, $matches);
		if ($result === 1) {
			$data->setSwapTotal((int)($matches['Avail'][0] / 1024));
			$data->setSwapFree(($data->getSwapTotal() - (int)($matches['Used'][0] / 1024)));
		}

		unset($matches, $result);

		try {
			$meminfo = $this->executeCommand('/sbin/sysctl -n hw.realmem hw.pagesize vm.stats.vm.v_inactive_count vm.stats.vm.v_cache_count vm.stats.vm.v_free_count');
		} catch (\RuntimeException $e) {
			$meminfo = '';
		}

		$lines = explode("\n", $meminfo);
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

			if ($cores === 1) {
				$data = $model . ' (1 core)';
			} else {
				$data = $model . ' (' . $cores . ' cores)';
			}
		} catch (\RuntimeException $e) {
			return $data;
		}
		return $data;
	}

	/**
	 * @return string
	 */
	public function getTime() {
		$time = '';

		try {
			$time = $this->executeCommand('date');
		} catch (\RuntimeException $e) {
			return $time;
		}
		return $time;
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

	/**
	 * @return string
	 */
	public function getTimeServers() {
		$servers = " ";

		try {
			$servers = $this->executeCommand('cat /etc/ntp.conf 2>/dev/null');
			preg_match_all("/(?<=^pool ).\S*/m", $servers, $matches);
			$allservers = implode(' ', $matches[0]);
		} catch (\RuntimeException $e) {
			return $servers;
		}
		return $allservers;
	}

	/**
	 * @return string
	 */
	public function getNetworkInfo() {
		$result = [];
		$result['hostname'] = \gethostname();

		try {
			$dns = $this->executeCommand('cat /etc/resolv.conf 2>/dev/null');
			preg_match_all("/(?<=^nameserver ).\S*/m", $dns, $matches);
			$alldns = implode(' ', $matches[0]);
			$result['dns'] = $alldns;
			$netstat = $this->executeCommand('netstat -rn');
			preg_match("/(?<=^default).*\b\d/m", $netstat, $gw);
			$result['gateway'] = $gw[0];
		} catch (\RuntimeException $e) {
			return $result;
		}
		return $result;
	}

	/**
	 * @return string
	 */
	public function getNetworkInterfaces() {
		$result = [];

		$ifconfig = $this->executeCommand('/sbin/ifconfig -a');
		preg_match_all("/^(?<=(?!\t)).*(?=:)/m", $ifconfig, $interfaces);

		foreach ($interfaces[0] as $interface) {
			$iface              = [];
			$iface['interface'] = $interface;
			$intface            = $this->executeCommand('/sbin/ifconfig ' . $iface['interface']);
			preg_match_all("/(?<=inet ).\S*/m", $intface, $ipv4);
			preg_match_all("/(?<=inet6 )((.*(?=%))|(.\S*))/m", $intface, $ipv6);
			$iface['ipv4']      = implode(' ', $ipv4[0]);
			$iface['ipv6']      = implode(' ', $ipv6[0]);

			if ($iface['interface'] !== 'lo0') {
				preg_match_all("/(?<=ether ).*/m", $intface, $mac);
				preg_match("/(?<=status: ).*/m", $intface, $status);
				preg_match("/\b[0-9].*?(?=base)/m", $intface, $speed);
				preg_match("/(?<=\<).*(?=-)/m", $intface, $duplex);

				$iface['mac'] = implode(' ', $mac[0]);
				$iface['speed']  = $speed[0];

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
				$iface['speed']  = 'unknown';
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
		$pattern = '/^(?<Filesystem>[\w\/-]+)\s*(?<Type>\w+)\s*(?<Blocks>\d+)\s*(?<Used>\d+)\s*(?<Available>\d+)\s*(?<Capacity>\d+%)\s*(?<Mounted>[\w\/-]+)$/m';

		$result = preg_match_all($pattern, $disks, $matches);
		if ($result === 0 || $result === false) {
			return $data;
		}

		foreach ($matches['Filesystem'] as $i => $filesystem) {
			if (in_array($matches['Type'][$i], ['tmpfs', 'devtmpfs'], false)) {
				continue;
			}

			$disk = new Disk();
			$disk->setDevice($filesystem);
			$disk->setFs($matches['Type'][$i]);
			$disk->setUsed((int)($matches['Used'][$i] / 1024));
			$disk->setAvailable((int)($matches['Available'][$i] / 1024));
			$disk->setPercent($matches['Capacity'][$i]);
			$disk->setMount($matches['Mounted'][$i]);

			$data[] = $disk;
		}

		return $data;
	}

	protected function executeCommand(string $command): string {
		$output = @shell_exec(escapeshellcmd($command));
		if ($output === null || $output === '') {
			throw new \RuntimeException('No output for command: "' . $command . '"');
		}
		return $output;
	}
}
