<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IConfig;
use OCP\Log\IFileBased;
use OCP\Log\ILogFactory;

class LogTailReader {
	public function __construct(
		private IConfig $config,
		private ILogFactory $logFactory,
	) {
	}

	/**
	 * @return array{
	 *     entries: list<array{time: string, level: int, app: string, message: string}>,
	 *     available: bool,
	 *     reason?: string
	 * }
	 */
	public function recentErrors(int $limit = 8, int $minLevel = 2): array {
		$logType = $this->config->getSystemValue('log_type', 'file');
		if ($logType !== 'file') {
			return ['entries' => [], 'available' => false, 'reason' => 'log_type_not_file'];
		}

		$log = $this->logFactory->get('file');
		if (!($log instanceof IFileBased)) {
			return ['entries' => [], 'available' => false, 'reason' => 'log_not_readable'];
		}

		$raw = $log->getEntries($limit * 10);
		$collected = [];
		foreach ($raw as $entry) {
			if (count($collected) >= $limit) {
				break;
			}
			$level = (int)($entry['level'] ?? 0);
			if ($level < $minLevel) {
				continue;
			}
			$collected[] = [
				'time' => (string)($entry['time'] ?? ''),
				'level' => $level,
				'app' => (string)($entry['app'] ?? ''),
				'message' => $this->snippet((string)($entry['message'] ?? '')),
			];
		}

		return ['entries' => $collected, 'available' => true];
	}

	private function snippet(string $msg, int $max = 200): string {
		$msg = trim($msg);
		if (mb_strlen($msg) > $max) {
			return mb_substr($msg, 0, $max - 1) . '…';
		}
		return $msg;
	}
}
