<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\History;

use OCA\ServerInfo\Controller\HistoryController;
use OCA\ServerInfo\Db\Sample;
use OCA\ServerInfo\Db\SampleMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\TestCase;

/**
 * @group DB
 */
class HistoryControllerTest extends TestCase {
	private const NOW = 1792411200;

	private SampleMapper $mapper;
	private HistoryController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = new SampleMapper(Server::get(IDBConnection::class));
		$this->clear();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->controller = new HistoryController('serverinfo', $this->createMock(IRequest::class), $this->mapper, $time);
	}

	protected function tearDown(): void {
		$this->clear();
		parent::tearDown();
	}

	private function clear(): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete(SampleMapper::TABLE)->executeStatement();
	}

	private function row(int $resolution, int $bucketStart, float $load1, string $node = 'web1'): void {
		$sample = new Sample();
		$sample->setNodeName($node);
		$sample->setResolution($resolution);
		$sample->setBucketStart($bucketStart);
		$sample->setSamples(1);
		$sample->setMetric('load1', $load1);
		$sample->setMetric('memTotal', 8000);
		$this->mapper->insert($sample);
	}

	public static function dataRanges(): array {
		return [
			['24h', Sample::RESOLUTION_RAW, 86400],
			['7d', Sample::RESOLUTION_HOURLY, 7 * 86400],
			['30d', Sample::RESOLUTION_HOURLY, 30 * 86400],
			['1y', Sample::RESOLUTION_DAILY, 365 * 86400],
		];
	}

	#[DataProvider('dataRanges')]
	public function testPicksTheResolutionKeptForTheWholeRange(string $range, int $resolution, int $seconds): void {
		$this->row($resolution, self::NOW - $seconds - $resolution, 9.0);
		$this->row($resolution, self::NOW - $seconds + $resolution, 1.0);
		$this->row($resolution, self::NOW - $resolution, 2.0);
		$other = $resolution === Sample::RESOLUTION_RAW ? Sample::RESOLUTION_HOURLY : Sample::RESOLUTION_RAW;
		$this->row($other, self::NOW - 600, 7.0);

		$data = $this->controller->series($range)->getData();

		$this->assertSame($range, $data['range']);
		$this->assertSame($resolution, $data['resolution']);
		$this->assertSame(self::NOW - $seconds, $data['from']);
		$this->assertSame(self::NOW, $data['now']);
		$this->assertSame([self::NOW - $seconds + $resolution, self::NOW - $resolution], $data['series']['t']);
		$this->assertEqualsWithDelta([1.0, 2.0], $data['series']['load1'], 0.0001);
		$this->assertSame([8000, 8000], $data['series']['memTotal']);
		$this->assertSame([null, null], $data['series']['diskFree']);
		$this->assertSame([1, 1], $data['series']['samples']);
	}

	public function testShowsTheNodeThatSampledLast(): void {
		$this->row(Sample::RESOLUTION_RAW, self::NOW - 900, 1.0, 'web1');
		$this->row(Sample::RESOLUTION_RAW, self::NOW - 600, 5.0, 'web2');
		$this->row(Sample::RESOLUTION_RAW, self::NOW - 300, 2.0, 'web1');

		$data = $this->controller->series('24h')->getData();

		$this->assertSame('web1', $data['node']);
		$this->assertSame([self::NOW - 900, self::NOW - 300], $data['series']['t']);
	}

	public function testEmptyBeforeTheFirstSample(): void {
		$data = $this->controller->series('7d')->getData();

		$this->assertNull($data['node']);
		$this->assertSame([], $data['series']['t']);
		$this->assertSame([], $data['series']['load1Max']);
	}

	public function testRejectsAnUnknownRange(): void {
		$response = $this->controller->series('2w');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['field' => 'range'], $response->getData());
	}

	public function testALimitKeepsTheNewestRows(): void {
		foreach ([900, 600, 300] as $age) {
			$this->row(Sample::RESOLUTION_RAW, self::NOW - $age, $age / 100);
		}

		$rows = $this->mapper->findSince(Sample::RESOLUTION_RAW, 0, 'web1', 2);

		$this->assertSame([self::NOW - 600, self::NOW - 300], array_map(fn (Sample $row): int => $row->getBucketStart(), $rows));
	}
}
