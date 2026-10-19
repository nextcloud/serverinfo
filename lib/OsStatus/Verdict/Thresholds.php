<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Verdict;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCP\AppFramework\Services\IAppConfig;

/**
 * How long each finding may stand before it becomes a warning or an error.
 * All values are in days.
 */
final class Thresholds {
	/** Upper bound for any threshold: ten years. */
	public const MAX_DAYS = 3650;

	/** setting key => config key */
	private const KEYS = [
		'securityWarning' => ConfigLexicon::OS_SECURITY_WARNING_DAYS,
		'securityError' => ConfigLexicon::OS_SECURITY_ERROR_DAYS,
		'rebootWarning' => ConfigLexicon::OS_REBOOT_WARNING_DAYS,
		'rebootError' => ConfigLexicon::OS_REBOOT_ERROR_DAYS,
		'restartWarning' => ConfigLexicon::OS_RESTART_WARNING_DAYS,
		'eolWarning' => ConfigLexicon::OS_EOL_WARNING_DAYS,
		'listsWarning' => ConfigLexicon::OS_LISTS_WARNING_DAYS,
		'listsError' => ConfigLexicon::OS_LISTS_ERROR_DAYS,
	];

	public function __construct(
		public readonly int $securityWarning = 3,
		public readonly int $securityError = 14,
		public readonly int $rebootWarning = 7,
		public readonly int $rebootError = 30,
		public readonly int $restartWarning = 3,
		public readonly int $eolWarning = 90,
		public readonly int $listsWarning = 7,
		public readonly int $listsError = 30,
	) {
	}

	public static function load(IAppConfig $appConfig): self {
		$values = [];
		foreach (self::KEYS as $name => $key) {
			$values[$name] = max(0, min(self::MAX_DAYS, $appConfig->getAppValueInt($key)));
		}

		return new self(...$values);
	}
}
