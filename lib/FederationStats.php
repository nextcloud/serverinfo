<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\Share\IShare;

class FederationStats {
	public function __construct(
		private IAppManager $appManager,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array{
	 *     enabled: bool,
	 *     sharesSent: int,
	 *     sharesReceived: int,
	 *     sharesSentToGroups: int,
	 *     trustedServers: int,
	 *     topPeers: list<array{server: string, count: int}>
	 * }
	 */
	public function getFederationStats(): array {
		$enabled = $this->appManager->isInstalled('federation') || $this->appManager->isInstalled('federatedfilesharing');

		try {
			$sent = $this->countShareType(IShare::TYPE_REMOTE);
			$sentGroup = $this->countShareType(IShare::TYPE_REMOTE_GROUP);
			$received = $this->countReceived();
			$trusted = $this->countTrustedServers();
			$top = $this->topPeers();
		} catch (\Throwable) {
			$sent = 0;
			$sentGroup = 0;
			$received = 0;
			$trusted = 0;
			$top = [];
		}

		return [
			'enabled' => $enabled,
			'sharesSent' => $sent,
			'sharesSentToGroups' => $sentGroup,
			'sharesReceived' => $received,
			'trustedServers' => $trusted,
			'topPeers' => $top,
		];
	}

	private function countShareType(int $type): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('share')
			->where($qb->expr()->eq('share_type', $qb->createNamedParameter($type)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	private function countReceived(): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('id'))->from('share_external');
			$result = $qb->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();
			return $count;
		} catch (\Throwable) {
			return 0;
		}
	}

	private function countTrustedServers(): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('id'))->from('trusted_servers');
			$result = $qb->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();
			return $count;
		} catch (\Throwable) {
			return 0;
		}
	}

	/**
	 * @return list<array{server: string, count: int}>
	 */
	private function topPeers(int $limit = 5): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('share_with')
				->selectAlias($qb->func()->count('id'), 'count')
				->from('share')
				->where($qb->expr()->in('share_type', $qb->createNamedParameter(
					[IShare::TYPE_REMOTE, IShare::TYPE_REMOTE_GROUP],
					\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY,
				)))
				->groupBy('share_with')
				->orderBy('count', 'DESC')
				->setMaxResults($limit);
			$result = $qb->executeQuery();
			$out = [];
			while (($row = $result->fetch()) !== false) {
				$with = (string)($row['share_with'] ?? '');
				$at = strrpos($with, '@');
				$server = $at !== false ? substr($with, $at + 1) : $with;
				$out[] = [
					'server' => $server,
					'count' => (int)($row['count'] ?? 0),
				];
			}
			$result->closeCursor();
			return $out;
		} catch (\Throwable) {
			return [];
		}
	}
}
