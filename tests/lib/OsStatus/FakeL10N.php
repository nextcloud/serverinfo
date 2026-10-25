<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCP\IL10N;

/**
 * English without translations, formatted the way the server's IL10N does
 * it: %n becomes the count, then vsprintf() with the parameters. Nothing
 * else, so a {placeholder} stays as it is.
 */
class FakeL10N implements IL10N {
	#[\Override]
	public function t(string $text, $parameters = []): string {
		return vsprintf($text, is_array($parameters) ? $parameters : [$parameters]);
	}

	#[\Override]
	public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
		return vsprintf(str_replace('%n', (string)$count, $count === 1 ? $text_singular : $text_plural), $parameters);
	}

	#[\Override]
	public function l(string $type, $data, array $options = []) {
		return false;
	}

	#[\Override]
	public function getLanguageCode(): string {
		return 'en';
	}

	#[\Override]
	public function getLocaleCode(): string {
		return 'en';
	}
}
