<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\App\IAppManager;
use OCP\IDBConnection;

class ExternalStoragesInfo {
	public function __construct(
		private IAppManager $appManager,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return array{
	 *     installed: bool,
	 *     count: int,
	 *     mounts: list<array{name: string, backend: string, scope: string}>
	 * }
	 */
	public function getExternalStorages(): array {
		if (!$this->appManager->isInstalled('files_external')) {
			return ['installed' => false, 'count' => 0, 'mounts' => []];
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('mount_id', 'mount_point', 'storage_backend', 'auth_backend', 'type')
				->from('external_mounts')
				->setMaxResults(50);
			$result = $qb->executeQuery();
			$mounts = [];
			while (($row = $result->fetch()) !== false) {
				$mounts[] = [
					'name' => $this->prettyMountPoint((string)($row['mount_point'] ?? '')),
					'backend' => $this->shortBackend((string)($row['storage_backend'] ?? '')),
					'scope' => (int)($row['type'] ?? 1) === 2 ? 'user' : 'admin',
				];
			}
			$result->closeCursor();
			return ['installed' => true, 'count' => count($mounts), 'mounts' => $mounts];
		} catch (\Throwable) {
			return ['installed' => true, 'count' => 0, 'mounts' => []];
		}
	}

	private function prettyMountPoint(string $mp): string {
		$mp = trim($mp, '/');
		return $mp === '' ? '/' : $mp;
	}

	private function shortBackend(string $backend): string {
		// Strip leading provider prefix, e.g. "smb" or "amazons3"
		$parts = explode('::', $backend);
		$tail = end($parts) ?: $backend;
		return ucwords(str_replace(['_', '-'], ' ', $tail));
	}
}
