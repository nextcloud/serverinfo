<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

/**
 * Reads OS-level package update status from filesystem signals — no
 * elevated privileges or shell calls needed. Supports Debian/Ubuntu's
 * update-notifier file format and RHEL/Fedora's reboot hint.
 */
class OsUpdates {
	/**
	 * @return array{
	 *     supported: bool,
	 *     distro: string,
	 *     updatesAvailable: int,
	 *     securityUpdates: int,
	 *     rebootRequired: bool,
	 *     rebootPackages: list<string>,
	 *     summary: string,
	 *     source: string
	 * }
	 */
	public function getOsUpdates(): array {
		$distro = $this->detectDistro();
		$source = '';

		$updates = 0;
		$security = 0;
		$summary = '';

		// Debian/Ubuntu: update-notifier writes a human-readable file on apt index update.
		$notifier = '/var/lib/update-notifier/updates-available';
		if (is_readable($notifier)) {
			$content = (string)@file_get_contents($notifier);
			$source = $notifier;
			[$updates, $security] = $this->parseUpdateNotifier($content);
			$summary = trim($content);
		}

		// RHEL/Fedora: dnf-automatic writes to this directory on check.
		if ($source === '' && is_readable('/var/lib/dnf/updates.txt')) {
			$content = (string)@file_get_contents('/var/lib/dnf/updates.txt');
			$source = '/var/lib/dnf/updates.txt';
			$lines = preg_split('/\r?\n/', trim($content)) ?: [];
			$updates = count(array_filter($lines, static fn ($l) => trim($l) !== ''));
			$summary = $updates > 0 ? "$updates package(s) can be updated." : 'System is up to date.';
		}

		$rebootFlag = file_exists('/var/run/reboot-required') || file_exists('/run/reboot-required');
		$rebootPkgs = [];
		foreach (['/var/run/reboot-required.pkgs', '/run/reboot-required.pkgs'] as $pkgFile) {
			if (is_readable($pkgFile)) {
				$lines = @file($pkgFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
				$rebootPkgs = array_values(array_unique(array_map('trim', $lines)));
				break;
			}
		}

		$supported = $source !== '' || $rebootFlag || $distro !== '';

		return [
			'supported' => $supported,
			'distro' => $distro,
			'updatesAvailable' => $updates,
			'securityUpdates' => $security,
			'rebootRequired' => $rebootFlag,
			'rebootPackages' => $rebootPkgs,
			'summary' => $summary,
			'source' => $source,
		];
	}

	private function detectDistro(): string {
		if (!is_readable('/etc/os-release')) {
			return '';
		}
		$lines = @file('/etc/os-release', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		$kv = [];
		foreach ($lines as $line) {
			if (preg_match('/^([A-Z_]+)="?([^"]*)"?$/', $line, $m)) {
				$kv[$m[1]] = $m[2];
			}
		}
		$pretty = $kv['PRETTY_NAME'] ?? ($kv['NAME'] ?? '');
		return (string)$pretty;
	}

	/**
	 * Parses Ubuntu/Debian's `updates-available` text. The file looks like:
	 *   "5 updates can be installed immediately.
	 *    2 of these updates are security updates."
	 *
	 * @return array{0: int, 1: int} [total updates, security updates]
	 */
	private function parseUpdateNotifier(string $content): array {
		$updates = 0;
		$security = 0;

		if (preg_match('/(\d+)\s+updates?\s+can\s+be\s+(?:installed|applied)/i', $content, $m)) {
			$updates = (int)$m[1];
		} elseif (preg_match('/(\d+)\s+package(?:s)?\s+can\s+be\s+upgraded/i', $content, $m)) {
			$updates = (int)$m[1];
		}

		if (preg_match('/(\d+)\s+(?:of\s+these\s+updates\s+are\s+)?security/i', $content, $m)) {
			$security = (int)$m[1];
		}

		return [$updates, $security];
	}
}
