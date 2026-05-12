<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IConfig;
use OCP\ServerVersion;

class EolInfo {
	/**
	 * PHP version end-of-life dates (security support end).
	 * Source: https://www.php.net/supported-versions.php
	 *
	 * @var array<string, string> version "8.1" => "2025-12-31"
	 */
	private const PHP_EOL = [
		'7.4' => '2022-11-28',
		'8.0' => '2023-11-26',
		'8.1' => '2025-12-31',
		'8.2' => '2026-12-31',
		'8.3' => '2027-12-31',
		'8.4' => '2028-12-31',
		'8.5' => '2029-12-31',
	];

	/**
	 * Nextcloud major version EOL (community/security end).
	 * Approximate based on the Nextcloud release schedule.
	 *
	 * @var array<string, string> "27" => "2024-06-30"
	 */
	private const NC_EOL = [
		'27' => '2024-06-30',
		'28' => '2024-12-31',
		'29' => '2025-06-30',
		'30' => '2025-12-31',
		'31' => '2026-06-30',
		'32' => '2026-12-31',
		'33' => '2027-06-30',
		'34' => '2028-06-30',
		'35' => '2028-12-31',
	];

	public function __construct(
		private IConfig $config,
		private ServerVersion $serverVersion,
	) {
	}

	/**
	 * @return array{
	 *     php: array{version: string, eol: ?string, daysUntilEol: ?int, status: string},
	 *     nextcloud: array{version: string, major: string, eol: ?string, daysUntilEol: ?int, status: string}
	 * }
	 */
	public function getEolInfo(): array {
		$phpMajor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$phpEol = self::PHP_EOL[$phpMajor] ?? null;
		$ncVersionParts = $this->serverVersion->getVersion();
		$ncVersion = implode('.', $ncVersionParts);
		$ncMajor = (string)(int)($ncVersionParts[0] ?? 0);
		$ncEol = self::NC_EOL[$ncMajor] ?? null;

		return [
			'php' => $this->makeEntry($phpMajor, $phpEol),
			'nextcloud' => array_merge(
				$this->makeEntry($ncMajor, $ncEol),
				['version' => $ncVersion, 'major' => $ncMajor],
			),
		];
	}

	/**
	 * @return array{version: string, eol: ?string, daysUntilEol: ?int, status: string}
	 */
	private function makeEntry(string $version, ?string $eolDate): array {
		if ($eolDate === null) {
			return ['version' => $version, 'eol' => null, 'daysUntilEol' => null, 'status' => 'unknown'];
		}
		try {
			$eolTs = (new \DateTimeImmutable($eolDate))->getTimestamp();
		} catch (\Throwable) {
			return ['version' => $version, 'eol' => $eolDate, 'daysUntilEol' => null, 'status' => 'unknown'];
		}
		$days = (int)floor(($eolTs - time()) / 86400);
		$status = 'ok';
		if ($days < 0) {
			$status = 'critical';
		} elseif ($days < 90) {
			$status = 'warning';
		}
		return [
			'version' => $version,
			'eol' => $eolDate,
			'daysUntilEol' => $days,
			'status' => $status,
		];
	}
}
