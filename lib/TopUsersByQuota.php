<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\IDBConnection;

class TopUsersByQuota {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Returns the top users by storage size from the per-user "home::"
	 * storages. Reads directly from the filecache to avoid loading all
	 * users into memory.
	 *
	 * @return list<array{user: string, sizeBytes: int}>
	 */
	public function getTopUsers(int $limit = 10): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('s.id', 'fc.size')
				->from('storages', 's')
				->innerJoin('s', 'filecache', 'fc', $qb->expr()->andX(
					$qb->expr()->eq('fc.storage', 's.numeric_id'),
					$qb->expr()->eq('fc.path', $qb->createNamedParameter('files'))
				))
				->where($qb->expr()->like('s.id', $qb->createNamedParameter('home::%')))
				->orderBy('fc.size', 'DESC')
				->setMaxResults($limit);
			$result = $qb->executeQuery();
			$out = [];
			while (($row = $result->fetch()) !== false) {
				$id = (string)($row['id'] ?? '');
				$user = str_starts_with($id, 'home::') ? substr($id, 6) : $id;
				$out[] = [
					'user' => $user,
					'sizeBytes' => max(0, (int)($row['size'] ?? 0)),
				];
			}
			$result->closeCursor();
			return $out;
		} catch (\Throwable) {
			return [];
		}
	}
}
