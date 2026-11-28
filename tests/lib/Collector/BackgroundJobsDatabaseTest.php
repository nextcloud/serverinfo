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
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * @group DB
 */
class BackgroundJobsDatabaseTest extends TestCase {
	public function testAggregatesRegisteredCompletedRunsAndOrdersTiesByClassId(): void {
		$connection = Server::get(IDBConnection::class);
		$connection->beginTransaction();

		try {
			// The collector ranks all retained runs. Isolate the fixture until rollback.
			$connection->getQueryBuilder()->delete('job_runs')->executeStatement();
			$connection->getQueryBuilder()->delete('job_classes_registry')->executeStatement();

			foreach ([10001 => 'ZTestJob', 10002 => 'ATestJob'] as $classId => $className) {
				$connection->executeStatement(
					'INSERT INTO *PREFIX*job_classes_registry (class_id, class_name) VALUES (?, ?)',
					[$classId, $className],
				);
			}

			// Run ID, class ID, status, duration, peak memory.
			$runs = [
				[10001, 10001, JobStatus::SUCCEEDED, 100, 500],
				[10002, 10001, JobStatus::FAILED, 101, 200],
				[10003, 10002, JobStatus::CRASHED, 101, 300],
				[10004, 10001, JobStatus::RUNNING, 0, 0],
				[10005, 10003, JobStatus::SUCCEEDED, 1000, 1000],
			];
			foreach ($runs as [$runId, $classId, $status, $duration, $memoryPeak]) {
				$connection->executeStatement(
					'INSERT INTO *PREFIX*job_runs (run_id, class_id, pid, status, duration, ram_peak_usage) VALUES (?, ?, 1, ?, ?, ?)',
					[$runId, $classId, $status->value, $duration, $memoryPeak],
				);
			}

			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->expects($this->once())
				->method('setValueArray')
				->with('serverinfo', 'cached_slowest_jobs', [
					['className' => 'ZTestJob', 'runs' => 2, 'avgDuration' => 101, 'maxDuration' => 101, 'memoryPeak' => 500],
					['className' => 'ATestJob', 'runs' => 1, 'avgDuration' => 101, 'maxDuration' => 101, 'memoryPeak' => 300],
				]);

			$instance = new BackgroundJobs(
				$this->createMock(IJobRuns::class),
				$this->createMock(IConfig::class),
				$appConfig,
				$connection,
				$this->createMock(LoggerInterface::class),
			);
			$instance->updateSlowestJobs();
		} finally {
			$connection->rollBack();
		}
	}
}
