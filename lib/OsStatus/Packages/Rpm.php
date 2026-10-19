<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * What the RPM database says, shared by dnf and zypper.
 *
 * @psalm-api
 */
class Rpm {
	private const DATABASES = [
		'/usr/lib/sysimage/rpm/rpmdb.sqlite',
		'/var/lib/rpm/rpmdb.sqlite',
		'/usr/lib/sysimage/rpm/Packages.db',
		'/var/lib/rpm/Packages',
	];

	/** @var array<string, string>|null */
	private ?array $installed = null;

	public function __construct(
		private IHost $host,
	) {
	}

	public function getDatabasePath(): ?string {
		foreach (self::DATABASES as $path) {
			if ($this->host->fileExists($path)) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Installed version (with epoch when it has one) by package name.
	 *
	 * @return array<string, string>
	 */
	public function getInstalledVersions(): array {
		if ($this->installed !== null) {
			return $this->installed;
		}

		$this->installed = [];
		$result = $this->host->run('rpm', ['-qa', '--qf', '%{NAME} %{EPOCHNUM}:%{VERSION}-%{RELEASE}\n'], 60);
		if ($result === null || !$result->succeeded()) {
			return $this->installed;
		}

		foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
			if (preg_match('/^(\S+) (\d+):(\S+)$/', trim($line), $matches) === 1) {
				// Several versions of a name (kernels) keep the last listed.
				$this->installed[$matches[1]] = $matches[2] === '0' ? $matches[3] : $matches[2] . ':' . $matches[3];
			}
		}

		return $this->installed;
	}

	public function getLastInstallTime(): ?int {
		$result = $this->host->run('rpm', ['-qa', '--qf', '%{INSTALLTIME}\n'], 60);
		if ($result === null || !$result->succeeded()) {
			return null;
		}

		$times = array_map('intval', array_filter(preg_split('/\R/', $result->stdout) ?: [], 'ctype_digit'));

		return $times === [] ? null : max($times);
	}

	/**
	 * Splits "name-[epoch:]version-release.arch" into name and version.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function splitNevra(string $nevra): ?array {
		$withoutArch = preg_replace('/\.(x86_64|aarch64|i[3-6]86|noarch|ppc64le|s390x|armv7hl|riscv64|src)$/', '', $nevra) ?? $nevra;
		if (preg_match('/^(.+)-([^-]+-[^-]+)$/', $withoutArch, $matches) !== 1) {
			return null;
		}

		return [$matches[1], $matches[2]];
	}
}
