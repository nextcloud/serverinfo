<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OC\Memcache\ArrayCache;
use OCA\ServerInfo\JobQueueInfo;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\Server;
use OCP\Snowflake\ISnowflakeGenerator;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Runs against core's real jobs table, which already holds the jobs of the
 * test installation, so totals are compared before and after the fixtures.
 *
 * @group DB
 */
class JobQueueInfoTest extends \Test\TestCase {
	public const CLASS_PREFIX = 'OCA\\ServerInfo\\Tests\\QueueFixture\\';

	private IDBConnection $db;
	private ITimeFactory&MockObject $timeFactory;
	private ICacheFactory&MockObject $cacheFactory;
	private int $now;

	protected function setUp(): void {
		parent::setUp();

		$this->db = Server::get(IDBConnection::class);
		$this->now = time();
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('createDistributed')->willReturnCallback(static fn (): ArrayCache => new ArrayCache(''));
		self::removeFixtures($this->db);
	}

	protected function tearDown(): void {
		self::removeFixtures($this->db);
		parent::tearDown();
	}

	public static function removeFixtures(IDBConnection $db): void {
		$qb = $db->getQueryBuilder();
		$qb->delete('jobs')
			->where($qb->expr()->like('class', $qb->createNamedParameter($db->escapeLikeParameter(self::CLASS_PREFIX) . '%')));
		$qb->executeStatement();
	}

	/**
	 * @return string the id of the new job
	 */
	public static function addFixture(IDBConnection $db, string $class, int $reservedAt = 0, int $lastRun = 0, int $lastChecked = 0): string {
		$id = Server::get(ISnowflakeGenerator::class)->nextId();
		$argument = json_encode($id);
		$qb = $db->getQueryBuilder();
		$qb->insert('jobs')
			->values([
				'id' => $qb->createNamedParameter($id),
				'class' => $qb->createNamedParameter(self::CLASS_PREFIX . $class),
				'argument' => $qb->createNamedParameter($argument),
				'argument_hash' => $qb->createNamedParameter(hash('sha256', $argument)),
				'last_run' => $qb->createNamedParameter($lastRun, IQueryBuilder::PARAM_INT),
				'last_checked' => $qb->createNamedParameter($lastChecked, IQueryBuilder::PARAM_INT),
				'reserved_at' => $qb->createNamedParameter($reservedAt, IQueryBuilder::PARAM_INT),
			])
			->executeStatement();

		return $id;
	}

	private function info(): JobQueueInfo {
		return new JobQueueInfo($this->db, $this->timeFactory, $this->cacheFactory);
	}

	public function testCountsTheQueue(): void {
		$before = $this->info()->getJobQueueInfo();

		self::addFixture($this->db, 'Idle', lastRun: 2);
		self::addFixture($this->db, 'Idle');
		self::addFixture($this->db, 'Running', reservedAt: $this->now - 60, lastRun: 1);
		self::addFixture($this->db, 'Stuck', reservedAt: $this->now - JobQueueInfo::STUCK_THRESHOLD_SECONDS - 60);
		$after = $this->info()->getJobQueueInfo();

		$this->assertSame(4, $after['total'] - $before['total']);
		$this->assertSame(2, $after['reserved'] - $before['reserved']);
		$this->assertSame(1, $after['stuck'] - $before['stuck']);
		// A job that never ran does not count as the oldest last run
		$this->assertSame(1, $after['oldestLastRun']);
	}

	public function testAReservationAtTheThresholdIsNotStuckYet(): void {
		$before = $this->info()->getJobQueueInfo();

		self::addFixture($this->db, 'Stuck', reservedAt: $this->now - JobQueueInfo::STUCK_THRESHOLD_SECONDS);

		$this->assertSame($before['stuck'], $this->info()->getJobQueueInfo()['stuck']);
	}

	public function testListsTheLargestClassesFirst(): void {
		for ($i = 0; $i < 30; $i++) {
			self::addFixture($this->db, 'Many');
		}
		self::addFixture($this->db, 'Few');

		$top = $this->info()->getJobQueueInfo()['topClasses'];

		$this->assertLessThanOrEqual(5, count($top));
		$this->assertSame(['class' => self::CLASS_PREFIX . 'Many', 'count' => 30], $top[0]);
	}

	public function testListsReservedJobsLongestReservedFirst(): void {
		$recent = self::addFixture($this->db, 'Running', reservedAt: 2_002, lastRun: 5);
		$oldest = self::addFixture($this->db, 'Stuck', reservedAt: 1_001, lastRun: 4);
		self::addFixture($this->db, 'Idle');

		$reserved = array_values(array_filter(
			$this->info()->getJobQueueInfo()['reservedJobs'],
			static fn (array $job): bool => str_starts_with($job['class'], self::CLASS_PREFIX),
		));

		$this->assertSame([
			['id' => $oldest, 'class' => self::CLASS_PREFIX . 'Stuck', 'reservedAt' => 1_001, 'lastRun' => 4],
			['id' => $recent, 'class' => self::CLASS_PREFIX . 'Running', 'reservedAt' => 2_002, 'lastRun' => 5],
		], $reserved);
	}

	public function testCachesUntilInvalidated(): void {
		$info = $this->info();
		$before = $info->getJobQueueInfo();

		self::addFixture($this->db, 'Idle');
		$this->assertSame($before, $info->getJobQueueInfo());

		$info->invalidate();
		$this->assertSame($before['total'] + 1, $info->getJobQueueInfo()['total']);
	}

	public function testFindsTheJobOfClassesWithOnlyOne(): void {
		$sole = self::addFixture($this->db, 'Sole');
		self::addFixture($this->db, 'Twice');
		self::addFixture($this->db, 'Twice');

		$ids = $this->info()->soleJobIds([
			self::CLASS_PREFIX . 'Sole',
			self::CLASS_PREFIX . 'Twice',
			self::CLASS_PREFIX . 'Gone',
		]);

		$this->assertSame([self::CLASS_PREFIX . 'Sole' => $sole], $ids);
		$this->assertSame([], $this->info()->soleJobIds([]));
	}
}
