<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The two changes an admin can make to a queued background job. Neither
 * runs job code: cron does that, on its next run.
 *
 * IJobList::unlockJob() and resetBackgroundJob() take an IJob, and
 * IJobList::getById() builds one, which deletes the job when its class no
 * longer exists. Both also update the row unconditionally. These updates
 * apply only while the reservation is the one the admin saw, so a job that
 * cron took in the meantime is left alone.
 *
 * @psalm-type ServerInfoQueuedJob = array{id: string, class: string, reservedAt: int}
 *
 * @psalm-api
 */
class JobQueueActions {
	public function __construct(
		private IJobList $jobList,
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return ServerInfoQueuedJob|null
	 */
	public function find(string $id): ?array {
		$row = $this->jobList->getDetailsById($id);
		if ($row === null) {
			return null;
		}

		return [
			'id' => (string)$row['id'],
			'class' => (string)$row['class'],
			'reservedAt' => (int)$row['reserved_at'],
		];
	}

	/**
	 * Reserved so long ago that cron no longer counts the reservation.
	 *
	 * @param ServerInfoQueuedJob $job
	 */
	public function isStuck(array $job): bool {
		return $job['reservedAt'] > 0
			&& $job['reservedAt'] < $this->timeFactory->getTime() - JobQueueInfo::STUCK_THRESHOLD_SECONDS;
	}

	/**
	 * Reserved recently enough that it may be running right now.
	 *
	 * @param ServerInfoQueuedJob $job
	 */
	public function isReserved(array $job): bool {
		return $job['reservedAt'] > 0 && !$this->isStuck($job);
	}

	/**
	 * Clears the reservation, as IJobList::unlockJob() does. The last run is
	 * kept, so a timed job keeps its schedule.
	 *
	 * @param ServerInfoQueuedJob $job
	 * @return bool false when the reservation changed since it was read
	 */
	public function release(array $job): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update('jobs')
			->set('reserved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($job['id'])))
			->andWhere($qb->expr()->eq('reserved_at', $qb->createNamedParameter($job['reservedAt'], IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() === 1;
	}

	/**
	 * Makes the job due and puts it first in line for the next cron run.
	 *
	 * Like IJobList::resetBackgroundJob() this clears the last run, so a timed
	 * job counts as due, and the reservation. It also clears `last_checked`:
	 * cron moves that up to two days ahead for a timed job that is not due
	 * yet, and only takes jobs whose `last_checked` has passed, oldest first.
	 *
	 * @param ServerInfoQueuedJob $job
	 * @return bool false when the reservation changed since it was read
	 */
	public function runAtNextCron(array $job): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update('jobs')
			->set('last_run', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('last_checked', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('reserved_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($job['id'])))
			->andWhere($qb->expr()->eq('reserved_at', $qb->createNamedParameter($job['reservedAt'], IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement() === 1;
	}
}
