<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Sample>
 * @psalm-api
 */
class SampleMapper extends QBMapper {
	public const TABLE = 'serverinfo_samples';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Sample::class);
	}

	/**
	 * Rows of one resolution from $from on, oldest first, for every node or
	 * for one. With a limit, the newest rows are kept.
	 *
	 * @return list<Sample>
	 */
	public function findSince(int $resolution, int $from, ?string $node = null, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('resolution', $qb->createNamedParameter($resolution, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('bucket_start', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->orderBy('bucket_start', 'DESC');
		if ($node !== null) {
			$qb->andWhere($qb->expr()->eq('node_name', $qb->createNamedParameter($node)));
		}
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		return array_reverse($this->findEntities($qb));
	}

	/**
	 * The node that wrote the newest row of a resolution, null while there is none.
	 */
	public function latestNode(int $resolution): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('node_name')
			->from(self::TABLE)
			->where($qb->expr()->eq('resolution', $qb->createNamedParameter($resolution, IQueryBuilder::PARAM_INT)))
			->orderBy('bucket_start', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$node = $result->fetchOne();
		$result->closeCursor();

		return $node === false ? null : (string)$node;
	}

	public function hasBucket(int $resolution, int $bucketStart, string $node): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->eq('resolution', $qb->createNamedParameter($resolution, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('bucket_start', $qb->createNamedParameter($bucketStart, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('node_name', $qb->createNamedParameter($node)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();

		return $found;
	}

	public function deleteBefore(int $resolution, int $before): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('resolution', $qb->createNamedParameter($resolution, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('bucket_start', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}
}
