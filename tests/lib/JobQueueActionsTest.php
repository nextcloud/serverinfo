<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\JobQueueActions;
use OCA\ServerInfo\JobQueueInfo;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @group DB
 */
class JobQueueActionsTest extends \Test\TestCase {
	private IDBConnection $db;
	private ITimeFactory&MockObject $timeFactory;
	private JobQueueActions $actions;
	private int $now;

	protected function setUp(): void {
		parent::setUp();

		$this->db = Server::get(IDBConnection::class);
		$this->now = time();
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->actions = new JobQueueActions(Server::get(IJobList::class), $this->db, $this->timeFactory);
		JobQueueInfoTest::removeFixtures($this->db);
	}

	protected function tearDown(): void {
		JobQueueInfoTest::removeFixtures($this->db);
		parent::tearDown();
	}

	/**
	 * @return array{last_run: int, last_checked: int, reserved_at: int}
	 */
	private function row(string $id): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('last_run', 'last_checked', 'reserved_at')
			->from('jobs')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$this->assertIsArray($row);

		return array_map('intval', $row);
	}

	private function stuckSince(): int {
		return $this->now - JobQueueInfo::STUCK_THRESHOLD_SECONDS - 60;
	}

	public function testFindsAJobWithoutBuildingIt(): void {
		// The class does not exist: building the job would delete it
		$id = JobQueueInfoTest::addFixture($this->db, 'Missing', reservedAt: 1234);

		$this->assertSame(['id' => $id, 'class' => JobQueueInfoTest::CLASS_PREFIX . 'Missing', 'reservedAt' => 1234], $this->actions->find($id));
		$this->assertSame(1234, $this->row($id)['reserved_at']);
	}

	public function testUnknownJobIsNotFound(): void {
		$this->assertNull($this->actions->find('1'));
	}

	public function testTellsStuckFromReservedJobs(): void {
		$idle = $this->actions->find(JobQueueInfoTest::addFixture($this->db, 'Idle'));
		$running = $this->actions->find(JobQueueInfoTest::addFixture($this->db, 'Running', reservedAt: $this->now - 600));
		$stuck = $this->actions->find(JobQueueInfoTest::addFixture($this->db, 'Stuck', reservedAt: $this->stuckSince()));
		$this->assertNotNull($idle);
		$this->assertNotNull($running);
		$this->assertNotNull($stuck);

		$this->assertFalse($this->actions->isStuck($idle));
		$this->assertFalse($this->actions->isReserved($idle));
		$this->assertFalse($this->actions->isStuck($running));
		$this->assertTrue($this->actions->isReserved($running));
		$this->assertTrue($this->actions->isStuck($stuck));
		$this->assertFalse($this->actions->isReserved($stuck));
	}

	public function testReleaseClearsOnlyTheReservation(): void {
		$id = JobQueueInfoTest::addFixture($this->db, 'Stuck', reservedAt: $this->stuckSince(), lastRun: 100, lastChecked: 200);
		$job = $this->actions->find($id);
		$this->assertNotNull($job);

		$this->assertTrue($this->actions->release($job));

		$this->assertSame(['last_run' => 100, 'last_checked' => 200, 'reserved_at' => 0], $this->row($id));
	}

	public function testRunAtNextCronMakesTheJobDueAndFirstInLine(): void {
		$id = JobQueueInfoTest::addFixture($this->db, 'Timed', lastRun: $this->now - 60, lastChecked: $this->now + 86400);
		$job = $this->actions->find($id);
		$this->assertNotNull($job);

		$this->assertTrue($this->actions->runAtNextCron($job));

		$this->assertSame(['last_run' => 0, 'last_checked' => 0, 'reserved_at' => 0], $this->row($id));
	}

	public function testRunAtNextCronClearsAStuckReservation(): void {
		$id = JobQueueInfoTest::addFixture($this->db, 'Stuck', reservedAt: $this->stuckSince(), lastRun: 100);
		$job = $this->actions->find($id);
		$this->assertNotNull($job);

		$this->assertTrue($this->actions->runAtNextCron($job));

		$this->assertSame(0, $this->row($id)['reserved_at']);
	}

	public function testRunAtNextCronOnADueJobStillSucceeds(): void {
		$id = JobQueueInfoTest::addFixture($this->db, 'Due');
		$job = $this->actions->find($id);
		$this->assertNotNull($job);

		$this->assertTrue($this->actions->runAtNextCron($job));
	}

	public function testLeavesAJobAloneThatCronReservedInTheMeantime(): void {
		$id = JobQueueInfoTest::addFixture($this->db, 'Stuck', reservedAt: $this->stuckSince(), lastRun: 100);
		$job = $this->actions->find($id);
		$this->assertNotNull($job);

		$qb = $this->db->getQueryBuilder();
		$qb->update('jobs')
			->set('reserved_at', $qb->createNamedParameter($this->now))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->executeStatement();

		$this->assertFalse($this->actions->release($job));
		$this->assertFalse($this->actions->runAtNextCron($job));
		$this->assertSame(['last_run' => 100, 'last_checked' => 0, 'reserved_at' => $this->now], $this->row($id));
	}
}
