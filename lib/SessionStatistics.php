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

	/** Seconds per reported window, keyed by the output key. */
	private const WINDOWS = [
		'last5minutes' => self::OFFSET_5MIN,
		'last1hour' => self::OFFSET_1HOUR,
		'last24hours' => self::OFFSET_1DAY,
		'last7days' => self::OFFSET_7DAYS,
		'last1month' => self::OFFSET_1MONTH,
		'last3months' => self::OFFSET_3MONTHS,
		'last6months' => self::OFFSET_6MONTHS,
		'lastyear' => self::OFFSET_1YEAR,
	];

	private IDBConnection $connection;
	private ITimeFactory $timeFactory;

	public function __construct(IDBConnection $connection, ITimeFactory $timeFactory) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * Count the users whose last login falls into each window, in one pass
	 * over the lastLogin rows.
	 *
	 * @return array<string, int>
	 */
	public function getSessionStatistics(): array {
		$now = $this->timeFactory->getTime();
		$queryBuilder = $this->connection->getQueryBuilder();
		$lastLogin = $queryBuilder->expr()->castColumn('configvalue', IQueryBuilder::PARAM_INT);
		foreach (self::WINDOWS as $key => $offset) {
			$isActive = $queryBuilder->expr()->gte(
				$lastLogin,
				$queryBuilder->createNamedParameter($now - $offset, IQueryBuilder::PARAM_INT),
				IQueryBuilder::PARAM_INT,
			);
			$queryBuilder->selectAlias(
				$queryBuilder->func()->sum($queryBuilder->createFunction('CASE WHEN ' . $isActive . ' THEN 1 ELSE 0 END')),
				$key,
			);
		}
		$queryBuilder->from('preferences')
			->where($queryBuilder->expr()->eq('appid', $queryBuilder->createNamedParameter('login')))
			->andWhere($queryBuilder->expr()->eq('configkey', $queryBuilder->createNamedParameter('lastLogin')));

		$result = $queryBuilder->executeQuery();
		$row = $result->fetch() ?: [];
		$result->closeCursor();

		// SUM over no rows is NULL
		$statistics = [];
		foreach (array_keys(self::WINDOWS) as $key) {
			$statistics[$key] = (int)($row[$key] ?? 0);
		}
		return $statistics;
	}
}
