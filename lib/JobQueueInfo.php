<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IDBConnection;

class JobQueueInfo {
	private const STUCK_THRESHOLD_SECONDS = 12 * 3600;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array{
	 *     total: int,
	 *     reserved: int,
	 *     stuck: int,
	 *     oldestLastRun: int,
	 *     topClasses: list<array{class: string, count: int}>
	 * }
	 */
	public function getJobQueueInfo(): array {
		return [
			'total' => $this->countTotal(),
			'reserved' => $this->countReserved(),
			'stuck' => $this->countStuck(),
			'oldestLastRun' => $this->oldestLastRun(),
			'topClasses' => $this->topClasses(5),
		];
	}

	private function countTotal(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('jobs');
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function countReserved(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('jobs')
			->where($qb->expr()->gt('reserved_at', $qb->createNamedParameter(0)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function countStuck(): int {
		$threshold = time() - self::STUCK_THRESHOLD_SECONDS;
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('jobs')
			->where($qb->expr()->gt('reserved_at', $qb->createNamedParameter(0)))
			->andWhere($qb->expr()->lt('reserved_at', $qb->createNamedParameter($threshold)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function oldestLastRun(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->min('last_run'))
			->from('jobs')
			->where($qb->expr()->gt('last_run', $qb->createNamedParameter(0)));
		$result = $qb->executeQuery();
		$min = $result->fetchOne();
		$result->closeCursor();
		return $min === false || $min === null ? 0 : (int)$min;
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
}
