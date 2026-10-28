<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Small pieces the package managers share.
 */
final class ProbeHelper {
	/** Files a fingerprint checks at most, to keep it a handful of stat calls */
	private const MAX_FINGERPRINT_PATHS = 512;

	/**
	 * The newest modification time among the files matching the patterns;
	 * a pattern without a wildcard names a single file.
	 *
	 * @param list<string> $patterns
	 */
	public static function newestModified(IHost $host, array $patterns): ?int {
		$newest = null;
		foreach ($patterns as $pattern) {
			foreach (str_contains($pattern, '*') ? $host->glob($pattern) : [$pattern] as $file) {
				$time = $host->modifiedAt($file);
				if ($time !== null && ($newest === null || $time > $newest)) {
					$newest = $time;
				}
			}
		}

		return $newest;
	}

	/**
	 * The modification time of every path, and of every file a glob pattern
	 * matches, so that comparing two fingerprints tells whether anything was
	 * written, added or removed. A path that does not exist counts as 0 when
	 * its directory can be seen.
	 *
	 * @param list<string> $paths
	 * @return array<string, int>|null null when any path cannot be checked,
	 *                                 or a pattern matches too many files
	 */
	public static function fingerprint(IHost $host, array $paths): ?array {
		$fingerprint = [];
		foreach ($paths as $path) {
			if (str_contains($path, '*')) {
				$matches = $host->glob($path);
				if (count($fingerprint) + count($matches) > self::MAX_FINGERPRINT_PATHS) {
					return null;
				}
			} else {
				$matches = [$path];
			}

			foreach ($matches as $file) {
				$time = $host->modifiedAt($file);
				if ($time === null) {
					if ($host->fileExists($file) || $host->modifiedAt(dirname($file)) === null) {
						return null;
					}
					$time = 0;
				}
				$fingerprint[$file] = $time;
			}
		}

		return $fingerprint;
	}

	/**
	 * The first line of a command's output, short enough for a diagnostic.
	 */
	public static function firstLine(string $text): string {
		return mb_substr(trim((string)strtok(trim($text), "\n")), 0, 200);
	}
}
