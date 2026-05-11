<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\SlowestJobs;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class SlowestJobsTest extends \Test\TestCase {
	private IDBConnection&MockObject $db;
	private LoggerInterface&MockObject $logger;
	private SlowestJobs $instance;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->instance = new SlowestJobs($this->db, $this->logger);
	}

	public function testGetSlowestJobsReturnsResults(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(
			['class' => 'OC\Files\BackgroundJob\ScanFiles', 'count' => '12', 'avg_dur' => '4.5', 'max_dur' => '9'],
			false,
		);
		$result->expects($this->once())->method('closeCursor');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('selectAlias')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('groupBy')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('func')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class));
		$qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('getColumnName')->willReturnArgument(0);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);

		$jobs = $this->instance->getSlowestJobs();

		$this->assertCount(1, $jobs);
		$this->assertSame('OC\Files\BackgroundJob\ScanFiles', $jobs[0]['class']);
		$this->assertSame(12, $jobs[0]['count']);
		$this->assertSame(5, $jobs[0]['avgSeconds']);
		$this->assertSame(9, $jobs[0]['maxSeconds']);
	}

	public function testGetSlowestJobsLogsAndReturnsEmptyOnFailure(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('selectAlias')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('groupBy')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('func')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class));
		$qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('getColumnName')->willReturnArgument(0);
		$qb->method('createFunction')->willReturnArgument(0);
		$qb->method('executeQuery')->willThrowException(new \RuntimeException('DB error'));

		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->logger->expects($this->once())
			->method('warning')
			->with('Failed to query slowest jobs', $this->arrayHasKey('exception'));

		$jobs = $this->instance->getSlowestJobs();

		$this->assertSame([], $jobs);
	}
}
