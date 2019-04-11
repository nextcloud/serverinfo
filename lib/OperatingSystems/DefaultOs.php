<?php
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

namespace OCA\Serverinfo\OperatingSystems;
/**
 * Class Ubuntu
 *
 * @package OCA\ServerInfo\OperatingSystems
 */
class DefaultOs {

	public function __construct() {}

	/**
	 * @return bool
	 */
	public function supported() {
		return true;
	}

	/**
	 * @return string
	 */
	public function getHostname() {
		$hostname = shell_exec('hostname');
		return $hostname;
	}

	/**
	 * @return string
	 */
	public function getMemory() {
		$memory = shell_exec('cat /proc/meminfo  | grep -i \'MemTotal\' | cut -f 2 -d ":" | awk \'{$1=$1}1\'');
		$memory = explode(' ', $memory);
		$memory = round($memory[0] / 1024);
		if ($memory < 1024) {
			$memory = $memory . ' MB';
		} else {
			$memory = round($memory / 1024, 1) . ' GB';
		}
		return $memory;
	}

	/**
	 * @return string
	 */
	public function getCPUName() {
		$cpu   = shell_exec('cat /proc/cpuinfo  | grep -i \'Model name\' | cut -f 2 -d ":" | awk \'{$1=$1}1\'');
		$cores = shell_exec('cat /proc/cpuinfo  | grep -i \'cpu cores\' | cut -f 2 -d ":" | awk \'{$1=$1}1\'');
		if ($cores === 1) {
			$cores = ' (' . $cores . ' core)';
		} else {
			$cores = ' (' . $cores . ' cores)';
		}
		return $cpu . ' ' . $cores;
	}

	/**
	 * @return string
	 */
	public function getTime() {
		$uptime = shell_exec('date');
		return $uptime;
	}

	/**
	 * @return string
	 */
	public function getUptime() {
		$uptime = shell_exec('uptime -p');
		return $uptime;
	}

	/**
	 * @return string
	 */
	public function getTimeServers() {
		$servers = shell_exec('cat /etc/ntp.conf |grep  \'^pool\' | cut -f 2 -d " "');
		$servers .= ' ' . shell_exec('cat /etc/systemd/timesyncd.conf |grep  \'^NTP=\' | cut -f 2 -d " "');
		return $servers;
	}

	/**
	 * @return string
	 */
	public function getNetworkInfo() {
		$result = [];
		$result['hostname'] = \gethostname();
		$dns = shell_exec('cat /etc/resolv.conf |grep -i \'^nameserver\'|head -n1|cut -d \' \' -f2');
		$result['dns'] = $dns;
		$gw = shell_exec('ip route | awk \'/default/ { print $3 }\'');
		$result['gateway'] = $gw;
		return $result;
	}

	/**
	 * @return string
	 */
	public function getNetworkInterfaces() {
		$interfaces = glob('/sys/class/net/*');
		$result = [];

		foreach ($interfaces as $interface) {
			$iface              = [];
			$iface['interface'] = basename($interface);
			$iface['mac']       = shell_exec('ip addr show dev ' . $iface['interface'] . ' | grep "link/ether " | cut -d \' \' -f 6  | cut -f 1 -d \'/\'');
			$iface['ipv4']      = shell_exec('ip addr show dev ' . $iface['interface'] . ' | grep "inet " | cut -d \' \' -f 6  | cut -f 1 -d \'/\'');
			$iface['ipv6']      = shell_exec('ip -o -6 addr show ' . $iface['interface'] . ' | sed -e \'s/^.*inet6 \([^ ]\+\).*/\1/\'');
			if ($iface['interface'] !== 'lo') {
				$iface['status'] = shell_exec('cat /sys/class/net/' . $iface['interface'] . '/operstate');
				$iface['speed']  = shell_exec('cat /sys/class/net/' . $iface['interface'] . '/speed');
				if ($iface['speed'] !== '') {
					$iface['speed'] = $iface['speed'] . 'Mbps';
				} else {
					$iface['speed'] = 'unknown';
				}

				$duplex = shell_exec('cat /sys/class/net/' . $iface['interface'] . '/duplex');
				if ($duplex !== '') {
					$iface['duplex'] = 'Duplex: ' . $duplex;
				} else {
					$iface['duplex'] = '';
				}
			} else {
				$iface['status'] = 'up';
				$iface['speed']  = 'unknown';
				$iface['duplex'] = '';
			}
			$result[] = $iface;
		}

		return $result;
	}

	/**
	 * @return array
	 */
	public function getDiskInfo() {
		$blacklist = ['', 'Type', 'tmpfs', 'devtmpfs'];
		$data  = shell_exec('df -T');
		$lines = preg_split('/[\r\n]+/', $data);

		foreach ($lines as $line) {
			$entry = preg_split('/\s+/', trim($line));
			if (isset($entry[1]) && !in_array($entry[1], $blacklist)) {
				$items = [];
				$items['device']    = $entry[0];
				$items['fs']        = $entry[1];
				$items['used']      = $entry[3];
				$items['available'] = $entry[4];
				$items['percent']   = $entry[5];
				$items['mount']     = $entry[6];
				$result[] = $items;
			}
		}
		return $result;
	}

}
