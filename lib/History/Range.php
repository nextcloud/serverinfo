<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\History;

use OCA\ServerInfo\Db\Sample;

/**
 * The time ranges the history can be shown for, each with the finest
 * resolution that is still kept for its whole length.
 */
enum Range: string {
	case Day = '24h';
	case Week = '7d';
	case Month = '30d';
	case Year = '1y';

	public function seconds(): int {
		return match ($this) {
			self::Day => 86400,
			self::Week => 7 * 86400,
			self::Month => 30 * 86400,
			self::Year => 365 * 86400,
		};
	}

	public function resolution(): int {
		return match ($this) {
			self::Day => Sample::RESOLUTION_RAW,
			self::Week, self::Month => Sample::RESOLUTION_HOURLY,
			self::Year => Sample::RESOLUTION_DAILY,
		};
	}
}
