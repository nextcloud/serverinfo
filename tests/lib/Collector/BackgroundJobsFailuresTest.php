<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Collector;

use OCA\ServerInfo\Collector\BackgroundJobs;
use OCP\BackgroundJob\IJobRuns;
use OCP\BackgroundJob\JobStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use OCP\Snowflake\ISnowflakeDecoder;
use OCP\Snowflake\ISnowflakeGenerator;
use Psr\Log\LoggerInterface;

/**
 * Runs against core's job_runs and job_classes_registry tables. The fixture
 * runs lie in the future, so they sort before any run the installation made.
 *
 * @group DB
 */
class BackgroundJobsFailuresTest extends \Test\TestCase {
	private const CLASS_PREFIX = 'OCA\\ServerInfo\\Tests\\FailureFixture\\';

	private IDBConnection $db;
	private ISnowflakeGenerator $snowflakes;
	/** @var list<string> */
	private array $classIds = [];
	private int $base;

	protected function setUp(): void {
		parent::setUp();

		$this->db = Server::get(IDBConnection::class);
		$this->snowflakes = Server::get(ISnowflakeGenerator::class);
		$this->base = time() + 86400 * 365;
	}

	protected function tearDown(): void {
		if ($this->classIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('job_runs')
				->where($qb->expr()->in('class_id', $qb->createNamedParameter($this->classIds, IQueryBuilder::PARAM_STR_ARRAY)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('job_classes_registry')
				->where($qb->expr()->in('class_id', $qb->createNamedParameter($this->classIds, IQueryBuilder::PARAM_STR_ARRAY)))
				->executeStatement();
		}
		parent::tearDown();
	}

	private function addClass(string $name): string {
		$id = $this->snowflakes->nextId();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('job_classes_registry')
			->values([
				'class_id' => $qb->createNamedParameter($id),
				'class_name' => $qb->createNamedParameter(self::CLASS_PREFIX . $name),
			])
			->executeStatement();
		$this->classIds[] = $id;

		return $id;
	}

	private function addRun(string $classId, JobStatus $status, int $startedAt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('job_runs')
			->values([
				'run_id' => $qb->createNamedParameter($this->snowflakes->nextId(new \DateTimeImmutable('@' . $startedAt))),
				'class_id' => $qb->createNamedParameter($classId),
				'pid' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'status' => $qb->createNamedParameter($status->value, IQueryBuilder::PARAM_INT),
			])
			->executeStatement();
	}

	private function collector(): BackgroundJobs {
		return new BackgroundJobs(
			$this->createMock(IJobRuns::class),
			$this->createMock(IConfig::class),
			$this->createMock(IAppConfig::class),
			$this->db,
			Server::get(ISnowflakeDecoder::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testGroupsFailuresByClassLatestFirst(): void {
		$flaky = $this->addClass('Flaky');
		$broken = $this->addClass('Broken');
		$fine = $this->addClass('Fine');
		$this->addRun($flaky, JobStatus::FAILED, $this->base + 10);
		$this->addRun($flaky, JobStatus::SUCCEEDED, $this->base + 50);
		$this->addRun($broken, JobStatus::FAILED, $this->base + 20);
		$this->addRun($broken, JobStatus::CRASHED, $this->base + 30);
		$this->addRun($broken, JobStatus::FAILED, $this->base + 5);
		$this->addRun($fine, JobStatus::SUCCEEDED, $this->base + 60);
		$this->addRun($fine, JobStatus::RUNNING, $this->base + 70);

		$failures = array_values(array_filter(
			$this->collector()->failuresByClass(),
			static fn (array $failure): bool => str_starts_with($failure['className'], self::CLASS_PREFIX),
		));

		$this->assertSame([
			['className' => self::CLASS_PREFIX . 'Broken', 'failures' => 3, 'lastFailedAt' => $this->base + 30],
			['className' => self::CLASS_PREFIX . 'Flaky', 'failures' => 1, 'lastFailedAt' => $this->base + 10],
		], $failures);
	}

	public function testListsAtMostTwentyClasses(): void {
		for ($i = 0; $i < 25; $i++) {
			$this->addRun($this->addClass('Job' . $i), JobStatus::FAILED, $this->base + $i);
		}

		$failures = $this->collector()->failuresByClass();

		$this->assertCount(20, $failures);
		$this->assertSame(self::CLASS_PREFIX . 'Job24', $failures[0]['className']);
	}
}
