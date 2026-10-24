<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\History;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\Db\Sample;
use OCA\ServerInfo\Db\SampleMapper;
use OCA\ServerInfo\History\Downsampler;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * @group DB
 */
class DownsamplerTest extends TestCase {
	/** A day boundary, so hours and days line up with the samples below. */
	private const DAY = 1792368000;

	private SampleMapper $mapper;
	private ITimeFactory&MockObject $time;
	private IAppConfig&MockObject $appConfig;
	private array $retention;
	private Downsampler $downsampler;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = new SampleMapper(Server::get(IDBConnection::class));
		$this->clear();
		$this->time = $this->createMock(ITimeFactory::class);
		$this->retention = [
			ConfigLexicon::HISTORY_RETENTION_RAW_HOURS => 48,
			ConfigLexicon::HISTORY_RETENTION_HOURLY_DAYS => 30,
			ConfigLexicon::HISTORY_RETENTION_DAILY_DAYS => 365,
		];
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValueInt')->willReturnCallback(fn (string $key): int => $this->retention[$key]);
		$this->downsampler = new Downsampler($this->mapper, $this->appConfig, $this->time);
	}

	protected function tearDown(): void {
		$this->clear();
		parent::tearDown();
	}

	private function clear(): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete(SampleMapper::TABLE)->executeStatement();
	}

	private function raw(int $bucketStart, ?float $load1, ?int $memUsed, string $node = 'web1'): void {
		$sample = new Sample();
		$sample->setNodeName($node);
		$sample->setResolution(Sample::RESOLUTION_RAW);
		$sample->setBucketStart($bucketStart);
		$sample->setSamples(1);
		$sample->setMetric('load1', $load1);
		$sample->setMetric('load1Max', $load1);
		$sample->setMetric('memUsed', $memUsed);
		$sample->setMetric('memUsedMax', $memUsed);
		$sample->setMetric('memTotal', 1000);
		$this->mapper->insert($sample);
	}

	private function at(int $now): void {
		$this->time->method('getTime')->willReturn($now);
	}

	/**
	 * @return array<int, Sample> by bucket start
	 */
	private function rows(int $resolution): array {
		$rows = [];
		foreach ($this->mapper->findSince($resolution, 0) as $row) {
			$rows[$row->getBucketStart()] = $row;
		}
		return $rows;
	}

	public function testAveragesAndPeaksPerHourAndDay(): void {
		$this->raw(self::DAY + 10 * 3600, 1.0, 100);
		$this->raw(self::DAY + 10 * 3600 + 300, 2.0, 200);
		$this->raw(self::DAY + 10 * 3600 + 600, 3.0, null);
		$this->raw(self::DAY + 11 * 3600, 6.0, 400);
		$this->at(self::DAY + 11 * 3600 + 120);

		$this->downsampler->run();

		$hourly = $this->rows(Sample::RESOLUTION_HOURLY);
		$this->assertSame([self::DAY + 10 * 3600, self::DAY + 11 * 3600], array_keys($hourly));
		$ten = $hourly[self::DAY + 10 * 3600];
		$this->assertSame(3, $ten->getSamples());
		$this->assertEqualsWithDelta(2.0, $ten->getMetric('load1'), 0.0001);
		$this->assertEqualsWithDelta(3.0, $ten->getMetric('load1Max'), 0.0001);
		$this->assertSame(150, $ten->getMetric('memUsed'));
		$this->assertSame(200, $ten->getMetric('memUsedMax'));
		$this->assertSame(1000, $ten->getMetric('memTotal'));
		$this->assertNull($ten->getMetric('diskFree'));
		$this->assertSame('web1', $ten->getNodeName());

		$daily = $this->rows(Sample::RESOLUTION_DAILY);
		$this->assertSame([self::DAY], array_keys($daily));
		$this->assertSame(4, $daily[self::DAY]->getSamples());
		$this->assertEqualsWithDelta(3.0, $daily[self::DAY]->getMetric('load1'), 0.0001);
		$this->assertEqualsWithDelta(6.0, $daily[self::DAY]->getMetric('load1Max'), 0.0001);
		$this->assertSame(233, $daily[self::DAY]->getMetric('memUsed'));
	}

	public function testRepeatedRunsChangeNothingAndNewSamplesUpdateTheBucket(): void {
		$this->raw(self::DAY + 3600, 1.0, 100);
		$this->at(self::DAY + 3600 + 400);

		$this->downsampler->run();
		$first = $this->rows(Sample::RESOLUTION_HOURLY)[self::DAY + 3600];
		$this->downsampler->run();
		$again = $this->rows(Sample::RESOLUTION_HOURLY);
		$this->assertCount(1, $again);
		$this->assertSame($first->getId(), $again[self::DAY + 3600]->getId());

		$this->raw(self::DAY + 3600 + 300, 3.0, 300);
		$this->downsampler->run();
		$updated = $this->rows(Sample::RESOLUTION_HOURLY)[self::DAY + 3600];
		$this->assertSame($first->getId(), $updated->getId());
		$this->assertSame(2, $updated->getSamples());
		$this->assertEqualsWithDelta(2.0, $updated->getMetric('load1'), 0.0001);
		$this->assertCount(1, $this->rows(Sample::RESOLUTION_DAILY));
	}

	public function testNodesAreKeptApart(): void {
		$this->raw(self::DAY, 1.0, 100, 'web1');
		$this->raw(self::DAY, 5.0, 500, 'web2');
		$this->at(self::DAY + 600);

		$this->downsampler->run();

		$rows = $this->mapper->findSince(Sample::RESOLUTION_HOURLY, 0);
		$this->assertCount(2, $rows);
		$byNode = [];
		foreach ($rows as $row) {
			$byNode[$row->getNodeName()] = $row->getMetric('memUsed');
		}
		ksort($byNode);
		$this->assertSame(['web1' => 100, 'web2' => 500], $byNode);
	}

	public function testExpiresEachResolutionAfterItsRetention(): void {
		$now = self::DAY + 400 * 86400;
		$this->raw($now - 49 * 3600, 1.0, 100);
		$this->raw($now - 47 * 3600, 1.0, 100);
		foreach ([[Sample::RESOLUTION_HOURLY, $now - 31 * 86400], [Sample::RESOLUTION_HOURLY, $now - 29 * 86400],
			[Sample::RESOLUTION_DAILY, $now - 366 * 86400], [Sample::RESOLUTION_DAILY, $now - 364 * 86400]] as [$resolution, $bucketStart]) {
			$row = new Sample();
			$row->setNodeName('web1');
			$row->setResolution($resolution);
			$row->setBucketStart($bucketStart);
			$row->setSamples(12);
			$this->mapper->insert($row);
		}
		$this->at($now);

		$this->downsampler->run();

		$this->assertSame([$now - 47 * 3600], array_keys($this->rows(Sample::RESOLUTION_RAW)));
		$this->assertContains($now - 29 * 86400, array_keys($this->rows(Sample::RESOLUTION_HOURLY)));
		$this->assertNotContains($now - 31 * 86400, array_keys($this->rows(Sample::RESOLUTION_HOURLY)));
		$this->assertContains($now - 364 * 86400, array_keys($this->rows(Sample::RESOLUTION_DAILY)));
		$this->assertNotContains($now - 366 * 86400, array_keys($this->rows(Sample::RESOLUTION_DAILY)));
	}

	public function testABucketWithExpiredSamplesIsNotRecomputed(): void {
		// The day started 49 h ago: its first samples are gone, only a later one is left.
		$now = self::DAY + 49 * 3600;
		$daily = new Sample();
		$daily->setNodeName('web1');
		$daily->setResolution(Sample::RESOLUTION_DAILY);
		$daily->setBucketStart(self::DAY);
		$daily->setSamples(288);
		$daily->setMetric('load1', 4.0);
		$this->mapper->insert($daily);
		$this->raw(self::DAY + 23 * 3600, 1.0, 100);
		$this->at($now);

		$this->downsampler->run();

		$row = $this->rows(Sample::RESOLUTION_DAILY)[self::DAY];
		$this->assertSame(288, $row->getSamples());
		$this->assertEqualsWithDelta(4.0, $row->getMetric('load1'), 0.0001);
	}

	public function testKeepsFiveMinuteSamplesForAtLeastADayAndAnHour(): void {
		$this->retention[ConfigLexicon::HISTORY_RETENTION_RAW_HOURS] = 1;
		$now = self::DAY + 86400;
		$this->raw($now - 24 * 3600, 1.0, 100);
		$this->raw($now - 26 * 3600, 1.0, 100);
		$this->at($now);

		$this->downsampler->run();

		$this->assertSame([$now - 24 * 3600], array_keys($this->rows(Sample::RESOLUTION_RAW)));
	}
}
