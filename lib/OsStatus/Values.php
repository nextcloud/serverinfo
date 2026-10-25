<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

/**
 * Typed reads from decoded JSON, which may hold anything.
 */
final class Values {
	public static function string(mixed $value): ?string {
		return is_string($value) ? $value : null;
	}

	public static function int(mixed $value): ?int {
		return is_int($value) ? $value : null;
	}

	/**
	 * @return list<string>
	 */
	public static function strings(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}

		$strings = [];
		foreach ($value as $item) {
			if (is_string($item)) {
				$strings[] = $item;
			}
		}

		return $strings;
	}
}
