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

namespace OCA\ServerInfo\OperatingSystems;
/**
 * Class Ubuntu
 *
 * @package OCA\ServerInfo\OperatingSystems
 */
class DefaultOs {

	/** @var string */
	protected $cpuinfo;

	/** @var string */
	protected $meminfo;

	/** @var string */
	protected $uptime;

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
	 * Get memory will return a list key => value where all values are in bytes.
	 * [MemTotal => 0, MemFree => 0, MemAvailable => 0, SwapTotal => 0, SwapFree => 0].
	 *
	 * @return array
	 */
	public function getMemory(): array {
		$data = ['MemTotal' => -1, 'MemFree' => -1, 'MemAvailable' => -1, 'SwapTotal' => -1, 'SwapFree' => -1];

		if ($this->meminfo === null) {
			$this->meminfo = $this->readContent('/proc/meminfo');
		}

		if ($this->meminfo === '') {
			return $data;
		}

		$matches = [];
		$pattern = '/(?<Key>(?:MemTotal|MemFree|MemAvailable|SwapTotal|SwapFree)+):\s+(?<Value>\d+)\s+(?<Unit>\w{2})/';

		if (preg_match_all($pattern, $this->meminfo, $matches) === false) {
			return $data;
		}

		$keys = array_map('trim', $matches['Key']);
		$values = array_map('trim', $matches['Value']);
		$units = array_map('trim', $matches['Unit']);

		foreach ($keys as $i => $key) {
			$value = (int)$values[$i];
			$unit = $units[$i];

			if ($unit === 'kB') {
				$value *= 1000;
			}

			$data[$key] = $value;
		}

		return $data;
	}

	/**
	 * Get name of the processor
	 *
	 * @return string
	 */
	public function getCPUName(): string {
		$data = 'Unknown Processor';

		if ($this->cpuinfo === null) {
			$this->cpuinfo = $this->readContent('/proc/cpuinfo');
		}

		if ($this->cpuinfo === '') {
			return $data;
		}

		$matches = [];
		$pattern = '/model name\s:\s(.+)/';

		if (preg_match_all($pattern, $this->cpuinfo, $matches) === false) {
			return $data;
		}

		$model = $matches[1][0];
		$cores = count($matches[1]);

		if ($cores === 1) {
			$data = $model . ' (1 core)';
		} else {
			$data = $model . ' (' . $cores . ' cores)';
		}

		return $data;
	}

	/**
	 * @return string
	 */
	public function getTime() {
		$uptime = shell_exec('date');
		return $uptime;
	}

	/**
	 * Get the total number of seconds the system has been up or -1 on failure.
	 *
	 * @return int
	 */
	public function getUptime(): int {
		$data = -1;

		if ($this->uptime === null) {
			$this->uptime = $this->readContent('/proc/uptime');
		}

		if ($this->uptime === '') {
			return $data;
		}

		[$uptime,] = array_map('intval', explode(' ', $this->uptime));

		return $uptime;
	}

	/**
	 * @return string
	 */
	public function getTimeServers() {
		$servers = shell_exec('cat /etc/ntp.conf 2>/dev/null |grep  \'^pool\' | cut -f 2 -d " "');
		$servers .= ' ' . shell_exec('cat /etc/systemd/timesyncd.conf 2>/dev/null |grep  \'^NTP=\' | cut -f 2 -d " "');
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
		$data  = shell_exec('df -TP');
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

	protected function readContent(string $filename): string {
		if (is_readable($filename)) {
			return file_get_contents($filename);
		}
		return '';
	}
}
