<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\History;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\Db\Sample;
use OCA\ServerInfo\Db\SampleMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;

/**
 * Turns the five-minute samples into hourly and daily rows and removes rows
 * past their retention.
 *
 * Both resolutions are computed from the five-minute samples, so averages
 * and peaks are exact. A bucket is recomputed on every run while all of its
 * samples are still kept, including the current, unfinished one, so the
 * longer ranges show the latest hour or day within five minutes. Once its
 * first samples have expired the bucket is left alone, which makes runs
 * repeatable and keeps a paused cron from shrinking a finished bucket.
 *
 * @psalm-api
 */
class Downsampler {
	/**
	 * A daily bucket is only complete while all of its samples still exist,
	 * so they are kept for at least a day and an hour.
	 */
	public const MIN_RAW_RETENTION_HOURS = 25;

	public function __construct(
		private SampleMapper $mapper,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
	) {
	}

	public function run(): void {
		$now = $this->timeFactory->getTime();
		$this->rollUp($now, Sample::RESOLUTION_HOURLY);
		$this->rollUp($now, Sample::RESOLUTION_DAILY);
		$this->purge($now);
	}

	private function rollUp(int $now, int $resolution): void {
		$from = (int)ceil(($now - $this->rawRetention()) / $resolution) * $resolution;

		$groups = [];
		foreach ($this->mapper->findSince(Sample::RESOLUTION_RAW, $from) as $sample) {
			$bucketStart = intdiv($sample->getBucketStart(), $resolution) * $resolution;
			$groups[$sample->getNodeName() . "\n" . $bucketStart][] = $sample;
		}

		$existing = [];
		foreach ($this->mapper->findSince($resolution, $from) as $row) {
			$existing[$row->getNodeName() . "\n" . $row->getBucketStart()] = $row;
		}

		foreach ($groups as $key => $samples) {
			$row = $existing[$key] ?? null;
			// Samples are only ever added, so an unchanged count means unchanged values.
			if ($row !== null && $row->getSamples() === count($samples)) {
				continue;
			}

			$target = $row ?? new Sample();
			if ($row === null) {
				$target->setNodeName($samples[0]->getNodeName());
				$target->setResolution($resolution);
				$target->setBucketStart(intdiv($samples[0]->getBucketStart(), $resolution) * $resolution);
			}
			self::aggregate($samples, $target);

			if ($row !== null) {
				$this->mapper->update($target);
				continue;
			}
			try {
				$this->mapper->insert($target);
			} catch (Exception $e) {
				// A parallel run wrote the bucket; the next run brings it up to date.
				if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
			}
		}
	}

	/**
	 * Averages of the samples and the highest value of each peak metric.
	 * Missing values are left out; a metric no sample has stays null.
	 *
	 * @param non-empty-list<Sample> $samples
	 */
	public static function aggregate(array $samples, Sample $target): void {
		$target->setSamples(count($samples));
		foreach (Sample::METRICS as $metric) {
			$values = self::values($samples, $metric);
			if ($values === []) {
				$target->setMetric($metric, null);
				continue;
			}
			$average = (float)array_sum($values) / (float)count($values);
			$target->setMetric($metric, Sample::isFloat($metric) ? $average : (int)round($average));
		}
		foreach (Sample::PEAKS as $peak => $metric) {
			$values = self::values($samples, $metric);
			$target->setMetric($peak, $values === [] ? null : max($values));
		}
	}

	/**
	 * @param list<Sample> $samples
	 * @return list<int|float>
	 */
	private static function values(array $samples, string $metric): array {
		$values = [];
		foreach ($samples as $sample) {
			$value = $sample->getMetric($metric);
			if ($value !== null) {
				$values[] = $value;
			}
		}

		return $values;
	}

	private function purge(int $now): void {
		$this->mapper->deleteBefore(Sample::RESOLUTION_RAW, $now - $this->rawRetention());
		$this->mapper->deleteBefore(Sample::RESOLUTION_HOURLY, $now - $this->days(ConfigLexicon::HISTORY_RETENTION_HOURLY_DAYS) * 86400);
		$this->mapper->deleteBefore(Sample::RESOLUTION_DAILY, $now - $this->days(ConfigLexicon::HISTORY_RETENTION_DAILY_DAYS) * 86400);
	}

	private function rawRetention(): int {
		$hours = $this->appConfig->getAppValueInt(ConfigLexicon::HISTORY_RETENTION_RAW_HOURS);
		return max(self::MIN_RAW_RETENTION_HOURS, $hours) * 3600;
	}

	private function days(string $key): int {
		return max(1, $this->appConfig->getAppValueInt($key));
	}
}
