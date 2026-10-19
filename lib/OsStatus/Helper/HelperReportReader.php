<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Helper;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Reads the report the optional root helper leaves behind.
 *
 * The report is untrusted input. It is only used when it is owned by root
 * and writable by no one else (the web server could otherwise forge an
 * all-clear), small, well-formed, written on this machine since its last boot
 * and recent.
 *
 * @psalm-api
 */
class HelperReportReader {
	public const DEFAULT_PATH = '/var/lib/serverinfo/os-report.json';

	/** Must match VERSION in bin/serverinfo-os-report. */
	public const BUNDLED_VERSION = '1.0.0';

	/** The helper runs every few hours; a day and a bit means it stopped. */
	public const MAX_AGE = 26 * 3600;

	private const MAX_BYTES = 1024 * 1024;

	/** Boot times measured from user space differ by a few seconds. */
	private const BOOT_TIME_TOLERANCE = 120;

	public function __construct(
		private IHost $host,
	) {
	}

	/**
	 * A configured path is used only when it is an absolute .json path
	 * without traversal; anything else falls back to the default.
	 */
	public static function sanitizePath(string $path): string {
		if (preg_match('#^(/[A-Za-z0-9._-]+)+\.json$#', $path) !== 1 || str_contains($path, '/../') || str_contains($path, '/./')) {
			return self::DEFAULT_PATH;
		}

		return $path;
	}

	/**
	 * @param int|null $bootTime this machine's boot time, null when unknown
	 * @return array{0: HelperState, 1: ?HelperReport}
	 */
	public function read(string $path, ?int $bootTime, int $now): array {
		$path = self::sanitizePath($path);

		if (!$this->host->fileExists($path)) {
			$state = $this->isOutsideOpenBasedir($path) ? HelperState::STATE_UNREADABLE : HelperState::STATE_ABSENT;

			return [new HelperState($state, $path), null];
		}

		if (!$this->isRootOnly($path) || !$this->isRootOnly(dirname($path))) {
			return [new HelperState(HelperState::STATE_INSECURE, $path), null];
		}

		$content = $this->host->readFile($path, self::MAX_BYTES + 1);
		if ($content === null) {
			return [new HelperState(HelperState::STATE_UNREADABLE, $path), null];
		}
		if (strlen($content) > self::MAX_BYTES) {
			return [new HelperState(HelperState::STATE_INVALID, $path), null];
		}

		$data = json_decode($content, true, 16);
		if (!is_array($data)) {
			return [new HelperState(HelperState::STATE_INVALID, $path), null];
		}
		if (($data['schema'] ?? null) !== HelperReport::SCHEMA) {
			return [new HelperState(HelperState::STATE_UNSUPPORTED, $path), null];
		}

		try {
			$report = HelperReport::fromArray($data);
		} catch (\InvalidArgumentException) {
			return [new HelperState(HelperState::STATE_INVALID, $path), null];
		}

		$state = match (true) {
			$report->hostname !== $this->host->hostname() => HelperState::STATE_OTHER_HOST,
			$bootTime !== null && $report->bootTime !== null && abs($report->bootTime - $bootTime) > self::BOOT_TIME_TOLERANCE => HelperState::STATE_PREVIOUS_BOOT,
			$now - $report->generatedAt > self::MAX_AGE || $report->generatedAt > $now + 300 => HelperState::STATE_STALE,
			default => HelperState::STATE_OK,
		};

		$helperState = new HelperState(
			$state,
			$path,
			$report->generatedAt,
			$report->helperVersion,
			version_compare($report->helperVersion, self::BUNDLED_VERSION, '<'),
			$report->errors,
		);

		return [$helperState, $state === HelperState::STATE_OK ? $report : null];
	}

	private function isOutsideOpenBasedir(string $path): bool {
		$allowed = $this->host->openBasedir();
		if ($allowed === []) {
			return false;
		}

		foreach ($allowed as $directory) {
			if (str_starts_with($path, rtrim($directory, '/') . '/')) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Owned by root and writable by neither group nor others.
	 */
	private function isRootOnly(string $path): bool {
		$ownership = $this->host->ownership($path);

		return $ownership !== null && $ownership['owner'] === 0 && ($ownership['mode'] & 0o022) === 0;
	}
}
