<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Reboot;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Notices when a library that every process uses has been replaced since boot.
 *
 * The same idea as dnf's `needs-restarting -r`, for distributions without
 * such a tool. A package upgrade writes each file under a new name and renames
 * it into place, which sets the inode change time; package managers keep the
 * build time as the modification time, so only the change time says when a
 * file was installed. A chmod, chown or restore from a backup sets the change
 * time as well, so the package itself has to have changed since boot too:
 * dpkg rewrites a package's file list on every install, and otherwise the
 * package database must have changed.
 *
 * systemd is not among them: its package re-executes PID 1 on upgrade.
 *
 * @psalm-api
 */
class CoreLibraryProbe {
	/** Glob patterns per component, the first match wins. */
	private const COMPONENTS = [
		'glibc' => [
			'/lib/x86_64-linux-gnu/libc.so.6', '/lib/aarch64-linux-gnu/libc.so.6', '/lib/*-linux-gnu*/libc.so.6',
			'/usr/lib/x86_64-linux-gnu/libc.so.6', '/usr/lib/aarch64-linux-gnu/libc.so.6',
			'/lib64/libc.so.6', '/usr/lib64/libc.so.6', '/usr/lib/libc.so.6',
			'/lib/ld-musl-*.so.1',
		],
		'dbus' => ['/usr/bin/dbus-broker', '/usr/bin/dbus-daemon'],
	];

	/** The Debian packages that ship each component. */
	private const DPKG_PACKAGES = [
		'glibc' => ['libc6'],
		'dbus' => ['dbus-broker', 'dbus-daemon', 'dbus'],
	];

	private const DPKG_INFO = '/var/lib/dpkg/info';

	/** Early boot can touch files before the clock settles. */
	private const BOOT_SLACK = 60;

	public function __construct(
		private IHost $host,
	) {
	}

	/**
	 * @param int $bootTime unix timestamp of the last boot
	 * @param string|null $packageDatabase a file that changes whenever packages are installed
	 * @return list<RebootReason>
	 */
	public function getRebootReasons(int $bootTime, ?string $packageDatabase = null): array {
		$changed = [];
		$since = null;

		foreach (self::COMPONENTS as $component => $patterns) {
			$file = $this->find($patterns);
			if ($file === null) {
				continue;
			}

			$changedAt = $this->host->changedAt($file);
			if ($changedAt === null || $changedAt <= $bootTime + self::BOOT_SLACK) {
				continue;
			}

			$installedAt = $this->packageChangedAt($component)
				?? ($packageDatabase !== null ? $this->host->modifiedAt($packageDatabase) : null);
			if ($installedAt !== null && $installedAt <= $bootTime + self::BOOT_SLACK) {
				continue;
			}

			$changed[] = $component;
			$since = $since === null ? $changedAt : min($since, $changedAt);
		}

		if ($changed === []) {
			return [];
		}

		return [new RebootReason(RebootReason::KIND_CORE_LIBRARY, null, $changed, $since)];
	}

	/**
	 * When dpkg last installed a package that ships the component, from the
	 * file lists it rewrites on every install; null when there are none.
	 */
	private function packageChangedAt(string $component): ?int {
		$times = [];
		foreach (self::DPKG_PACKAGES[$component] ?? [] as $package) {
			$lists = $this->host->glob(self::DPKG_INFO . '/' . $package . ':*.list');
			$lists[] = self::DPKG_INFO . '/' . $package . '.list';
			foreach ($lists as $list) {
				$times[] = $this->host->modifiedAt($list);
			}
		}
		$times = array_filter($times, 'is_int');

		return $times === [] ? null : max($times);
	}

	/**
	 * @param list<string> $patterns
	 */
	private function find(array $patterns): ?string {
		foreach ($patterns as $pattern) {
			$matches = str_contains($pattern, '*') ? $this->host->glob($pattern) : ($this->host->fileExists($pattern) ? [$pattern] : []);
			if ($matches !== []) {
				return $matches[0];
			}
		}

		return null;
	}
}
