<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Helper\HelperReportReader;
use OCA\ServerInfo\OsStatus\Helper\HelperState;
use PHPUnit\Framework\Attributes\DataProvider;

class HelperReportReaderTest extends \Test\TestCase {
	private const PATH = '/var/lib/serverinfo/os-report.json';
	private const NOW = 1792411200;
	private const BOOT = 1792000000;

	private FakeHost $host;
	private HelperReportReader $reader;

	protected function setUp(): void {
		parent::setUp();
		$this->host = new FakeHost();
		$this->reader = new HelperReportReader($this->host);
		$this->owned(self::PATH, 0o100644);
		$this->owned('/var/lib/serverinfo', 0o40755);
	}

	private function owned(string $path, int $mode, int $owner = 0): void {
		$this->host->owners[$path] = ['owner' => $owner, 'mode' => $mode];
	}

	private function report(array $overrides = []): string {
		return (string)json_encode(array_merge([
			'schema' => 1,
			'helperVersion' => HelperReportReader::BUNDLED_VERSION,
			'generatedAt' => self::NOW - 3600,
			'hostname' => 'cloud.example.org',
			'bootTime' => self::BOOT + 3,
			'listsRefreshedAt' => self::NOW - 3600,
			'restartServices' => ['php8.3-fpm.service', 'bad unit; rm -rf /'],
			'rebootReasons' => [['kind' => 'kernel', 'detail' => '6.8.0-48-generic'], ['kind' => 'bogus']],
			'subscription' => ['kind' => 'suse-ltss', 'active' => true],
			'errors' => ['Refreshing the package lists with apt failed.'],
		], $overrides));
	}

	/**
	 * @return array{0: HelperState, 1: mixed}
	 */
	private function read(?string $content = null): array {
		$this->host->files[self::PATH] = $content ?? $this->report();

		return $this->reader->read(self::PATH, self::BOOT, self::NOW);
	}

	public function testAValidReport(): void {
		[$state, $report] = $this->read();

		$this->assertSame(HelperState::STATE_OK, $state->state);
		$this->assertFalse($state->outdated);
		$this->assertSame(['Refreshing the package lists with apt failed.'], $state->errors);
		$this->assertSame(['php8.3-fpm.service'], array_map(static fn ($s) => $s->unit, $report->restartServices));
		$this->assertCount(1, $report->rebootReasons);
		$this->assertTrue($report->subscriptionActive);
	}

	public function testNoReport(): void {
		unset($this->host->owners[self::PATH]);

		[$state, $report] = $this->reader->read(self::PATH, self::BOOT, self::NOW);

		$this->assertSame(HelperState::STATE_ABSENT, $state->state);
		$this->assertNull($report);
	}

	public function testAReportTheWebServerCannotRead(): void {
		[$state] = $this->reader->read(self::PATH, self::BOOT, self::NOW);

		$this->assertSame(HelperState::STATE_UNREADABLE, $state->state);
	}

	public function testOutsideOpenBasedir(): void {
		$this->host->basedir = ['/var/www/', '/tmp/'];
		unset($this->host->owners[self::PATH]);

		$this->assertSame(HelperState::STATE_UNREADABLE, $this->reader->read(self::PATH, self::BOOT, self::NOW)[0]->state);
	}

	public static function dataInsecure(): array {
		return [
			'owned by the web server' => [self::PATH, 0o100644, 33],
			'group writable' => [self::PATH, 0o100664, 0],
			'world writable' => [self::PATH, 0o100646, 0],
			'directory owned by the web server' => ['/var/lib/serverinfo', 0o40755, 33],
			'directory world writable' => ['/var/lib/serverinfo', 0o41777, 0],
		];
	}

	#[DataProvider('dataInsecure')]
	public function testReportsOthersCouldForgeAreIgnored(string $path, int $mode, int $owner): void {
		$this->owned($path, $mode, $owner);

		[$state, $report] = $this->read();

		$this->assertSame(HelperState::STATE_INSECURE, $state->state);
		$this->assertNull($report);
	}

	public static function dataRejected(): array {
		$base = [
			'schema' => 1, 'helperVersion' => '1.0.0', 'generatedAt' => self::NOW - 60,
			'hostname' => 'cloud.example.org', 'bootTime' => self::BOOT,
		];

		return [
			'not json' => ['{"schema": 1,', HelperState::STATE_INVALID],
			'not an object' => ['"report"', HelperState::STATE_INVALID],
			'future schema' => [json_encode(['schema' => 2] + $base), HelperState::STATE_UNSUPPORTED],
			'missing host' => [json_encode(array_diff_key($base, ['hostname' => 1])), HelperState::STATE_INVALID],
			'bad version' => [json_encode(['helperVersion' => 'latest'] + $base), HelperState::STATE_INVALID],
			'other host' => [json_encode(['hostname' => 'elsewhere'] + $base), HelperState::STATE_OTHER_HOST],
			'previous boot' => [json_encode(['bootTime' => self::BOOT - 86400] + $base), HelperState::STATE_PREVIOUS_BOOT],
			'stale' => [json_encode(['generatedAt' => self::NOW - 27 * 3600] + $base), HelperState::STATE_STALE],
			'from the future' => [json_encode(['generatedAt' => self::NOW + 3600] + $base), HelperState::STATE_STALE],
			'too large' => [json_encode($base + ['padding' => str_repeat('x', 1024 * 1024)]), HelperState::STATE_INVALID],
			'too deep' => [str_repeat('[', 40) . str_repeat(']', 40), HelperState::STATE_INVALID],
		];
	}

	#[DataProvider('dataRejected')]
	public function testRejectedReports(string $content, string $expected): void {
		[$state, $report] = $this->read($content);

		$this->assertSame($expected, $state->state);
		$this->assertNull($report);
	}

	public function testAnOlderHelperIsFlagged(): void {
		[$state] = $this->read($this->report(['helperVersion' => '0.9.0']));

		$this->assertSame(HelperState::STATE_OK, $state->state);
		$this->assertTrue($state->outdated);
	}

	public function testUnsafePathsFallBackToTheDefault(): void {
		$this->assertSame(HelperReportReader::DEFAULT_PATH, HelperReportReader::sanitizePath('/etc/shadow'));
		$this->assertSame(HelperReportReader::DEFAULT_PATH, HelperReportReader::sanitizePath('/var/lib/../../etc/x.json'));
		$this->assertSame(HelperReportReader::DEFAULT_PATH, HelperReportReader::sanitizePath('relative.json'));
		$this->assertSame('/srv/reports/os.json', HelperReportReader::sanitizePath('/srv/reports/os.json'));
	}

	public function testBundledVersionMatchesTheScript(): void {
		$script = (string)file_get_contents(__DIR__ . '/../../../bin/serverinfo-os-report');

		$this->assertMatchesRegularExpression('/^VERSION=' . preg_quote(HelperReportReader::BUNDLED_VERSION, '/') . '$/m', $script);
		$this->assertMatchesRegularExpression('/^OUTPUT=' . preg_quote(HelperReportReader::DEFAULT_PATH, '/') . '$/m', $script);
	}
}
