<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\App\IAppManager;
use OCP\IDBConnection;

class ActivityRate {
	public function __construct(
		private IAppManager $appManager,
		private IDBConnection $db,
	) {
	}

	/**
	 * Counts user-facing activity events from the activity app over
	 * recent time windows. Useful as a "is anything happening on the
	 * server right now" signal.
	 *
	 * @return array{
	 *     installed: bool,
	 *     last1h: int,
	 *     last24h: int,
	 *     last7d: int,
	 *     topActions: list<array{action: string, count: int}>
	 * }
	 */
	public function getActivityRate(): array {
		if (!$this->appManager->isInstalled('activity')) {
			return ['installed' => false, 'last1h' => 0, 'last24h' => 0, 'last7d' => 0, 'topActions' => []];
		}

		try {
			return [
				'installed' => true,
				'last1h' => $this->countSince(time() - 3600),
				'last24h' => $this->countSince(time() - 86400),
				'last7d' => $this->countSince(time() - 7 * 86400),
				'topActions' => $this->topActions(),
			];
		} catch (\Throwable) {
			return ['installed' => true, 'last1h' => 0, 'last24h' => 0, 'last7d' => 0, 'topActions' => []];
		}
	}

	private function countSince(int $ts): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('activity_id'))
			->from('activity')
			->where($qb->expr()->gte('timestamp', $qb->createNamedParameter($ts)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @return list<array{action: string, count: int}>
	 */
	private function topActions(int $limit = 5): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('subjectparams', 'type')
			->selectAlias($qb->func()->count('activity_id'), 'count')
			->from('activity')
			->where($qb->expr()->gte('timestamp', $qb->createNamedParameter(time() - 86400)))
			->groupBy('type', 'subjectparams')
			->orderBy('count', 'DESC')
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = [
				'action' => (string)($row['type'] ?? 'unknown'),
				'count' => (int)($row['count'] ?? 0),
			];
		}
		$result->closeCursor();
		return $out;
	}
}
