<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IConfig;

class LogTailReader {
	private const READ_CHUNK = 96 * 1024;

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * Tail the Nextcloud JSON log and return the last $limit entries
	 * with severity >= $minLevel (default: WARN = 2). Skips DEBUG/INFO.
	 *
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

		$path = $this->resolvePath();
		if ($path === null || !is_readable($path)) {
			return ['entries' => [], 'available' => false, 'reason' => 'log_not_readable'];
		}

		$tail = $this->tailFile($path, self::READ_CHUNK);
		if ($tail === '') {
			return ['entries' => [], 'available' => true];
		}

		$lines = explode("\n", $tail);
		$collected = [];
		// Iterate from newest to oldest.
		for ($i = count($lines) - 1; $i >= 0 && count($collected) < $limit; $i--) {
			$line = trim($lines[$i]);
			if ($line === '') {
				continue;
			}
			$decoded = json_decode($line, true);
			if (!is_array($decoded)) {
				continue;
			}
			$level = isset($decoded['level']) ? (int)$decoded['level'] : 0;
			if ($level < $minLevel) {
				continue;
			}
			$collected[] = [
				'time' => (string)($decoded['time'] ?? ''),
				'level' => $level,
				'app' => (string)($decoded['app'] ?? ''),
				'message' => $this->snippet((string)($decoded['message'] ?? '')),
			];
		}

		return ['entries' => $collected, 'available' => true];
	}

	private function resolvePath(): ?string {
		$dataDir = $this->config->getSystemValue('datadirectory', '');
		$default = $dataDir !== '' ? rtrim($dataDir, '/') . '/nextcloud.log' : '';
		$logFile = $this->config->getSystemValue('logfile', $default);
		if (!is_string($logFile) || $logFile === '') {
			return null;
		}
		return $logFile;
	}

	private function tailFile(string $path, int $chunk): string {
		$size = @filesize($path);
		if ($size === false || $size === 0) {
			return '';
		}
		$handle = @fopen($path, 'rb');
		if ($handle === false) {
			return '';
		}
		try {
			$readFrom = max(0, $size - $chunk);
			fseek($handle, $readFrom);
			$data = fread($handle, $chunk) ?: '';
			// Drop the leading partial line so JSON parsing doesn't choke.
			if ($readFrom > 0) {
				$nl = strpos($data, "\n");
				if ($nl !== false) {
					$data = substr($data, $nl + 1);
				}
			}
			return $data;
		} finally {
			fclose($handle);
		}
	}

	private function snippet(string $msg, int $max = 200): string {
		$msg = trim($msg);
		if (function_exists('mb_strlen') && mb_strlen($msg) > $max) {
			return mb_substr($msg, 0, $max - 1) . '…';
		}
		if (strlen($msg) > $max) {
			return substr($msg, 0, $max - 1) . '…';
		}
		return $msg;
	}
}
