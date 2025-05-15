<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class SessionStatistics
 *
 * get active users
 *
 * @group DB
 * @package OCA\ServerInfo
 */
class SessionStatistics {
	private const OFFSET_5MIN = 300;
	private const OFFSET_1HOUR = 3600;
	private const OFFSET_1DAY = 86400;
	private const OFFSET_7DAYS = 604800;
	private const OFFSET_1MONTH = 2592000;
	private const OFFSET_3MONTHS = 7776000;
	private const OFFSET_6MONTHS = 15552000;
	private const OFFSET_1YEAR = 31536000;

	private IDBConnection $connection;
	private ITimeFactory $timeFactory;

	public function __construct(IDBConnection $connection, ITimeFactory $timeFactory) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
	}

	public function getSessionStatistics(): array {
		return [
			'last5minutes' => $this->getNumberOfActiveUsers(self::OFFSET_5MIN),
			'last1hour' => $this->getNumberOfActiveUsers(self::OFFSET_1HOUR),
			'last24hours' => $this->getNumberOfActiveUsers(self::OFFSET_1DAY),
			'last7days' => $this->getNumberOfActiveUsers(self::OFFSET_7DAYS),
			'last1month' => $this->getNumberOfActiveUsers(self::OFFSET_1MONTH),
			'last3months' => $this->getNumberOfActiveUsers(self::OFFSET_3MONTHS),
			'last6months' => $this->getNumberOfActiveUsers(self::OFFSET_6MONTHS),
			'lastyear' => $this->getNumberOfActiveUsers(self::OFFSET_1YEAR),
		];
	}

	/**
	 * get number of active user in a given time span
	 *
	 * @param int $offset seconds
	 */
	private function getNumberOfActiveUsers(int $offset): int {
		$queryBuilder = $this->connection->getQueryBuilder();
		$queryBuilder->select($queryBuilder->func()->count('userid'))
			->from('preferences')
			->where($queryBuilder->expr()->eq('appid', $queryBuilder->createNamedParameter('login')))
			->andWhere($queryBuilder->expr()->eq('configkey', $queryBuilder->createNamedParameter('lastLogin')))
			->andwhere($queryBuilder->expr()->gte(
				$queryBuilder->expr()->castColumn('configvalue', IQueryBuilder::PARAM_INT),
				$queryBuilder->createNamedParameter($this->timeFactory->getTime() - $offset, IQueryBuilder::PARAM_INT),
				IQueryBuilder::PARAM_INT,
			));

		$result = $queryBuilder->executeQuery();
		$activeUsers = (int)$result->fetchOne();
		$result->closeCursor();

		return $activeUsers;
	}
}
