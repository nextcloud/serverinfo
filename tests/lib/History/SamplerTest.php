<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\History;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\Db\Sample;
use OCA\ServerInfo\Db\SampleMapper;
use OCA\ServerInfo\History\Sampler;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\SessionStatistics;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * @group DB
 */
class SamplerTest extends TestCase {
	private const NOW = 1792411337;

	private SampleMapper $mapper;
	private Os&MockObject $os;
	private SessionStatistics&MockObject $sessions;
	private DatabaseStatistics&MockObject $database;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private Memory $memory;
	private array $cachedDbSize = [];
	private ?int $fileCount = 1234;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = new SampleMapper(Server::get(IDBConnection::class));
		$this->clear();

		$this->memory = new Memory();
		$this->memory->setMemTotal(8000);
		$this->memory->setMemAvailable(3000);
		$this->memory->setSwapTotal(2000);
		$this->memory->setSwapFree(1500);

		$this->os = $this->createMock(Os::class);
		$this->os->method('getHostname')->willReturn('web1.example.org');
		$this->os->method('getAverageLoad')->willReturn([1.5, 1.25, 0.75]);
		$this->os->method('getMemory')->willReturnCallback(fn () => $this->memory);

		$this->sessions = $this->createMock(SessionStatistics::class);
		$this->sessions->method('getSessionStatistics')->willReturn(['last5minutes' => 3, 'last1hour' => 4, 'last24hours' => 9]);
		$this->database = $this->createMock(DatabaseStatistics::class);

		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueString')->with('datadirectory', '')->willReturn(sys_get_temp_dir());

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('hasAppKey')->with('cached_count_filecache', null)->willReturnCallback(fn () => $this->fileCount !== null);
		$this->appConfig->method('getAppValueInt')->with('cached_count_filecache')->willReturnCallback(fn () => (int)$this->fileCount);
		$this->appConfig->method('getAppValueArray')->with(ConfigLexicon::CACHED_HISTORY_DB_SIZE)->willReturnCallback(fn () => $this->cachedDbSize);
	}

	protected function tearDown(): void {
		$this->clear();
		parent::tearDown();
	}

	private function clear(): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete(SampleMapper::TABLE)->executeStatement();
	}

	private function sampler(int $now = self::NOW): Sampler {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		return new Sampler($this->os, $this->sessions, $this->database, $this->mapper, $this->config, $this->appConfig, $time, $this->createMock(LoggerInterface::class));
	}

	public function testStoresOneSampleInTheCurrentBucket(): void {
		$this->database->expects($this->once())->method('getDatabaseStatistics')->willReturn(['type' => 'sqlite', 'version' => '3', 'size' => 5_000_000]);
		$this->appConfig->expects($this->once())->method('setAppValueArray')
			->with(ConfigLexicon::CACHED_HISTORY_DB_SIZE, ['size' => 5_000_000, 'measuredAt' => self::NOW]);

		$this->assertTrue($this->sampler()->sample());

		$rows = $this->mapper->findSince(Sample::RESOLUTION_RAW, 0);
		$this->assertCount(1, $rows);
		$row = $rows[0];
		$this->assertSame('web1.example.org', $row->getNodeName());
		$this->assertSame(intdiv(self::NOW, 300) * 300, $row->getBucketStart());
		$this->assertSame(1, $row->getSamples());
		$this->assertEqualsWithDelta(1.5, $row->getMetric('load1'), 0.0001);
		$this->assertEqualsWithDelta(1.25, $row->getMetric('load5'), 0.0001);
		$this->assertEqualsWithDelta(0.75, $row->getMetric('load15'), 0.0001);
		$this->assertEqualsWithDelta(1.5, $row->getMetric('load1Max'), 0.0001);
		$this->assertSame(5000, $row->getMetric('memUsed'));
		$this->assertSame(5000, $row->getMetric('memUsedMax'));
		$this->assertSame(8000, $row->getMetric('memTotal'));
		$this->assertSame(500, $row->getMetric('swapUsed'));
		$this->assertSame(2000, $row->getMetric('swapTotal'));
		$this->assertGreaterThan(0, $row->getMetric('diskTotal'));
		$this->assertGreaterThanOrEqual(0, $row->getMetric('diskFree'));
		$this->assertSame(3, $row->getMetric('users5min'));
		$this->assertSame(3, $row->getMetric('users5minMax'));
		$this->assertSame(9, $row->getMetric('users24h'));
		$this->assertSame(1234, $row->getMetric('files'));
		$this->assertSame(5_000_000, $row->getMetric('dbSize'));

		// A second run inside the same five minutes adds nothing.
		$this->assertFalse($this->sampler(self::NOW + 60)->sample());
		$this->assertCount(1, $this->mapper->findSince(Sample::RESOLUTION_RAW, 0));
	}

	public function testUnknownValuesStayEmpty(): void {
		$this->memory = new Memory();
		$this->os = $this->createMock(Os::class);
		$this->os->method('getHostname')->willReturn('');
		$this->os->method('getAverageLoad')->willReturn(false);
		$this->os->method('getMemory')->willReturnCallback(fn () => $this->memory);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueString')->willReturn('/does/not/exist');
		$this->fileCount = null;
		$this->database->method('getDatabaseStatistics')->willReturn(['type' => 'oci', 'version' => 'N/A', 'size' => -1]);

		$this->assertTrue($this->sampler()->sample());

		$row = $this->mapper->findSince(Sample::RESOLUTION_RAW, 0)[0];
		$this->assertSame('unknown', $row->getNodeName());
		foreach (['load1', 'load1Max', 'memUsed', 'memTotal', 'swapUsed', 'diskFree', 'diskTotal', 'files', 'dbSize'] as $metric) {
			$this->assertNull($row->getMetric($metric), $metric);
		}
	}

	public function testMeasuresTheDatabaseSizeAtMostOnceAnHour(): void {
		$this->cachedDbSize = ['size' => 42, 'measuredAt' => self::NOW - 3000];
		$this->database->expects($this->never())->method('getDatabaseStatistics');

		$this->sampler()->sample();

		$this->assertSame(42, $this->mapper->findSince(Sample::RESOLUTION_RAW, 0)[0]->getMetric('dbSize'));
	}

	public function testMeasuresAgainWhenTheSizeIsAnHourOld(): void {
		$this->cachedDbSize = ['size' => 42, 'measuredAt' => self::NOW - 3600];
		$this->database->expects($this->once())->method('getDatabaseStatistics')->willReturn(['type' => 'pgsql', 'version' => '16', 'size' => 43]);

		$this->sampler()->sample();

		$this->assertSame(43, $this->mapper->findSince(Sample::RESOLUTION_RAW, 0)[0]->getMetric('dbSize'));
	}
}
