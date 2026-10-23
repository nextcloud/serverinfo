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
 * The report is untrusted input. It is only used when it and every
 * directory above it are owned by root and writable by no one else (the
 * web server could otherwise forge an all-clear), small, well-formed,
 * written on this machine since its last boot and recent.
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

	/** How far a report may be dated ahead before the clock counts as wrong. */
	private const FUTURE_TOLERANCE = 300;

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
			$state = $this->isOutsideOpenBasedir($path) || $this->isClosedDirectory(dirname($path))
				? HelperState::STATE_UNREADABLE
				: HelperState::STATE_ABSENT;

			return [new HelperState($state, $path), null];
		}

		$security = $this->securityState($path);
		if ($security !== null) {
			return [new HelperState($security, $path), null];
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
			$report->generatedAt > $now + self::FUTURE_TOLERANCE => HelperState::STATE_CLOCK_SKEW,
			$now - $report->generatedAt > self::MAX_AGE => HelperState::STATE_STALE,
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

	/**
	 * The report and every directory above it must be owned by root,
	 * writable by no one else, sticky world-writable directories included,
	 * and no symbolic links: the rules bin/serverinfo-os-report checks the
	 * directories against before writing. Directories outside open_basedir
	 * cannot be inspected here and are left to the helper's check.
	 *
	 * @return HelperState::STATE_INSECURE|HelperState::STATE_UNREADABLE|null null when the path is secure
	 */
	private function securityState(string $path): ?string {
		for ($checked = $path; ; $checked = dirname($checked)) {
			if ($this->host->readLink($checked) !== null) {
				return HelperState::STATE_INSECURE;
			}
			$ownership = $this->host->ownership($checked);
			if ($ownership === null) {
				if ($checked === $path || !$this->isOutsideOpenBasedir($checked)) {
					return HelperState::STATE_UNREADABLE;
				}
			} elseif ($ownership['owner'] !== 0 || ($ownership['mode'] & 0o022) !== 0) {
				return HelperState::STATE_INSECURE;
			}
			if ($checked === '/') {
				return null;
			}
		}
	}

	private function isOutsideOpenBasedir(string $path): bool {
		$allowed = $this->host->openBasedir();
		if ($allowed === []) {
			return false;
		}

		// As PHP does: an entry is a directory whether or not it ends in a
		// slash, and an entry naming the file itself allows it.
		foreach ($allowed as $entry) {
			$entry = rtrim($entry, '/');
			if ($path === $entry || str_starts_with($path, $entry . '/')) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A directory of root's that neither group nor others may enter, so the
	 * web server cannot tell whether the report is in it.
	 */
	private function isClosedDirectory(string $directory): bool {
		$ownership = $this->host->ownership($directory);

		return $ownership !== null
			&& $ownership['owner'] === 0
			&& ($ownership['mode'] & 0o170000) === 0o040000
			&& ($ownership['mode'] & 0o011) === 0;
	}
}
