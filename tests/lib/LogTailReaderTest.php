<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\LogTailReader;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class LogTailReaderTest extends TestCase {
	private IConfig&MockObject $config;
	private LogTailReader $instance;
	private string $tmpDir;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->instance = new LogTailReader($this->config);
		$this->tmpDir = sys_get_temp_dir();
	}

	private function configReturns(string $logType, string $logFile = ''): void {
		$this->config->method('getSystemValue')
			->willReturnCallback(function (string $key) use ($logType, $logFile): string {
				return match ($key) {
					'log_type' => $logType,
					'datadirectory' => '',
					'logfile' => $logFile,
					default => '',
				};
			});
	}

	private function setupFileLog(string $path): void {
		$this->configReturns('file', $path);
	}

	public function testNonFileLogTypeReturnsUnavailable(): void {
		$this->configReturns('syslog');

		$result = $this->instance->recentErrors();

		$this->assertFalse($result['available']);
		$this->assertSame('log_type_not_file', $result['reason']);
		$this->assertSame([], $result['entries']);
	}

	public function testUnreadablePathReturnsUnavailable(): void {
		$this->configReturns('file', '/nonexistent/path/nextcloud.log');

		$result = $this->instance->recentErrors();

		$this->assertFalse($result['available']);
		$this->assertSame('log_not_readable', $result['reason']);
	}

	public function testEmptyLogFileReturnsAvailableWithNoEntries(): void {
		$path = tempnam($this->tmpDir, 'nc_log_test_');
		file_put_contents($path, '');
		$this->setupFileLog($path);

		try {
			$result = $this->instance->recentErrors();

			$this->assertTrue($result['available']);
			$this->assertSame([], $result['entries']);
		} finally {
			unlink($path);
		}
	}

	public function testEntriesBelowMinLevelAreFiltered(): void {
		$path = tempnam($this->tmpDir, 'nc_log_test_');
		$lines = [
			json_encode(['time' => '2026-01-01T00:00:00+00:00', 'level' => 0, 'app' => 'a', 'message' => 'debug']),
			json_encode(['time' => '2026-01-01T00:00:01+00:00', 'level' => 1, 'app' => 'a', 'message' => 'info']),
			json_encode(['time' => '2026-01-01T00:00:02+00:00', 'level' => 2, 'app' => 'a', 'message' => 'warn']),
			json_encode(['time' => '2026-01-01T00:00:03+00:00', 'level' => 3, 'app' => 'a', 'message' => 'error']),
		];
		file_put_contents($path, implode("\n", $lines) . "\n");
		$this->setupFileLog($path);

		try {
			$result = $this->instance->recentErrors(limit: 10, minLevel: 2);

			$this->assertTrue($result['available']);
			$this->assertCount(2, $result['entries']);
			foreach ($result['entries'] as $entry) {
				$this->assertGreaterThanOrEqual(2, $entry['level']);
			}
		} finally {
			unlink($path);
		}
	}

	public function testLimitIsRespected(): void {
		$path = tempnam($this->tmpDir, 'nc_log_test_');
		$lines = [];
		for ($i = 0; $i < 10; $i++) {
			$lines[] = json_encode(['time' => "2026-01-01T00:00:{$i}0+00:00", 'level' => 3, 'app' => 'test', 'message' => "error $i"]);
		}
		file_put_contents($path, implode("\n", $lines) . "\n");
		$this->setupFileLog($path);

		try {
			$result = $this->instance->recentErrors(limit: 3);

			$this->assertTrue($result['available']);
			$this->assertCount(3, $result['entries']);
		} finally {
			unlink($path);
		}
	}

	public function testReturnShape(): void {
		$path = tempnam($this->tmpDir, 'nc_log_test_');
		$line = json_encode(['time' => '2026-01-01T00:00:00+00:00', 'level' => 3, 'app' => 'core', 'message' => 'something failed']);
		file_put_contents($path, $line . "\n");
		$this->setupFileLog($path);

		try {
			$result = $this->instance->recentErrors();

			$this->assertArrayHasKey('entries', $result);
			$this->assertArrayHasKey('available', $result);
			$this->assertCount(1, $result['entries']);
			$entry = $result['entries'][0];
			$this->assertArrayHasKey('time', $entry);
			$this->assertArrayHasKey('level', $entry);
			$this->assertArrayHasKey('app', $entry);
			$this->assertArrayHasKey('message', $entry);
			$this->assertSame(3, $entry['level']);
			$this->assertSame('core', $entry['app']);
		} finally {
			unlink($path);
		}
	}

	public function testLongMessageIsTruncated(): void {
		$path = tempnam($this->tmpDir, 'nc_log_test_');
		$longMsg = str_repeat('a', 300);
		$line = json_encode(['time' => '2026-01-01T00:00:00+00:00', 'level' => 3, 'app' => 'core', 'message' => $longMsg]);
		file_put_contents($path, $line . "\n");
		$this->setupFileLog($path);

		try {
			$result = $this->instance->recentErrors();

			$this->assertCount(1, $result['entries']);
			// snippet() uses mb_strlen/mb_substr so measure in characters, not bytes
			$this->assertLessThanOrEqual(200, mb_strlen($result['entries'][0]['message']));
		} finally {
			unlink($path);
		}
	}

	public function testInvalidJsonLinesAreSkipped(): void {
		$path = tempnam($this->tmpDir, 'nc_log_test_');
		$lines = [
			'not valid json',
			json_encode(['time' => '2026-01-01T00:00:00+00:00', 'level' => 3, 'app' => 'core', 'message' => 'real error']),
			'{broken',
		];
		file_put_contents($path, implode("\n", $lines) . "\n");
		$this->setupFileLog($path);

		try {
			$result = $this->instance->recentErrors();

			$this->assertCount(1, $result['entries']);
		} finally {
			unlink($path);
		}
	}

	public function testEntriesReturnedNewestFirst(): void {
		$path = tempnam($this->tmpDir, 'nc_log_test_');
		$lines = [
			json_encode(['time' => '2026-01-01T00:00:00+00:00', 'level' => 3, 'app' => 'a', 'message' => 'first']),
			json_encode(['time' => '2026-01-01T00:00:01+00:00', 'level' => 3, 'app' => 'a', 'message' => 'second']),
			json_encode(['time' => '2026-01-01T00:00:02+00:00', 'level' => 3, 'app' => 'a', 'message' => 'third']),
		];
		file_put_contents($path, implode("\n", $lines) . "\n");
		$this->setupFileLog($path);

		try {
			$result = $this->instance->recentErrors();

			$this->assertCount(3, $result['entries']);
			$this->assertSame('third', $result['entries'][0]['message']);
			$this->assertSame('first', $result['entries'][2]['message']);
		} finally {
			unlink($path);
		}
	}
}
