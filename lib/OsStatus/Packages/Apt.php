<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Host\IHost;
use OCA\ServerInfo\OsStatus\Host\SystemTimeZone;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;

/**
 * Debian, Ubuntu and their derivatives.
 *
 * `apt-get -s` simulates an upgrade from the package lists already on disk
 * and needs no privilege. Ubuntu's phased updates are left out of the
 * simulation by apt itself, so they are not reported as pending.
 *
 * @psalm-api
 */
class Apt implements IPackageManager {
	private const REBOOT_REQUIRED = '/run/reboot-required';
	private const DPKG_LOG = '/var/log/dpkg.log';
	private const DPKG_STATUS = '/var/lib/dpkg/status';
	private const SIMULATE_TIMEOUT = 120;
	private const LISTS = '/var/lib/apt/lists';
	private const UPDATE_SUCCESS_STAMP = '/var/lib/apt/periodic/update-success-stamp';

	/** A Debian package name, with an optional ":arch" qualifier */
	private const PACKAGE_NAME = '/^[a-z0-9][a-z0-9+.:-]*$/';
	/** A Debian version: [epoch:]upstream[-revision] */
	private const VERSION = '/^[A-Za-z0-9][A-Za-z0-9.+~:_-]{0,127}$/';
	/** An archive as apt describes it, e.g. "Debian-Security:12/stable-security" or "Debian Backports:12/bookworm-backports" */
	private const ORIGIN = '/^[A-Za-z0-9][A-Za-z0-9 .+~:_\/-]{0,127}$/';

	public function __construct(
		private IHost $host,
		private SystemdProbe $systemd,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'apt';
	}

	#[\Override]
	public function isAvailable(): bool {
		return $this->host->binaryState('apt-get') !== false;
	}

	#[\Override]
	public function classifiesSecurity(): bool {
		return true;
	}

	#[\Override]
	public function getPendingUpdates(): array {
		$result = $this->host->run('apt-get', ['-s', '-o', 'Debug::NoLocking=1', 'dist-upgrade'], self::SIMULATE_TIMEOUT);
		if ($result === null) {
			throw new ProbeException('apt-get could not be run.');
		}
		if (!$result->succeeded()) {
			throw new ProbeException('apt-get -s dist-upgrade failed: ' . $result->failure(self::SIMULATE_TIMEOUT));
		}

		return self::parseSimulation($result->stdout);
	}

	/**
	 * Parses the "Inst" lines of an `apt-get -s` run, e.g.
	 * Inst libssl3 [3.0.11-1~deb12u2] (3.0.15-1~deb12u1 Debian:12.8/stable, Debian-Security:12/stable-security [amd64])
	 * A line whose name, versions or archives do not look like apt's is left out.
	 *
	 * @return list<PendingUpdate>
	 */
	public static function parseSimulation(string $output): array {
		$updates = [];
		foreach (preg_split('/\R/', $output) ?: [] as $line) {
			if (preg_match('/^Inst (\S+)(?: \[([^\]]*)\])? \((\S+) ([^()\[]*?)(?: \[[^\]]*\])?\)/', $line, $matches) !== 1) {
				continue;
			}

			$origins = array_values(array_filter(array_map('trim', explode(',', $matches[4]))));
			if (preg_match(self::PACKAGE_NAME, $matches[1]) !== 1
				|| ($matches[2] !== '' && preg_match(self::VERSION, $matches[2]) !== 1)
				|| preg_match(self::VERSION, $matches[3]) !== 1
				|| array_filter($origins, static fn (string $origin): bool => preg_match(self::ORIGIN, $origin) !== 1) !== []) {
				continue;
			}

			$security = false;
			foreach ($origins as $origin) {
				$slash = strrpos($origin, '/');
				$suite = $slash === false ? $origin : substr($origin, $slash + 1);
				if (str_ends_with($suite, '-security') || stripos($origin, 'Debian-Security') !== false) {
					$security = true;
				}
			}

			$updates[$matches[1]] = new PendingUpdate(
				$matches[1],
				$matches[2] !== '' ? $matches[2] : null,
				$matches[3],
				$security,
				$origins !== [] ? implode(', ', $origins) : null,
			);
		}

		return array_values($updates);
	}

	#[\Override]
	public function getHeldPackages(): ?array {
		$result = $this->host->run('apt-mark', ['showhold'], 30);
		if ($result === null || !$result->succeeded()) {
			return null;
		}

		$held = [];
		foreach (preg_split('/\R/', trim($result->stdout)) ?: [] as $name) {
			$name = trim($name);
			if (preg_match(self::PACKAGE_NAME, $name) === 1) {
				$held[] = new HeldPackage($name, 'apt-mark hold');
			}
		}

		return $held;
	}

	/**
	 * update-success-stamp is touched after every `apt-get update` that
	 * succeeded, where a hook for it is installed. Otherwise the newest
	 * release file tells: apt only moves one into place after verifying it,
	 * and keeps the mirror's modification time, so it is when the lists were
	 * refreshed at the earliest. lists/partial is not used: it is written on
	 * every run, also on one that fails.
	 */
	#[\Override]
	public function getListsRefreshedAt(): ?int {
		$stamp = $this->host->modifiedAt(self::UPDATE_SUCCESS_STAMP);
		if ($stamp !== null) {
			return $stamp;
		}

		$times = [];
		foreach (['*InRelease', '*_Release'] as $pattern) {
			foreach ($this->host->glob(self::LISTS . '/' . $pattern) as $file) {
				$times[] = $this->host->modifiedAt($file);
			}
		}
		$times = array_filter($times, 'is_int');

		return $times === [] ? null : max($times);
	}

