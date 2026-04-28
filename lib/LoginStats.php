<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IDBConnection;

class LoginStats {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array{
	 *     bruteforceAttempts24h: int,
	 *     bruteforceAttempts1h: int,
	 *     bruteforceTotal: int,
	 *     topIps: list<array{ip: string, count: int}>,
	 *     available: bool
	 * }
	 */
	public function getStats(): array {
		try {
			$total = $this->countAttempts();
		} catch (\Throwable) {
			return [
				'bruteforceAttempts24h' => 0,
				'bruteforceAttempts1h' => 0,
				'bruteforceTotal' => 0,
				'topIps' => [],
				'available' => false,
			];
		}

		return [
			'bruteforceAttempts24h' => $this->countAttempts(time() - 86400),
			'bruteforceAttempts1h' => $this->countAttempts(time() - 3600),
			'bruteforceTotal' => $total,
			'topIps' => $this->topIps(),
			'available' => true,
		];
	}

	private function countAttempts(?int $sinceTimestamp = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('bruteforce_attempts');
		if ($sinceTimestamp !== null) {
			$qb->where($qb->expr()->gte('occurred', $qb->createNamedParameter($sinceTimestamp)));
		}
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @return list<array{ip: string, count: int}>
	 */
	private function topIps(int $limit = 5): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('ip')
			->selectAlias($qb->func()->count('id'), 'count')
			->from('bruteforce_attempts')
			->where($qb->expr()->gte('occurred', $qb->createNamedParameter(time() - 86400)))
			->groupBy('ip')
			->orderBy('count', 'DESC')
			->setMaxResults($limit);
		try {
			$result = $qb->executeQuery();
		} catch (\Throwable) {
			return [];
		}
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = [
				'ip' => (string)($row['ip'] ?? ''),
				'count' => (int)($row['count'] ?? 0),
			];
		}
		$result->closeCursor();
		return $out;
	}
}
