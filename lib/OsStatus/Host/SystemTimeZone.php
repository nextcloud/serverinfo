<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Host;

/**
 * The time zone the system writes its logs in. Nextcloud runs PHP in UTC,
 * so log timestamps cannot be parsed with PHP's default.
 */
final class SystemTimeZone {
	public static function of(IHost $host): \DateTimeZone {
		$link = $host->readLink('/etc/localtime');
		if ($link !== null && preg_match('#/zoneinfo/(.+)$#', $link, $matches) === 1) {
			$zone = self::create($matches[1]);
			if ($zone !== null) {
				return $zone;
			}
		}

		$configured = $host->readFile('/etc/timezone', 128);
		if ($configured !== null && ($zone = self::create(trim($configured))) !== null) {
			return $zone;
		}

		return new \DateTimeZone('UTC');
	}

	/**
	 * Parses a local wall-clock time such as "2026-10-19 06:36:20".
	 */
	public static function parse(string $time, \DateTimeZone $zone): ?int {
		$parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $time, $zone);

		return $parsed === false ? null : $parsed->getTimestamp();
	}

	private static function create(string $name): ?\DateTimeZone {
		if ($name === '' || preg_match('#^[A-Za-z0-9/_+-]+$#', $name) !== 1) {
			return null;
		}

		try {
			return new \DateTimeZone($name);
		} catch (\Exception) {
			return null;
		}
	}
}