	#[\Override]
	public function getLastUpgradeAt(): ?int {
		$log = $this->host->readFileTail(self::DPKG_LOG, 512 * 1024);
		if ($log !== null) {
			$last = self::lastUpgradeFromLog($log, SystemTimeZone::of($this->host));
			if ($last !== null) {
				return $last;
			}
		}

		return $this->host->modifiedAt(self::DPKG_STATUS);
	}

	/**
	 * The time of the last install or upgrade in a dpkg.log, which is
	 * written in the system's local time.
	 */
	public static function lastUpgradeFromLog(string $log, \DateTimeZone $zone): ?int {
		if (preg_match_all('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) (?:upgrade|install) /m', $log, $matches) < 1) {
			return null;
		}

		return SystemTimeZone::parse((string)end($matches[1]), $zone);
	}

	#[\Override]
	public function getAutomaticUpdates(): AutomaticUpdates {
		$installed = $this->host->fileExists('/usr/bin/unattended-upgrade');

		$result = $this->host->run('apt-config', ['dump'], 30);
		if ($result === null || !$result->succeeded()) {
			return $installed ? AutomaticUpdates::unknown() : new AutomaticUpdates(AutomaticUpdates::STATE_DISABLED);
		}
		$config = $result->stdout;

		$periodic = self::configValue($config, 'APT::Periodic::Unattended-Upgrade');
		if (!$installed || $periodic === null || $periodic === '0' || $periodic === '') {
			$download = self::configValue($config, 'APT::Periodic::Download-Upgradeable-Packages');
			if ($download !== null && $download !== '0' && $download !== '') {
				return new AutomaticUpdates(AutomaticUpdates::STATE_DISABLED, 'apt-daily', AutomaticUpdates::SCOPE_DOWNLOAD);
			}

			return new AutomaticUpdates(AutomaticUpdates::STATE_DISABLED);
		}

		// The schedule comes from the systemd timer; a disabled or masked timer
		// means the configuration above never runs.
		$timer = $this->systemd->getUnitFileState('apt-daily-upgrade.timer');
		if ($timer === 'disabled' || $timer === 'masked') {
			return new AutomaticUpdates(AutomaticUpdates::STATE_DISABLED, 'unattended-upgrades');
		}

		return new AutomaticUpdates(AutomaticUpdates::STATE_ENABLED, 'unattended-upgrades', self::unattendedScope($config));
	}

	/**
	 * Whether unattended-upgrades is limited to security origins.
	 *
	 * The bare release pocket ("Ubuntu:noble") only changes at release time,
	 * so allowing it does not widen the scope.
	 *
	 * @return AutomaticUpdates::SCOPE_*
	 */
	public static function unattendedScope(string $config): string {
		$patterns = [];
		if (preg_match_all('/^Unattended-Upgrade::(?:Allowed-Origins|Origins-Pattern):: "(.*)";$/m', $config, $matches) > 0) {
			$patterns = $matches[1];
		}
		if ($patterns === []) {
			return AutomaticUpdates::SCOPE_UNKNOWN;
		}

		foreach ($patterns as $pattern) {
			if (stripos($pattern, 'security') !== false || $pattern === '${distro_id}:${distro_codename}') {
				continue;
			}

			return AutomaticUpdates::SCOPE_ALL;
		}

		return AutomaticUpdates::SCOPE_SECURITY;
	}

	/**
	 * The value of a scalar option in `apt-config dump` output.
	 */
	public static function configValue(string $config, string $key): ?string {
		if (preg_match('/^' . preg_quote($key, '/') . ' "(.*)";$/m', $config, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}

	#[\Override]
	public function getRebootReasons(): array {
		if (!$this->host->fileExists(self::REBOOT_REQUIRED)) {
			return [];
		}

		$packages = [];
		$list = $this->host->readFile(self::REBOOT_REQUIRED . '.pkgs', 65536) ?? '';
		foreach (preg_split('/\R/', $list) ?: [] as $package) {
			$package = trim($package);
			if (preg_match(self::PACKAGE_NAME, $package) === 1) {
				$packages[$package] = true;
			}
		}

		return [new RebootReason(
			RebootReason::KIND_DISTRIBUTION,
			null,
			array_keys($packages),
			$this->host->modifiedAt(self::REBOOT_REQUIRED),
		)];
	}

	#[\Override]
	public function hasOwnRebootCheck(): bool {
		return false;
	}

	#[\Override]
	public function getUpgradeCommands(): array {
		return [
			'all' => 'sudo apt update && sudo apt full-upgrade',
			'security' => $this->host->fileExists('/usr/bin/unattended-upgrade') ? 'sudo unattended-upgrade -v' : null,
		];
	}

	#[\Override]
	public function getPackageDatabasePath(): ?string {
		return self::DPKG_STATUS;
	}
}
