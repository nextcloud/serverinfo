<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Collector;

use OCA\ServerInfo\Collector\BackgroundJobs;
use OCP\BackgroundJob\IJobRuns;
use OCP\BackgroundJob\JobRun;
use OCP\BackgroundJob\JobStatus;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class BackgroundJobsTest extends \Test\TestCase {
	private IJobRuns&MockObject $jobRuns;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private IDBConnection&MockObject $connection;
	private LoggerInterface&MockObject $logger;
	private BackgroundJobs $instance;

	protected function setUp(): void {
		parent::setUp();

		$this->jobRuns = $this->createMock(IJobRuns::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueInt')->willReturn(60);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueArray')->willReturn([]);
		$this->connection = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->instance = new BackgroundJobs($this->jobRuns, $this->config, $this->appConfig, $this->connection, $this->logger);
	}

	private function jobRun(int $runId, JobStatus $status = JobStatus::SUCCEEDED): JobRun {
		return new JobRun(
			runId: $runId,
			className: 'OCA\\Files\\BackgroundJob\\ScanFiles',
			serverId: 1,
			pid: 42,
			startedAt: new \DateTimeImmutable('@1700000000'),
			status: $status,
			duration: 1234,
			memoryPeak: 5678,
		);
	}

	public function testSerialisesRuns(): void {
		$this->jobRuns->method('completedJobs')->willReturnCallback(
			fn () => (function () {
				yield $this->jobRun(1);
			})(),
		);

		$data = $this->instance->get();

		$this->assertSame([[
			'runId' => '1',
			'className' => 'OCA\\Files\\BackgroundJob\\ScanFiles',
			'serverId' => 1,
			'pid' => 42,
			'startedAt' => 1700000000,
			'status' => 'SUCCEEDED',
			'duration' => 1234,
			'memoryPeak' => 5678,
		]], $data['recent']);
		$this->assertSame($data['recent'], $data['failures']);
	}

	public function testReportsTheRetentionWindow(): void {
		$this->jobRuns->method('completedJobs')->willReturnCallback(
			fn () => (function () {
				yield from [];
			})(),
		);
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('getSystemValueInt')
			->with('background_jobs_expiration_days', 60)
			->willReturn(14);

		$instance = new BackgroundJobs($this->jobRuns, $config, $this->appConfig, $this->connection, $this->logger);

		$this->assertSame(14, $instance->get()['retentionDays']);
	}

	public function testQueriesFailuresSeparately(): void {
		$calls = [];
		$this->jobRuns->method('completedJobs')->willReturnCallback(
			function (array $statuses = [], array $classes = [], int $limit = 200) use (&$calls) {
				$calls[] = [$statuses, $limit];
				return (function () {
					yield from [];
				})();
			},
		);

		$this->instance->get();

		$this->assertSame([
			[[], 10],
			[[JobStatus::FAILED, JobStatus::CRASHED], 10],
		], $calls);
	}

	public function testEmptyResultIsAnEmptyList(): void {
		$this->jobRuns->method('completedJobs')->willReturnCallback(
			fn () => (function () {
				yield from [];
			})(),
		);

		$this->assertSame(['recent' => [], 'failures' => [], 'slowest' => [], 'retentionDays' => 60], $this->instance->get());
	}

	public function testUnresolvableJobClassKeepsEarlierRuns(): void {
		// The registry throws while the generator produces the row, which ends the
		// generator — everything yielded before it still has to come through.
		$this->jobRuns->method('completedJobs')->willReturnCallback(
			fn () => (function () {
				yield $this->jobRun(1);
				throw new \InvalidArgumentException('Unknown job class id');
			})(),
		);
		$this->logger->expects($this->exactly(2))->method('warning');

		$data = $this->instance->get();

		$this->assertCount(1, $data['recent']);
		$this->assertSame('1', $data['recent'][0]['runId']);
	}

	/**
	 * @param list<array<string, string>> $rows
	 */
	private function expectQueryReturning(array $rows): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);

		$query = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectAlias', 'from', 'innerJoin', 'where', 'groupBy'] as $method) {
			$query->method($method)->willReturnSelf();
		}
		$query->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
		$query->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$query->method('executeQuery')->willReturn($result);

		$this->connection->method('getQueryBuilder')->willReturn($query);
	}

	public function testCachesTheSlowestJobsByAverageDuration(): void {
		$this->expectQueryReturning([
			['class_name' => 'OCA\\Files\\BackgroundJob\\ScanFiles', 'runs' => '4', 'total_duration' => '400', 'max_duration' => '250', 'max_ram' => '2048'],
			['class_name' => 'OCA\\Talk\\BackgroundJob\\RemoveEmptyRooms', 'runs' => '2', 'total_duration' => '5000', 'max_duration' => '4000', 'max_ram' => '512'],
		]);

		$this->appConfig->expects($this->once())
			->method('setValueArray')
			->with('serverinfo', 'cached_slowest_jobs', [
				[
					'className' => 'OCA\\Talk\\BackgroundJob\\RemoveEmptyRooms',
					'runs' => 2,
					'avgDuration' => 2500,
					'maxDuration' => 4000,
					'memoryPeak' => 512,
				],
				[
					'className' => 'OCA\\Files\\BackgroundJob\\ScanFiles',
					'runs' => 4,
					'avgDuration' => 100,
					'maxDuration' => 250,
					'memoryPeak' => 2048,
				],
			]);

		$this->instance->updateSlowestJobs();
	}

	public function testCachesAtMostFiveJobs(): void {
		$this->expectQueryReturning(array_map(
			static fn (int $i): array => [
				'class_name' => 'OCA\\Files\\BackgroundJob\\Job' . $i,
				'runs' => '1',
				'total_duration' => (string)$i,
				'max_duration' => (string)$i,
				'max_ram' => '1',
			],
			range(1, 15),
		));

		$this->appConfig->expects($this->once())
			->method('setValueArray')
			->willReturnCallback(function (string $app, string $key, array $value): bool {
				$this->assertCount(5, $value);
				$this->assertSame(15, $value[0]['avgDuration']);
				return true;
			});

		$this->instance->updateSlowestJobs();
	}

	public function testUnresolvableJobClassOnTheFirstRow(): void {
		$this->jobRuns->method('completedJobs')->willReturnCallback(
			fn () => (function () {
				throw new \InvalidArgumentException('Unknown job class id');
				yield;
			})(),
		);

		$this->assertSame(['recent' => [], 'failures' => [], 'slowest' => [], 'retentionDays' => 60], $this->instance->get());
	}
}
