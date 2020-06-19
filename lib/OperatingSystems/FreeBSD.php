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

/**
 * Class FreeBSD
 *
 * @package OCA\ServerInfo\OperatingSystems
 */
class FreeBSD {
	
	/**
	 * @return bool
	 */
	public function supported(): bool {
		return false;
	}

	/**
	 * Get memory will return a list key => value where all values are in bytes.
	 * [MemTotal => 0, MemFree => 0, MemAvailable => 0, SwapTotal => 0, SwapFree => 0].
	 *
	 * @return array
	 */
	public function getMemory(): array {
		$data = ['MemTotal' => -1, 'MemFree' => -1, 'MemAvailable' => -1, 'SwapTotal' => -1, 'SwapFree' => -1];

		try {
			$swapinfo = $this->executeCommand('/usr/sbin/swapinfo');
		} catch (\RuntimeException $e) {
			return $data;
		}

		$line = preg_split("/[\s]+/", $swapinfo);
		if (count($line) > 3) {
			$data['SwapTotal'] = (int)$line[3];
			$data['SwapFree'] = $data['SwapTotal'] - (int)$line[2];
		}

		try {
			$return = $this->executeCommand('/sbin/sysctl -n hw.physmem hw.pagesize vm.stats.vm.v_inactive_count vm.stats.vm.v_cache_count vm.stats.vm.v_free_count');
			$return = preg_split('/\s+/', trim($return));

			$data['MemTotal'] = (int)$return[0];
			$data['MemAvailable'] = (int)$return[1] * ((int)$return[2] + (int)$return[3] + (int)$return[4]);
		} catch (\RuntimeException $e) {
			return $data;
		}
		return $data;
	}

	/**
	 * Get name of the processor
	 *
	 * @return string
	 */
	public function getCPUName(): string {
		try {
			$data = $this->executeCommand('/sbin/sysctl -n hw.model');
		} catch (\RuntimeException $e) {
			return $data;
		}
		return $data;
	}

	/**
	 * @return string
	 */
	public function getTime() {
		try {
			$uptime = $this->executeCommand('date');
		} catch (\RuntimeException $e) {
			return $uptime;
		}
		return $uptime;
	}

	/**
	 * Get the total number of seconds the system has been up or -1 on failure.
	 *
	 * @return int
	 */
	public function getUptime(): int {
		try {
			$shell_boot = $this->executeCommand('/sbin/sysctl -n kern.boottime');
			preg_match("/[\d]+/", $shell_boot, $boottime);
			$time = $this->executeCommand('date +%s');
			$uptimeInSeconds = (int)$time - (int)$boottime[0];
		} catch (\RuntimeException $e) {
			return $uptimeInSeconds;
		}
		return $uptimeInSeconds;
	}

	/**
	 * @return string
	 */
	public function getTimeServers() {
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
				preg_match("/(?<=\().*(?=b)/m", $intface, $speed);
				preg_match("/(?<=\<).*(?=-)/m", $intface, $duplex);
				
				$iface['mac'] = implode(' ', $mac[0]);
				$iface['status'] = $status[0];
				$iface['speed']  = $speed[0];
				
				if ($iface['speed'] !== '') {
					$iface['speed'] = $iface['speed'];
				} else {
					$iface['speed'] = 'unknown';
				}

				if ($duplex[0] !== '') {
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

	/**
	 * Get diskInfo will return a list of disks. Used and Available in bytes.
	 *
	 * [
	 *      [device => /dev/mapper/homestead--vg-root, fs => ext4, used => 6205468, available => 47321220, percent => 12%, mount => /]
	 * ]
	 *
	 * @return array
	 */
	public function getDiskInfo(): array {
		$data = [];

		try {
			$disks = $this->executeCommand('df -TP');
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

			$data[] = [
				'device' => $filesystem,
				'fs' => $matches['Type'][$i],
				'used' => (int)$matches['Used'][$i] * 1024,
				'available' => (int)$matches['Available'][$i] * 1024,
				'percent' => $matches['Capacity'][$i],
				'mount' => $matches['Mounted'][$i],
			];
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
