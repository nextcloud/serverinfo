<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Verdict;

/**
 * One aspect of the operating system and how worrying it is.
 */
final class Concern {
	public const LEVEL_OK = 'ok';
	public const LEVEL_INFO = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR = 'error';
	/** Could not be determined; never raises the overall level */
	public const LEVEL_UNKNOWN = 'unknown';

	public const UPDATES = 'updates';
	public const REBOOT = 'reboot';
	public const RESTART = 'restart';
	public const SUPPORT = 'support';
	public const PACKAGE_LISTS = 'package-lists';
	public const AUTOMATIC_UPDATES = 'automatic-updates';
	public const FAILED_UNITS = 'failed-units';
	public const TIME_SYNC = 'time-sync';

	/**
	 * @param self::LEVEL_* $level
	 * @param array<string, scalar|list<string>|null> $facts what the level is based on, for display
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $level,
		public readonly array $facts = [],
	) {
	}

	public static function rank(string $level): int {
		return match ($level) {
			self::LEVEL_ERROR => 3,
			self::LEVEL_WARNING => 2,
			self::LEVEL_INFO => 1,
			default => 0,
		};
	}

	/**
	 * One level less severe; used where a mitigation is in place.
	 *
	 * @param self::LEVEL_* $level
	 * @return self::LEVEL_*
	 */
	public static function lower(string $level): string {
		return match ($level) {
			self::LEVEL_ERROR => self::LEVEL_WARNING,
			self::LEVEL_WARNING => self::LEVEL_INFO,
			default => $level,
		};
	}
}
