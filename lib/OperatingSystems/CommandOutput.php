<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OperatingSystems;

use OCA\ServerInfo\OsStatus\Host\IHost;
use Psr\Log\LoggerInterface;

/**
 * Runs the tools the operating-system backends read their values from:
 * without a shell, with a time limit and with the host's output cap.
 */
class CommandOutput {
	/** Seconds a quick query such as nproc or sysctl may take. */
	public const TIMEOUT = 2;

	/** Seconds df may take; a stale network mount makes it hang. */
	public const DF_TIMEOUT = 5;

	public function __construct(
		private IHost $host,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What a command printed.
	 *
	 * Output of a command that exits with an error is still used: df does so
	 * when a single mount point is inaccessible, yet lists all the others.
	 *
	 * @param list<string> $arguments
	 * @param bool $partial whether the complete lines printed before the command
	 *                      was stopped are still of use
	 * @return string|null null when there is no output to use
	 */
	public function read(string $binary, array $arguments, int $timeout = self::TIMEOUT, bool $partial = false): ?string {
		$result = $this->host->run($binary, $arguments, $timeout);
		if ($result === null) {
			$this->logger->debug('Could not run {command}', ['app' => 'serverinfo', 'command' => $binary]);
			return null;
		}

		$output = $result->stdout;
		if ($result->timedOut || $result->truncated || $result->readFailed) {
			$this->logger->warning('{command} did not finish: {reason}', [
				'app' => 'serverinfo',
				'command' => trim($binary . ' ' . implode(' ', $arguments)),
				'reason' => $result->failure($timeout),
			]);

			$end = strrpos($output, "\n");
			$output = $partial && $end !== false ? substr($output, 0, $end + 1) : '';
		}

		return $output !== '' ? $output : null;
	}
}
