<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Persists daily snapshots of (free space + file count) and uses a
 * simple linear regression over the last samples to predict when
 * the system disk will fill up.
 *
 * Snapshots are stored in app config as JSON: list of {ts, free, files}.
 */
class DiskGrowth {
	private const HISTORY_KEY = 'storage_history';
	private const MAX_SAMPLES = 60;
	private const SAMPLE_INTERVAL = 12 * 3600;

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private StorageStatistics $storageStatistics,
	) {
	}

	/**
	 * Returns growth and prediction. Reads stored history; appends a
	 * new sample if enough time has passed since the last one.
	 *
	 * @return array{
	 *     samples: list<array{ts: int, freeBytes: int, files: int}>,
	 *     daysUntilFull: int,
	 *     bytesPerDay: int,
	 *     filesPerDay: int,
	 *     freeBytes: int,
	 *     hasEnoughData: bool
	 * }
	 */
	public function getGrowthInfo(): array {
		$history = $this->loadHistory();
		$now = time();
		$shouldSample = $history === [] || ($now - $history[count($history) - 1]['ts']) >= self::SAMPLE_INTERVAL;

		if ($shouldSample) {
			$dataDir = (string)$this->config->getSystemValue('datadirectory', '');
			$free = $dataDir !== '' ? @disk_free_space($dataDir) : false;
			$files = (int)($this->storageStatistics->getStorageStatistics()['num_files'] ?? 0);
			if ($free !== false && $free > 0) {
				$history[] = ['ts' => $now, 'freeBytes' => (int)$free, 'files' => $files];
				$history = array_slice($history, -self::MAX_SAMPLES);
				$this->saveHistory($history);
			}
		}

		$count = count($history);
		$current = $count > 0 ? $history[$count - 1] : ['freeBytes' => 0, 'files' => 0, 'ts' => $now];

		$bytesPerDay = 0;
		$filesPerDay = 0;
		$daysUntilFull = -1;
		$hasEnough = false;

		if ($count >= 2) {
			$first = $history[0];
			$last = $history[$count - 1];
			$dt = max(1, $last['ts'] - $first['ts']);
			$dayFactor = 86400 / $dt;
			// Negative bytesPerDay means free space is shrinking (i.e. usage growing).
			$bytesPerDay = (int)round(($last['freeBytes'] - $first['freeBytes']) * $dayFactor);
			$filesPerDay = (int)round(($last['files'] - $first['files']) * $dayFactor);
			$hasEnough = true;

			if ($bytesPerDay < 0) {
				$daysUntilFull = (int)round($last['freeBytes'] / abs($bytesPerDay));
			}
		}

		return [
			'samples' => $history,
			'daysUntilFull' => $daysUntilFull,
			'bytesPerDay' => $bytesPerDay,
			'filesPerDay' => $filesPerDay,
			'freeBytes' => (int)($current['freeBytes'] ?? 0),
			'hasEnoughData' => $hasEnough,
		];
	}

	/**
	 * @return list<array{ts: int, freeBytes: int, files: int}>
	 */
	private function loadHistory(): array {
		$raw = $this->appConfig->getValueString('serverinfo', self::HISTORY_KEY, '[]');
		try {
			$parsed = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			return [];
		}
		if (!is_array($parsed)) {
			return [];
		}
		$out = [];
		foreach ($parsed as $entry) {
			if (!is_array($entry)) continue;
			$out[] = [
				'ts' => (int)($entry['ts'] ?? 0),
				'freeBytes' => (int)($entry['freeBytes'] ?? 0),
				'files' => (int)($entry['files'] ?? 0),
			];
		}
		return $out;
	}

	private function saveHistory(array $history): void {
		try {
			$this->appConfig->setValueString('serverinfo', self::HISTORY_KEY, json_encode($history, JSON_THROW_ON_ERROR));
		} catch (\Throwable) {
			// best-effort; storage unavailable
		}
	}
}
