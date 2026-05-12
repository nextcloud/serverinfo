<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\LogTailReader;
use OCP\IConfig;
use OCP\Log\IFileBased;
use OCP\Log\ILogFactory;
use OCP\Log\IWriter;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class LogTailReaderTest extends TestCase {
	private IConfig&MockObject $config;
	private ILogFactory&MockObject $logFactory;
	private LogTailReader $instance;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->logFactory = $this->createMock(ILogFactory::class);
		$this->instance = new LogTailReader($this->config, $this->logFactory);
	}

	/** @param list<array<string, mixed>> $entries */
	private function setupFileLog(array $entries = []): void {
		$this->config->method('getSystemValue')->with('log_type', 'file')->willReturn('file');
		$log = $this->createMockForIntersectionOfInterfaces([IWriter::class, IFileBased::class]);
		$log->method('getEntries')->willReturn($entries);
		$this->logFactory->method('get')->with('file')->willReturn($log);
	}

	public function testNonFileLogTypeReturnsUnavailable(): void {
		$this->config->method('getSystemValue')->with('log_type', 'file')->willReturn('syslog');

		$result = $this->instance->recentErrors();

		$this->assertFalse($result['available']);
		$this->assertSame('log_type_not_file', $result['reason']);
		$this->assertSame([], $result['entries']);
	}

	public function testLogNotFileBasedReturnsUnavailable(): void {
		$this->config->method('getSystemValue')->with('log_type', 'file')->willReturn('file');
		$writer = $this->createMock(IWriter::class);
		$this->logFactory->method('get')->with('file')->willReturn($writer);

		$result = $this->instance->recentErrors();

		$this->assertFalse($result['available']);
		$this->assertSame('log_not_readable', $result['reason']);
	}

	public function testEmptyEntriesReturnsAvailableWithNoEntries(): void {
		$this->setupFileLog([]);

		$result = $this->instance->recentErrors();

		$this->assertTrue($result['available']);
		$this->assertSame([], $result['entries']);
	}

	public function testReturnShape(): void {
		$this->setupFileLog([
			['time' => '2026-01-01T00:00:00+00:00', 'level' => 3, 'app' => 'core', 'message' => 'something failed'],
		]);

		$result = $this->instance->recentErrors();

		$this->assertArrayHasKey('entries', $result);
		$this->assertArrayHasKey('available', $result);
		$this->assertTrue($result['available']);
		$this->assertCount(1, $result['entries']);
		$entry = $result['entries'][0];
		$this->assertArrayHasKey('time', $entry);
		$this->assertArrayHasKey('level', $entry);
		$this->assertArrayHasKey('app', $entry);
		$this->assertArrayHasKey('message', $entry);
		$this->assertSame(3, $entry['level']);
		$this->assertSame('core', $entry['app']);
	}

	public function testEntriesBelowMinLevelAreFiltered(): void {
		$this->setupFileLog([
			['time' => '2026-01-01T00:00:03+00:00', 'level' => 3, 'app' => 'a', 'message' => 'error'],
			['time' => '2026-01-01T00:00:02+00:00', 'level' => 2, 'app' => 'a', 'message' => 'warn'],
			['time' => '2026-01-01T00:00:01+00:00', 'level' => 1, 'app' => 'a', 'message' => 'info'],
			['time' => '2026-01-01T00:00:00+00:00', 'level' => 0, 'app' => 'a', 'message' => 'debug'],
		]);

		$result = $this->instance->recentErrors(limit: 10, minLevel: 2);

		$this->assertTrue($result['available']);
		$this->assertCount(2, $result['entries']);
		foreach ($result['entries'] as $entry) {
			$this->assertGreaterThanOrEqual(2, $entry['level']);
		}
	}

	public function testLimitIsRespected(): void {
		$entries = [];
		for ($i = 0; $i < 10; $i++) {
			$entries[] = ['time' => "2026-01-01T00:00:{$i}0+00:00", 'level' => 3, 'app' => 'test', 'message' => "error $i"];
		}
		$this->setupFileLog($entries);

		$result = $this->instance->recentErrors(limit: 3);

		$this->assertTrue($result['available']);
		$this->assertCount(3, $result['entries']);
	}

	public function testLongMessageIsTruncated(): void {
		$this->setupFileLog([
			['time' => '2026-01-01T00:00:00+00:00', 'level' => 3, 'app' => 'core', 'message' => str_repeat('a', 300)],
		]);

		$result = $this->instance->recentErrors();

		$this->assertCount(1, $result['entries']);
		$this->assertLessThanOrEqual(200, mb_strlen($result['entries'][0]['message']));
	}

	public function testOrderFromGetEntriesIsPreserved(): void {
		$this->setupFileLog([
			['time' => '2026-01-01T00:00:02+00:00', 'level' => 3, 'app' => 'a', 'message' => 'third'],
			['time' => '2026-01-01T00:00:01+00:00', 'level' => 3, 'app' => 'a', 'message' => 'second'],
			['time' => '2026-01-01T00:00:00+00:00', 'level' => 3, 'app' => 'a', 'message' => 'first'],
		]);

		$result = $this->instance->recentErrors();

		$this->assertCount(3, $result['entries']);
		$this->assertSame('third', $result['entries'][0]['message']);
		$this->assertSame('first', $result['entries'][2]['message']);
	}
}
