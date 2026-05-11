<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\JobQueueInfo;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;

class JobQueueInfoTest extends \Test\TestCase {
	private IDBConnection&MockObject $db;
	private JobQueueInfo $instance;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->instance = new JobQueueInfo($this->db);
	}

	private function makeQb(mixed $fetchOneReturn = null, array $fetchRows = []): IQueryBuilder&MockObject {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('gt')->willReturn('1=1');
		$expr->method('lt')->willReturn('1=1');

		$queryFunction = $this->createMock(IQueryFunction::class);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($queryFunction);
		$func->method('min')->willReturn($queryFunction);

		$result = $this->createMock(IResult::class);
		if ($fetchOneReturn !== null) {
			$result->method('fetchOne')->willReturn($fetchOneReturn);
		}
		if (!empty($fetchRows)) {
			$result->method('fetch')->willReturnOnConsecutiveCalls(...$fetchRows);
		}

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('selectAlias')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('groupBy')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}

	public function testGetJobQueueInfo(): void {
		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->makeQb(fetchOneReturn: '42'),   // countTotal
			$this->makeQb(fetchOneReturn: '3'),    // countReserved
			$this->makeQb(fetchOneReturn: '1'),    // countStuck
			$this->makeQb(fetchOneReturn: '1700000000'), // oldestLastRun
			$this->makeQb(fetchRows: [            // topClasses
				['class' => 'OC\Files\BackgroundJob\ScanFiles', 'count' => '30'],
				false,
			]),
		);

		$info = $this->instance->getJobQueueInfo();

		$this->assertSame(42, $info['total']);
		$this->assertSame(3, $info['reserved']);
		$this->assertSame(1, $info['stuck']);
		$this->assertSame(1700000000, $info['oldestLastRun']);
		$this->assertCount(1, $info['topClasses']);
		$this->assertSame('OC\Files\BackgroundJob\ScanFiles', $info['topClasses'][0]['class']);
		$this->assertSame(30, $info['topClasses'][0]['count']);
	}

	public function testOldestLastRunReturnsZeroWhenNoJobs(): void {
		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->makeQb(fetchOneReturn: '0'),
			$this->makeQb(fetchOneReturn: '0'),
			$this->makeQb(fetchOneReturn: '0'),
			$this->makeQb(fetchOneReturn: false),
			$this->makeQb(fetchRows: [false]),
		);

		$info = $this->instance->getJobQueueInfo();

		$this->assertSame(0, $info['oldestLastRun']);
		$this->assertSame([], $info['topClasses']);
	}
}
