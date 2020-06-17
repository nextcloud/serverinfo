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
			$uptime = $this->executeCommand('/sbin/sysctl -n kern.boottime | tr -d \',\' | cut -d \' \' -f4');
			$time = $this->executeCommand('date +%s');
			$uptimeInSeconds = (int)$uptime - (int)$time;
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
			$servers = $this->executeCommand('cat /etc/ntp.conf 2>/dev/null |grep  \'^pool\' | cut -f 2 -d " "');
		} catch (\RuntimeException $e) {
			return $servers;
		}
		return $servers;
	}

	/**
	 * @return string
	 */
	public function getNetworkInfo() {
		$result = [];
		$result['hostname'] = \gethostname();
		
		try {
			$dns = $this->executeCommand('cat /etc/resolv.conf |grep -i \'^nameserver\'|head -n1|cut -d \' \' -f2');
			$result['dns'] = $dns;
			$gw = $this->executeCommand('netstat -rn | grep default | cut -d \' \' -f13');
			$result['gateway'] = $gw;
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
		
		$interfaces = $this->executeCommand('/sbin/ifconfig -a | cut -d$\'\t\' -f1 | cut -d \':\' -f1 | grep -v -e \'^$\'');
		$interfaces = preg_split('/\s+/', trim($interfaces));

		foreach ($interfaces as $interface) {
			$iface              = [];
			$iface['interface'] = $interface;
			$iface['ipv4']      = $this->executeCommand('/sbin/ifconfig ' . $iface['interface'] . ' | grep \'inet \' | cut -f2 -d$\'\t\' | cut -f 2 -d \' \'');
			$iface['ipv6']      = $this->executeCommand('/sbin/ifconfig ' . $iface['interface'] . ' | grep inet6 | cut -f2 -d$\'\t\' | cut -f 2 -d \' \' | cut -f1 -d \'%\'');
			if ($iface['interface'] !== 'lo0') {
				$iface['mac']       = $this->executeCommand('/sbin/ifconfig ' . $iface['interface'] . ' | grep ether | cut -f2 -d$\'\t\' | cut -f2 -d \' \'');
				$iface['status'] = $this->executeCommand('/sbin/ifconfig ' . $iface['interface'] . ' | grep status | cut -f2 -d$\'\t\' | cut -f2 -d \' \'');
				$iface['speed']  = $this->executeCommand('/sbin/ifconfig ' . $iface['interface'] . ' | grep media | cut -d \' \' -f3 | cut -f1 -d \'b\'');
				if ($iface['speed'] !== '') {
					$iface['speed'] = $iface['speed'];
				} else {
					$iface['speed'] = 'unknown';
				}

				$duplex = $this->executeCommand('/sbin/ifconfig ' . $iface['interface'] . ' | grep media | cut -d \'<\' -f2 | cut -d \'-\' -f1');
				if ($duplex !== '') {
					$iface['duplex'] = 'Duplex: ' . $duplex;
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
		$output = @shell_exec($command);
		if ($output === null || $output === '') {
			throw new \RuntimeException('No output for command: "' . $command . '"');
		}
		return $output;
	}
}
