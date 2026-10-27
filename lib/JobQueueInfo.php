<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;

/**
 * The state of the background job queue, read from core's `jobs` table.
 *
 * The table has indexes on `class` and on (`last_checked`, `reserved_at`),
 * none on `last_run`, so the totals take one full pass and are cached briefly.
 *
 * @psalm-type ServerInfoReservedJob = array{
 *     id: string,
 *     class: string,
 *     reservedAt: int,
 *     lastRun: int
 * }
 * @psalm-type ServerInfoJobQueue = array{
 *     total: int,
 *     reserved: int,
 *     stuck: int,
 *     oldestLastRun: int,
 *     topClasses: list<array{class: string, count: int}>,
 *     reservedJobs: list<ServerInfoReservedJob>
 * }
 *
 * @psalm-api
 */
class JobQueueInfo {
	/** Cron takes a job again once its reservation is this old, see JobList::getNext() */
	public const STUCK_THRESHOLD_SECONDS = 12 * 3600;
	private const TOP_CLASSES_LIMIT = 5;
	private const RESERVED_JOBS_LIMIT = 20;
	private const CACHE_KEY = 'queue';
	private const CACHE_TTL = 60;

	private ICache $cache;

	public function __construct(
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('serverinfo-jobs');
	}

	/**
	 * @return ServerInfoJobQueue
	 */
	public function getJobQueueInfo(): array {
		/** @var ServerInfoJobQueue|null $cached */
		$cached = $this->cache->get(self::CACHE_KEY);
		if (is_array($cached)) {
			return $cached;
		}

		$info = $this->counts() + [
			'topClasses' => $this->topClasses(self::TOP_CLASSES_LIMIT),
			'reservedJobs' => $this->reservedJobs(self::RESERVED_JOBS_LIMIT),
		];
		$this->cache->set(self::CACHE_KEY, $info, self::CACHE_TTL);

		return $info;
	}

	/**
	 * Drops the cached numbers, so that a change made from the page shows on
	 * its next load.
	 */
	public function invalidate(): void {
		$this->cache->remove(self::CACHE_KEY);
	}

	/**
	 * The id of the job of each class that has exactly one job queued.
	 *
	 * @param list<string> $classes
	 * @return array<string, string> job id by class
	 */
	public function soleJobIds(array $classes): array {
		if ($classes === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('class')
			->selectAlias($qb->func()->count('id'), 'jobs')
			->selectAlias($qb->func()->min('id'), 'job_id')
			->from('jobs')
			->where($qb->expr()->in('class', $qb->createNamedParameter($classes, IQueryBuilder::PARAM_STR_ARRAY)))
			->groupBy('class');
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			if ((int)$row['jobs'] === 1) {
				$ids[(string)$row['class']] = (string)$row['job_id'];
			}
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Total, reserved and stuck jobs and the oldest last run, in one pass.
	 *
	 * @return array{total: int, reserved: int, stuck: int, oldestLastRun: int}
	 */
	private function counts(): array {
		$qb = $this->db->getQueryBuilder();
		$reserved = $qb->expr()->gt('reserved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT));
		$stuck = $qb->expr()->gt('reserved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			. ' AND ' . $qb->expr()->lt('reserved_at', $qb->createNamedParameter($this->timeFactory->getTime() - self::STUCK_THRESHOLD_SECONDS, IQueryBuilder::PARAM_INT));
		// A job that never ran has a last_run of 0
		$hasRun = $qb->expr()->gt('last_run', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT));

		$qb->selectAlias($qb->func()->count(), 'total')
			->selectAlias($qb->func()->sum($qb->createFunction('CASE WHEN ' . $reserved . ' THEN 1 ELSE 0 END')), 'reserved')
			->selectAlias($qb->func()->sum($qb->createFunction('CASE WHEN ' . $stuck . ' THEN 1 ELSE 0 END')), 'stuck')
			->selectAlias($qb->func()->min($qb->createFunction('CASE WHEN ' . $hasRun . ' THEN ' . $qb->getColumnName('last_run') . ' END')), 'oldest_last_run')
			->from('jobs');
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			$row = [];
		}

		return [
			'total' => (int)($row['total'] ?? 0),
			'reserved' => (int)($row['reserved'] ?? 0),
			'stuck' => (int)($row['stuck'] ?? 0),
			'oldestLastRun' => (int)($row['oldest_last_run'] ?? 0),
		];
	}

	/**
	 * @return list<array{class: string, count: int}>
	 */
	private function topClasses(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('class')
			->selectAlias($qb->func()->count('id'), 'count')
			->from('jobs')
			->groupBy('class')
			->orderBy('count', 'DESC')
			->addOrderBy('class')
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = [
				'class' => (string)($row['class'] ?? ''),
				'count' => (int)($row['count'] ?? 0),
			];
		}
		$result->closeCursor();
		return $out;
	}

	/**
	 * The reserved jobs, longest reserved first.
	 *
	 * @return list<ServerInfoReservedJob>
	 */
	private function reservedJobs(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'class', 'reserved_at', 'last_run')
			->from('jobs')
			->where($qb->expr()->gt('reserved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('reserved_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = [
				// Snowflake ids exceed what JavaScript can hold exactly
				'id' => (string)$row['id'],
				'class' => (string)$row['class'],
				'reservedAt' => (int)$row['reserved_at'],
				'lastRun' => (int)$row['last_run'],
			];
		}
		$result->closeCursor();
		return $out;
	}
}
