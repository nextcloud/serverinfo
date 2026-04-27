<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IDBConnection;

class ActiveConnections {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Approximate active sessions/connections by counting auth tokens
	 * with recent activity. Each token corresponds to one client (browser
	 * tab, mobile app, desktop client, etc.).
	 *
	 * @return array{
	 *     last5min: int,
	 *     last1h: int,
	 *     totalTokens: int,
	 *     byType: array<string, int>
	 * }
	 */
	public function getActiveConnections(): array {
		try {
			return [
				'last5min' => $this->countSince(time() - 300),
				'last1h' => $this->countSince(time() - 3600),
				'totalTokens' => $this->countTotal(),
				'byType' => $this->byType(),
			];
		} catch (\Throwable) {
			return ['last5min' => 0, 'last1h' => 0, 'totalTokens' => 0, 'byType' => []];
		}
	}

	private function countSince(int $ts): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('authtoken')
			->where($qb->expr()->gte('last_activity', $qb->createNamedParameter($ts)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function countTotal(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from('authtoken');
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @return array<string, int>
	 */
	private function byType(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('type')
			->selectAlias($qb->func()->count('id'), 'count')
			->from('authtoken')
			->where($qb->expr()->gte('last_activity', $qb->createNamedParameter(time() - 3600)))
			->groupBy('type');
		$result = $qb->executeQuery();
		$out = ['session' => 0, 'permanent' => 0];
		while (($row = $result->fetch()) !== false) {
			$type = (int)($row['type'] ?? 0) === 0 ? 'session' : 'permanent';
			$out[$type] = (int)($row['count'] ?? 0);
		}
		$result->closeCursor();
		return $out;
	}
}
