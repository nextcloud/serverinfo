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

	/**
	 * An architecture: x86_64, x86_64_v2, aarch64, ppc64, s390, noarch, src …
	 * Any token works, as long as it starts with a letter, so that a version
	 * such as the "22" of "go1.22" is not taken for one.
	 */
	public const ARCH = '[a-z][a-z0-9_]*';

	public const QUERY_FORMAT = '%{NAME} %{EPOCHNUM}:%{VERSION}-%{RELEASE} %{INSTALLTIME}\n';

	/** @var array<string, string>|null */
	private ?array $installed = null;
	private ?int $lastInstallTime = null;

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
	 * The database and its directory, where the sqlite backend keeps its
	 * write-ahead log until it is merged back.
	 *
	 * @return list<string>|null null when the database cannot be found
	 */
	public function getUpdateInputs(): ?array {
		$path = $this->getDatabasePath();

		return $path === null ? null : [$path, dirname($path)];
	}

	/**
	 * Installed version (with epoch when it has one) by package name.
	 *
	 * @return array<string, string>
	 */
	public function getInstalledVersions(): array {
		$this->query();

		return $this->installed ?? [];
	}

	public function getLastInstallTime(): ?int {
		$this->query();

		return $this->lastInstallTime;
	}

	/**
	 * Reads every installed package with its install time in one rpm run.
	 */
	private function query(): void {
		if ($this->installed !== null) {
			return;
		}

		$this->installed = [];
		$result = $this->host->run('rpm', ['-qa', '--qf', self::QUERY_FORMAT], 60);
		if ($result === null || !$result->succeeded()) {
			return;
		}

		foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
			if (preg_match('/^(\S+) (\d+):(\S+) (\d+)$/', trim($line), $matches) !== 1) {
				continue;
			}
			// Several versions of a name (kernels) keep the last listed.
			$this->installed[$matches[1]] = $matches[2] === '0' ? $matches[3] : $matches[2] . ':' . $matches[3];
			$this->lastInstallTime = max($this->lastInstallTime ?? 0, (int)$matches[4]);
		}
	}

	/**
	 * Splits "name-[epoch:]version-release.arch" into name and version.
	 *
	 * @param bool $withArch whether the string ends in an architecture; a
	 *                       release such as "1.el9" ends like one
	 * @return array{0: string, 1: string}|null
	 */
	public static function splitNevra(string $nevra, bool $withArch = true): ?array {
		$withoutArch = $withArch ? (preg_replace('/\.' . self::ARCH . '$/', '', $nevra) ?? $nevra) : $nevra;
		if (preg_match('/^(.+)-([^-]+-[^-]+)$/', $withoutArch, $matches) !== 1) {
			return null;
		}

		return [$matches[1], $matches[2]];
	}
}
