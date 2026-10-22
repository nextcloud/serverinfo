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
		/** Waiting for output failed, so the output may be incomplete */
		public readonly bool $readFailed = false,
	) {
	}

	/**
	 * Exited cleanly with its complete output; a partial list of updates is
	 * worse than none.
	 */
	public function succeeded(): bool {
		return $this->exitCode === 0 && !$this->timedOut && !$this->truncated && !$this->readFailed;
	}

	/**
	 * Why the command did not succeed, for a diagnostic: the runner's own
	 * reason when it stopped the command, otherwise the first line it printed.
	 *
	 * @param int $timeout the time limit the command was run with, in seconds
	 */
	public function failure(int $timeout): string {
		if ($this->timedOut) {
			return sprintf('timed out after %d s', $timeout);
		}
		if ($this->truncated) {
			return 'output too large';
		}
		if ($this->readFailed) {
			return 'output could not be read';
		}

		$line = mb_substr(trim((string)strtok(trim($this->stderr ?: $this->stdout), "\n")), 0, 200);

		return $line !== '' ? $line : sprintf('exit code %d', $this->exitCode);
	}
}
