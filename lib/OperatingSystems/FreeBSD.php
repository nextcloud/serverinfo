<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\ServerInfo\OperatingSystems;

use OCA\ServerInfo\OsStatus\Host\IHost;
use OCA\ServerInfo\Resources\CPU;
use OCA\ServerInfo\Resources\Disk;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\Resources\NetInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class FreeBSD implements IOperatingSystem {
	private const AF_INET = 2;
	private const AF_INET6 = 28;

	private CommandOutput $commands;

	public function __construct(
		private IHost $host,
		LoggerInterface $logger,
	) {
		$this->commands = new CommandOutput($host, $logger);
	}

	#[\Override]
	public function supported(): bool {
		return false;
	}

	#[\Override]
	public function getCPU(): CPU {
		$lines = explode("\n", trim($this->commands->read('sysctl', ['-n', 'hw.model', 'kern.smp.cpus']) ?? ''));
		if (count($lines) !== 2 || trim($lines[0]) === '' || preg_match('/^\d+$/', trim($lines[1])) !== 1) {
			// -1 marks the count as unknown rather than claiming a single core.
			return new CPU('Unknown Processor', -1);
		}

		return new CPU(trim($lines[0]), (int)$lines[1]);
	}

	#[\Override]
	public function getAverageLoad(): array|false {
		$load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
		if ($load === false || count($load) < 3) {
			return false;
		}

		return [(float)$load[0], (float)$load[1], (float)$load[2]];
	}

	/**
	 * The boot time comes from sysctl, which costs a fork, so
	 * {@see \OCA\ServerInfo\Os::getUptime()} only asks occasionally and
	 * remembers the answer.
	 */
	#[\Override]
	public function sampleUptime(): int {
		$boottime = $this->commands->read('sysctl', ['-n', 'kern.boottime']);
		if ($boottime === null || preg_match('/\bsec\s*=\s*(\d+)/', $boottime, $matches) !== 1) {
			return -1;
		}

		$bootedAt = (int)$matches[1];

		return $bootedAt > 0 ? max(0, time() - $bootedAt) : -1;
	}

	#[\Override]
	public function getMemory(): Memory {
		$data = new Memory();

		$swapinfo = $this->commands->read('swapinfo', ['-k']) ?? '';

		$matches = [];
		$pattern = '/(?>\/dev\/\S+)\s+(?>\d+)\s+(?<Used>\d+)\s+(?<Avail>\d+)\s+(?<Capacity>\d+)/';

		$result = preg_match_all($pattern, $swapinfo, $matches);
		if ($result !== 0) {
			$data->setSwapTotal((int)((int)array_sum($matches['Avail']) / 1024));
			$data->setSwapFree(($data->getSwapTotal() - (int)((int)array_sum($matches['Used']) / 1024)));
		}

		unset($matches, $result);

		$meminfo = $this->commands->read('sysctl', ['-n', 'hw.realmem', 'hw.pagesize', 'vm.stats.vm.v_inactive_count', 'vm.stats.vm.v_cache_count', 'vm.stats.vm.v_free_count']) ?? '';

		$lines = array_map('intval', explode("\n", $meminfo));
		if (count($lines) > 4) {
			$data->setMemTotal((int)($lines[0] / 1024 / 1024));
			$data->setMemAvailable((int)(($lines[1] * ($lines[2] + $lines[3] + $lines[4])) / 1024 / 1024));
		}

		unset($lines);

		return $data;
	}

	#[\Override]
	public function getTime(): string {
		return (new \DateTimeImmutable('now', $this->getSystemTimeZone()))->format('D M j H:i:s T Y');
	}

	/**
	 * Nextcloud runs PHP in UTC, so the zone date(1) would use is read from
	 * the system: tzsetup either links /etc/localtime into the zoneinfo
	 * database or copies it there and records the name in /var/db/zoneinfo.
	 */
	private function getSystemTimeZone(): \DateTimeZone {
		$link = $this->host->readLink('/etc/localtime');
		$name = $link !== null && preg_match('#/zoneinfo/(.+)$#', $link, $matches) === 1
			? $matches[1]
			: trim($this->host->readFile('/var/db/zoneinfo', 128) ?? '');

		if ($name !== '' && preg_match('#^[A-Za-z0-9/_+-]+$#', $name) === 1) {
			try {
				return new \DateTimeZone($name);
			} catch (\Exception) {
				// fall through to the configured default
			}
		}

		return new \DateTimeZone(date_default_timezone_get());
	}

	/**
	 * Only {@see sampleUptime()} can tell, at the cost of a fork.
	 */
	#[\Override]
	public function getUptime(): int {
		return -1;
	}

	#[\Override]
	public function getNetworkInfo(): array {
		$result = [
			'gateway' => '',
			'hostname' => \gethostname(),
			'dns' => '',
		];

		$netstat = $this->commands->read('netstat', ['-rn']);
		if ($netstat !== null) {
			preg_match_all("/(?<=^default)\s*[0-9a-fA-f\.:]+/m", $netstat, $gw);
			if (count($gw[0]) > 0) {
				$result['gateway'] = implode(', ', array_map('trim', $gw[0]));
			}
		}

		$resolvConf = $this->host->readFile('/etc/resolv.conf', 65536);
		if ($resolvConf !== null && preg_match_all('/^\s*nameserver\s+(\S+)/m', $resolvConf, $matches)) {
			$result['dns'] = implode(', ', array_unique($matches[1]));
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

			// Interface names are at most 15 characters and never start with a
			// dash, which keeps the name from being read as an option.
			if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,14}$/', (string)$interfaceName) !== 1) {
				continue;
			}

			$details = $this->commands->read('ifconfig', [(string)$interfaceName]);
			if ($details === null) {
				continue;
			}

			preg_match('/(?<=ether ).*/m', $details, $mac);
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

	#[\Override]
	public function getDiskInfo(): array {
		$data = [];

		$disks = $this->commands->read('df', ['-TPk'], CommandOutput::DF_TIMEOUT, true);
		if ($disks === null) {
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

		$excluded = ['devfs', 'fdescfs', 'tmpfs', 'devtmpfs', 'procfs', 'linprocfs', 'linsysfs'];
		foreach ($matches['Filesystem'] as $i => $filesystem) {
			if (in_array($matches['Type'][$i], $excluded, false)) {
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
			$disk->setPercent($blocks > 0 ? (string)round($used * 100 / $blocks, 2) . '%' : '0%');
			$disk->setMount($matches['Mounted'][$i]);

			$data[] = $disk;
		}

		return $data;
	}

	#[\Override]
	public function getThermalZones(): array {
		return [];
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
