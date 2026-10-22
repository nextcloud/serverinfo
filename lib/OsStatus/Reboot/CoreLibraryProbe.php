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
 * file was installed.
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

	public function __construct(
		private IHost $host,
	) {
	}

	/**
	 * @param int $bootTime unix timestamp of the last boot
	 * @return list<RebootReason>
	 */
	public function getRebootReasons(int $bootTime): array {
		$changed = [];
		$since = null;

		foreach (self::COMPONENTS as $component => $patterns) {
			$file = $this->find($patterns);
			if ($file === null) {
				continue;
			}

			$changedAt = $this->host->changedAt($file);
			// A minute of slack: early boot can touch files before the clock settles.
			if ($changedAt !== null && $changedAt > $bootTime + 60) {
				$changed[] = $component;
				$since = $since === null ? $changedAt : min($since, $changedAt);
			}
		}

		if ($changed === []) {
			return [];
		}

		return [new RebootReason(RebootReason::KIND_CORE_LIBRARY, null, $changed, $since)];
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
