<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Php;

use bantu\IniGetWrapper\IniGetWrapper;
use OCA\ServerInfo\Collector\Opcache;
use OCA\ServerInfo\Database\Snapshot;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\Php\PhpProbe;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\Tests\OsStatus\FakeHost;
use OCA\ServerInfo\Tests\OsStatus\FakeL10N;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;

class PhpProbeTest extends \Test\TestCase {
	private Opcache&MockObject $opcache;
	private Os&MockObject $os;
	private IAppConfig&MockObject $appConfig;
	private IniGetWrapper&MockObject $ini;
	private FakeHost $host;
	private Memory $memory;
	private string $restrictedMode = 'no';
	private string|int|null $memoryLimit = '512M';

	protected function setUp(): void {
		parent::setUp();

		$this->opcache = $this->createMock(Opcache::class);
		$this->opcache->method('getData')->willReturn(['status' => 'disabled']);
		$this->memory = new Memory();
		$this->memory->setMemTotal(16000);
		$this->memory->setMemAvailable(8000);
		$this->os = $this->createMock(Os::class);
		$this->os->method('getMemory')->willReturnCallback(fn () => $this->memory);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValueString')->willReturnCallback(
			fn (string $key, string $default) => $key === 'restricted_mode' ? $this->restrictedMode : $default,
		);
		$this->ini = $this->createMock(IniGetWrapper::class);
		$this->ini->method('getBytes')->willReturnCallback(fn (string $name) => match ($this->memoryLimit) {
			'512M' => 512 * 1048576,
			default => $this->memoryLimit,
		});
		$this->ini->method('getNumeric')->willReturnCallback(fn (string $name) => $name === 'opcache.memory_consumption' ? 128 : null);
		$this->host = new FakeHost();
	}

	/**
	 * @param array|false $fpm what fpm_get_status() returns
	 */
	private function snapshot(array|false $fpm): Snapshot {
		$probe = new class($fpm, $this->opcache, $this->os, $this->host, $this->appConfig, $this->ini, new FakeL10N()) extends PhpProbe {
			public function __construct(
				private array|false $fpm,
				mixed ...$args,
			) {
				parent::__construct(...$args);
			}

			#[\Override]
			protected function readFpmStatus(): array|false {
				return $this->fpm;
			}
		};

		return $probe->snapshot();
	}

	/**
	 * @param list<int> $pids
	 */
	private static function fpm(array $pids = [101, 102], int $reached = 3): array {
		return [
			'pool' => 'www',
			'process-manager' => 'dynamic',
			'start-time' => 1700000000,
			'listen-queue' => 1,
			'max-listen-queue' => 4,
			'total-processes' => count($pids),
			'max-active-processes' => 5,
			'max-children-reached' => $reached,
			'procs' => array_map(static fn (int $pid) => ['pid' => $pid, 'state' => 'Idle'], $pids),
		];
	}

	public function testWithoutFpmEveryFpmKeyIsUnavailableWithTheSapi(): void {
		$snapshot = $this->snapshot(false);

		$this->assertSame(Snapshot::FLAVOUR_PHP, $snapshot->flavour);
		$this->assertFalse($snapshot->has('fpm_max_children_reached'));
		$this->assertSame('PHP-FPM does not run this page; the PHP server API is ' . PHP_SAPI . '.', $snapshot->unavailable['fpm_max_children_reached']);
		$this->assertSame($snapshot->unavailable['fpm_max_children_reached'], $snapshot->unavailable['fpm_headroom']);
		$this->assertSame(512.0, $snapshot->derived['php_memory_limit_mb']);
		$this->assertSame(16000, $snapshot->derived['mem_total_mb']);
		$this->assertArrayNotHasKey('fpm_pool', $snapshot->details);
	}

	public function testMeasuresWorkersFromTheirPrivateMemory(): void {
		$this->host->files['/proc/101/status'] = "Name:\tphp-fpm8.4\nVmRSS:\t  150000 kB\nRssAnon:\t   81920 kB\nRssFile:\t   20000 kB\n";
		$this->host->files['/proc/102/status'] = "Name:\tphp-fpm8.4\nVmRSS:\t  102400 kB\n";

		$snapshot = $this->snapshot(self::fpm());

		$this->assertSame(['www'], $snapshot->details['fpm_pool']);
		$this->assertSame(3, $snapshot->derived['fpm_max_children_reached']);
		$this->assertSame(1, $snapshot->derived['fpm_listen_queue']);
		$this->assertSame(4, $snapshot->derived['fpm_max_listen_queue']);
		$this->assertSame(2, $snapshot->derived['fpm_total_processes']);
		$this->assertSame(5, $snapshot->derived['fpm_peak_processes']);
		// RssAnon of the first, VmRSS of the second: (80 + 100) / 2
		$this->assertSame(90.0, $snapshot->derived['fpm_worker_mb']);
		$this->assertSame(2, $snapshot->derived['fpm_worker_measured']);
		// 2 running + floor(0.8 * 8000 / 90) = 2 + 71
		$this->assertSame(73, $snapshot->derived['fpm_suggested_max_children']);
		$this->assertSame(68, $snapshot->derived['fpm_headroom']);
		$this->assertSame(['/proc/101/status', '/proc/102/status'], $this->host->read);
	}

	public function testFallsBackToMemoryLimitWhenNoWorkerCanBeRead(): void {
		$snapshot = $this->snapshot(self::fpm());

		$this->assertSame(512.0, $snapshot->derived['fpm_worker_mb']);
		$this->assertSame(0, $snapshot->derived['fpm_worker_measured']);
		// 2 running + floor(0.8 * 8000 / 512) = 2 + 12
		$this->assertSame(14, $snapshot->derived['fpm_suggested_max_children']);
		$this->assertSame(9, $snapshot->derived['fpm_headroom']);
	}

	public function testRestrictedModeReadsNoProcessAndFallsBack(): void {
		$this->restrictedMode = 'yes';
		$this->host->files['/proc/101/status'] = "RssAnon:\t   81920 kB\n";

		$snapshot = $this->snapshot(self::fpm());

		$this->assertSame([], $this->host->read);
		$this->assertSame(0, $snapshot->derived['fpm_worker_measured']);
	}

	public function testOffLinuxReadsNoProcess(): void {
		$this->host->family = 'BSD';

		$snapshot = $this->snapshot(self::fpm());

		$this->assertSame([], $this->host->read);
		$this->assertSame(0, $snapshot->derived['fpm_worker_measured']);
	}

	public function testUnlimitedMemoryLimitAndUnreadableWorkersLeaveNoWorkerSize(): void {
		$this->memoryLimit = -1;

		$snapshot = $this->snapshot(self::fpm());

		$this->assertSame(-1, $snapshot->derived['php_memory_limit_mb']);
		$this->assertFalse($snapshot->has('fpm_worker_mb'));
		$this->assertFalse($snapshot->has('fpm_suggested_max_children'));
		$this->assertStringContainsString('memory_limit sets no upper bound', $snapshot->unavailable['fpm_headroom']);
		$this->assertSame(3, $snapshot->derived['fpm_max_children_reached']);
	}

	public function testUnknownServerMemoryLeavesNoSuggestion(): void {
		$this->memory = new Memory();

		$snapshot = $this->snapshot(self::fpm());

		$this->assertFalse($snapshot->has('mem_total_mb'));
		$this->assertFalse($snapshot->has('fpm_suggested_max_children'));
		$this->assertSame('The memory of the server could not be read.', $snapshot->unavailable['fpm_headroom']);
		$this->assertSame(512.0, $snapshot->derived['fpm_worker_mb']);
	}

	public function testReadsAtMostSixtyFourWorkers(): void {
		$this->snapshot(self::fpm(range(1000, 1099)));

		$this->assertCount(64, $this->host->read);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function opcacheStates(): array {
		return [
			'not loaded' => ['not_loaded', 'OPcache is not loaded.'],
			'disabled' => ['disabled', 'OPcache is disabled.'],
			'restricted' => ['api_restricted', 'opcache.restrict_api keeps Nextcloud from reading the OPcache status.'],
			'unavailable' => ['status_unavailable', 'The OPcache status is not available.'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('opcacheStates')]
	public function testOpcacheKeysAreUnavailableWithTheReason(string $status, string $reason): void {
		$this->opcache = $this->createMock(Opcache::class);
		$this->opcache->method('getData')->willReturn(['status' => $status]);

		$snapshot = $this->snapshot(false);

		$this->assertFalse($snapshot->has('opcache_keys_used'));
		$this->assertSame($reason, $snapshot->unavailable['opcache_keys_used']);
		$this->assertSame($reason, $snapshot->unavailable['opcache_memory_total_mb']);
	}

	public function testOpcacheUsageInMegabytes(): void {
		$this->opcache = $this->createMock(Opcache::class);
		$this->opcache->method('getData')->willReturn([
			'status' => 'ok',
			'memory' => ['used' => 100 * 1048576, 'wasted' => 1048576, 'free' => 27 * 1048576, 'total' => 128 * 1048576],
			'internedStrings' => ['used' => 7 * 1048576, 'free' => 1048576, 'total' => 8 * 1048576],
			'keys' => ['used' => 7000, 'max' => 7963],
			'hitRate' => 99.5,
			'cachedScripts' => 6500,
			'oomRestarts' => 1,
			'cacheFull' => false,
			'revalidateFreq' => 0,
			'validateTimestamps' => true,
			'lastRestart' => null,
			'jit' => null,
		]);

		$snapshot = $this->snapshot(false);

		$this->assertSame(100.0, $snapshot->derived['opcache_memory_used_mb']);
		$this->assertSame(1.0, $snapshot->derived['opcache_memory_wasted_mb']);
		$this->assertSame(128.0, $snapshot->derived['opcache_memory_total_mb']);
		$this->assertSame(128, $snapshot->derived['opcache_memory_consumption_mb']);
		$this->assertSame(1, $snapshot->derived['opcache_oom_restarts']);
		$this->assertSame(7.0, $snapshot->derived['opcache_strings_used_mb']);
		$this->assertSame(8.0, $snapshot->derived['opcache_strings_total_mb']);
		$this->assertSame(7000, $snapshot->derived['opcache_keys_used']);
		$this->assertSame(7963, $snapshot->derived['opcache_keys_max']);
		$this->assertSame(1, $snapshot->derived['opcache_validate_timestamps']);
		$this->assertSame(0, $snapshot->derived['opcache_revalidate_freq']);
		$this->assertArrayNotHasKey('opcache_keys_used', $snapshot->unavailable);
	}
}
