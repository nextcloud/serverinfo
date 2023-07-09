<?php

declare(strict_types=1);

/**
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

class SunOS implements IOperatingSystem {
	private const AF_INET = 2;
	private const AF_INET6 = 26;

	public function supported(): bool {
		return false;
	}

	// requires "top" package to be installed
	public function getMemory(): Memory {
		$data = new Memory();

		try {
			$top = $this->executeCommand('/opt/ooce/bin/top -bn 1');
			$matches = [];
			$pattern = '/^Memory: (?<TotalMemory>[\d\.KMGT]+) phys mem, (?<FreeMemory>[\d\.KMGT]+) free mem, (?<TotalSwap>[\d\.KMGT]+) total swap, (?<FreeSwap>[\d\.KMGT]+) free swap$/m';

			$result = preg_match($pattern, $top, $matches);

			if ($result === 1) {
				$data->setMemTotal($this->parseHumanReadableSizeAsInt($matches['TotalMemory']));
				$data->setMemFree($this->parseHumanReadableSizeAsInt($matches['FreeMemory']));
				$data->setMemAvailable($this->parseHumanReadableSizeAsInt($matches['FreeMemory']));
				$data->setSwapTotal($this->parseHumanReadableSizeAsInt($matches['TotalSwap']));
				$data->setSwapFree($this->parseHumanReadableSizeAsInt($matches['FreeSwap']));
			}

			unset($matches, $pattern, $result);
		} catch (\RuntimeException $e) {
		}

		return $data;
	}

	public function getCpuName(): string {
		$data = 'Unknown Processor';

		try {
			$modelCmd = $this->executeCommand('/usr/sbin/psrinfo -pv');
			$modelAry = array_filter(explode("\n", $modelCmd));
			$model = trim($modelAry[count($modelAry) - 1]);
			
			$coresCmd = $this->executeCommand('/usr/sbin/psrinfo');
			$cores = count(array_filter(explode("\n", $coresCmd)));

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
			$shell_uptime = $this->executeCommand('kstat -p unix:0:system_misc:snaptime | cut -f2 | cut -d "." -f1');
			preg_match("/^unix:0:system_misc:snaptime\s(?<Uptime>[\d]+)/", $shell_uptime, $boottime);
			$uptime = (int)$boottime['Uptime'];
		} catch (\RuntimeException $e) {
			return $uptime;
		}
		return $uptime;
	}

	public function getNetworkInfo(): array {
		$result = [];
		$result['hostname'] = \gethostname();

		try {
			// $dns = $this->executeCommand('cat /etc/resolv.conf 2>/dev/null');
			// preg_match_all("/(?<=^nameserver ).\S*/m", $dns, $matches);
			// $alldns = implode(' ', $matches[0]);
			// $result['dns'] = $alldns;
			$dns = shell_exec('cat /etc/resolv.conf |grep -i \'^nameserver\'|head -n1|cut -d \' \' -f2');
			$result['dns'] = $dns;
	
			$netstat = $this->executeCommand('netstat -rn');
			preg_match_all("/(?<=^default)\s*[0-9a-fA-f\.:]+/m", $netstat, $gw);
			if (count($gw[0]) > 0) {
				$result['gateway'] = implode(", ", array_map("trim", $gw[0]));
			}
		} catch (RuntimeException $e) {
			return $result;
		}
		return $result;
	}

	public function getNetworkInterfaces(): array {
		$data = [];

		foreach ($this->getNetInterfaces() as $interfaceName => $interface) {
			$netInterface = new NetInterface($interfaceName, $interface['up']);

			if ($netInterface->isLoopback() || stripos($netInterface->getName(), 'lo') !== false) {
				continue;
			}

			$data[] = $netInterface;

			foreach ($interface['unicast'] as $unicast) {
				if ($unicast['family'] === self::AF_INET) {
					$netInterface->addIPv4($unicast['address']);
				}
				if ($unicast['family'] === self::AF_INET6) {
					$netInterface->addIPv6($unicast['address']);
				}
			}

			try {
				$details = $this->executeCommand('/usr/sbin/ifconfig ' . $interfaceName);
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
			$disks = $this->executeCommand('/sbin/zfs list -o name,type,used,available,mountpoint');
		} catch (\RuntimeException $e) {
			return $data;
		}

		$matches = [];
		$pattern = '/^(?<Filesystem>[\w\/-]+)\s*(?<Type>[\w]+)\s*(?<Used>[\d\.KMGT]+)\s*(?<Available>[\d\.KMGT]+)\s*(?<Mounted>[\w\/-]+)$/m';

		$result = preg_match_all($pattern, $disks, $matches);
		if ($result === 0 || $result === false) {
			return $data;
		}

		foreach ($matches['Filesystem'] as $i => $filesystem) {
			if (in_array($matches['Type'][$i], ['volume', 'tmpfs', 'devtmpfs'], false)) {
				continue;
			}

			$disk = new Disk();
			$disk->setDevice($filesystem);
			$disk->setFs($matches['Type'][$i]);
			$disk->setUsed($this->parseHumanReadableSizeAsInt($matches['Used'][$i]));
			$disk->setAvailable($this->parseHumanReadableSizeAsInt($matches['Available'][$i]));

			$usageInPercent = round(($this->parseHumanReadableSizeAsInt($matches['Used'][$i]) / ($this->parseHumanReadableSizeAsInt($matches['Used'][$i]) + $this->parseHumanReadableSizeAsInt($matches['Available'][$i]))) * 100, 2);
			$disk->setPercent((string)$usageInPercent . '%');

			$disk->setMount($matches['Mounted'][$i]);

			$data[] = $disk;
		}

		return $data;
	}

	protected function parseHumanReadableSizeAsInt(string $input): int {
		$result = 0;
		
		$match = [];
		preg_match('/^(?<Size>[\d\.]+)(?<Unit>[KMGT])$/', $input, $match);
		
		switch ($match['Unit']) {
			case 'K':
				$result = floatval($match['Size']) / 1024;
				break;
			case 'M':
				$result = floatval($match['Size']);
				break;
			case 'G':
				$result = floatval($match['Size']) * 1024;
				break;
			case 'T':
				$result = floatval($match['Size']) * 1024 * 1024;
				break;
		}
		
		return intval($result);
	}

	public function getThermalZones(): array {
		return [];
	}

	protected function executeCommand(string $command): string {
		$output = @shell_exec(escapeshellcmd($command));
		if ($output === null || $output === '' || $output === false) {
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
