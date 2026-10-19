<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Host;

/**
 * What a finished command printed and how it exited.
 */
final class CommandResult {
	public function __construct(
		public readonly int $exitCode,
		public readonly string $stdout,
		public readonly string $stderr = '',
		/** The command ran into its time limit and was killed */
		public readonly bool $timedOut = false,
		/** The output was longer than the runner keeps, so it is cut off */
		public readonly bool $truncated = false,
	) {
	}

	/**
	 * Exited cleanly with its complete output; a partial list of updates is
	 * worse than none.
	 */
	public function succeeded(): bool {
		return $this->exitCode === 0 && !$this->timedOut && !$this->truncated;
	}
}
