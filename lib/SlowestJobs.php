<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IDBConnection;

class SlowestJobs {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Returns the job classes with the highest average execution time
	 * (last_run - last_checked when reservations are released). Pulls
	 * the columns directly because there is no public API.
	 *
	 * @return list<array{class: string, count: int, avgSeconds: int, maxSeconds: int}>
	 */
	public function getSlowestJobs(int $limit = 5): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('class')
			->selectAlias($qb->func()->count('id'), 'count')
			->selectAlias($qb->createFunction('AVG(' . $qb->getColumnName('execution_duration') . ')'), 'avg_dur')
			->selectAlias($qb->createFunction('MAX(' . $qb->getColumnName('execution_duration') . ')'), 'max_dur')
			->from('jobs')
			->where($qb->expr()->gt('execution_duration', $qb->createNamedParameter(0)))
			->groupBy('class')
			->orderBy('avg_dur', 'DESC')
			->setMaxResults($limit);
		try {
			$result = $qb->executeQuery();
		} catch (\Throwable) {
			return [];
		}
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = [
				'class' => (string)($row['class'] ?? ''),
				'count' => (int)($row['count'] ?? 0),
				'avgSeconds' => (int)round((float)($row['avg_dur'] ?? 0)),
				'maxSeconds' => (int)($row['max_dur'] ?? 0),
			];
		}
		$result->closeCursor();
		return $out;
	}
}
