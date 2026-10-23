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
	 * The first line of a command's output, short enough for a diagnostic.
	 */
	public static function firstLine(string $text): string {
		return mb_substr(trim((string)strtok(trim($text), "\n")), 0, 200);
	}
}
