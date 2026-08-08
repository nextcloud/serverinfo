<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Collector;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\Config\ConfigLexicon;
use OCP\BackgroundJob\IJobRuns;
use OCP\BackgroundJob\JobRun;
use OCP\BackgroundJob\JobStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * @psalm-type ServerInfoJobRun = array{
 *     runId: string,
 *     className: string,
 *     serverId: int,
 *     pid: int,
 *     startedAt: int,
 *     status: string,
 *     duration: int|null,
 *     memoryPeak: int|null
 * }
 * @psalm-type ServerInfoSlowJob = array{
 *     className: string,
 *     runs: int,
 *     avgDuration: int,
 *     maxDuration: int,
 *     memoryPeak: int
 * }
 */
class BackgroundJobs {
	private const LIMIT = 10;
	private const SLOWEST_LIMIT = 5;

	public function __construct(
		private IJobRuns $jobRuns,
		private IConfig $config,
		private IAppConfig $appConfig,
		private IDBConnection $connection,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Failures are queried separately: completedJobs() is ordered by run id, so on
	 * a busy instance a failure scrolls out of the newest rows within seconds.
	 *
	 * @return array{
	 *     recent: list<ServerInfoJobRun>,
	 *     failures: list<ServerInfoJobRun>,
	 *     slowest: list<ServerInfoSlowJob>,
	 *     retentionDays: int
	 * }
	 */
	public function get(): array {
		/** @var list<ServerInfoSlowJob> $slowest */
		$slowest = $this->appConfig->getValueArray(Application::APP_ID, ConfigLexicon::CACHED_SLOWEST_JOBS);

		return [
			'recent' => $this->collect($this->jobRuns->completedJobs(limit: self::LIMIT)),
			'failures' => $this->collect(
				$this->jobRuns->completedJobs([JobStatus::FAILED, JobStatus::CRASHED], limit: self::LIMIT),
			),
			'slowest' => $slowest,
			'retentionDays' => $this->config->getSystemValueInt('background_jobs_expiration_days', 60),
		];
	}

	/**
	 * Aggregates every retained run per job class: too expensive for a request, so
	 * a background job caches the result and get() only reads it back.
	 */
	public function updateSlowestJobs(): void {
		$query = $this->connection->getQueryBuilder();
		$query->select('c.class_name')
			->selectAlias($query->func()->count(), 'runs')
			->selectAlias($query->func()->sum('r.duration'), 'total_duration')
			->selectAlias($query->func()->max('r.duration'), 'max_duration')
			->selectAlias($query->func()->max('r.ram_peak_usage'), 'max_ram')
			->from('job_runs', 'r')
			// A run whose class is gone from the registry drops out of the join
			->innerJoin('r', 'job_classes_registry', 'c', $query->expr()->eq('r.class_id', 'c.class_id'))
			// A running job has not reported its duration yet
			->where($query->expr()->neq('r.status', $query->createNamedParameter(JobStatus::RUNNING->value, IQueryBuilder::PARAM_INT)))
			->groupBy('r.class_id', 'c.class_name');

		$result = $query->executeQuery();
		$stats = [];
		while ($row = $result->fetch()) {
			$runs = max(1, (int)$row['runs']);
			$stats[] = [
				'className' => (string)$row['class_name'],
				'runs' => $runs,
				'avgDuration' => (int)round((int)$row['total_duration'] / $runs),
				'maxDuration' => (int)$row['max_duration'],
				'memoryPeak' => (int)$row['max_ram'],
			];
		}
		$result->closeCursor();

		// One row per job class, so sorting here beats a portable ORDER BY over an
		// aggregate.
		usort($stats, static fn (array $a, array $b): int => $b['avgDuration'] <=> $a['avgDuration']);

		$this->appConfig->setValueArray(Application::APP_ID, ConfigLexicon::CACHED_SLOWEST_JOBS, array_slice($stats, 0, self::SLOWEST_LIMIT));
	}

	/**
	 * @return list<ServerInfoJobRun>
	 */
	private function collect(\Generator $runs): array {
		$out = [];

		try {
			/** @var JobRun $run */
			foreach ($runs as $run) {
				$out[] = [
					// Snowflake ids exceed what JavaScript can hold exactly
					'runId' => (string)$run->runId,
					'className' => $run->className,
					'serverId' => $run->serverId,
					'pid' => $run->pid,
					'startedAt' => $run->startedAt->getTimestamp(),
					'status' => $run->status->name,
					'duration' => $run->duration,
					'memoryPeak' => $run->memoryPeak,
				];
			}
		} catch (\InvalidArgumentException $e) {
			// An unresolvable class id throws inside the generator, and a
			// generator that threw cannot be resumed. Earlier rows still count.
			$this->logger->warning('Stopped listing job runs at an unresolvable job class', ['exception' => $e]);
		}

		return $out;
	}
}
