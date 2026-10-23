<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Environment;
use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\OsStatusStore;
use OCA\ServerInfo\OsStatus\Packages\HeldPackage;
use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessProbe;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\OsVerdict;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig as IServerConfig;
use PHPUnit\Framework\MockObject\MockObject;

class OsStatusServiceTest extends \Test\TestCase {
	private const NOW = 1792411200;

	private OsStatusStore&MockObject $store;
	private FakeHost $host;
	private string $mode = 'cron';
	private OsStatusService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(OsStatusStore::class);
		$this->host = new FakeHost();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getAppValueInt')->willReturnCallback(static fn (string $key): int => match ($key) {
			'os_security_warning_days' => 3, 'os_security_error_days' => 14, 'os_reboot_warning_days' => 7,
			'os_reboot_error_days' => 30, 'os_restart_warning_days' => 3, 'os_eol_warning_days' => 90,
			'os_lists_warning_days' => 7, 'os_lists_error_days' => 30, default => 0,
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$serverConfig = $this->createMock(IServerConfig::class);
		$serverConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $app === 'core' && $key === 'backgroundjobs_mode' ? $this->mode : $default);
		$serverConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key): int => $app === 'serverinfo' && $key === 'job_interval_os_status' ? 86400 : 0);

		$this->service = new OsStatusService($this->store, new OsVerdict(), new PhpProcessProbe($this->host), $config, $time, $this->host, $serverConfig);
	}

	public function testTheMonitoringSummaryHasCountsButNoNames(): void {
		$this->store->method('load')->willReturn(new OsStatus(
			OsStatus::STATE_OK,
			self::NOW - 60,
			new OsRelease('ubuntu', ['debian'], 'Ubuntu', 'Ubuntu 24.04.3 LTS', '24.04', 'noble'),
			'6.8.0-45-generic',
			'x86_64',
			new Environment(Environment::KIND_HOST, null, 'kvm'),
			new SupportStatus(false, '2029-05-31'),
			'apt',
			[
				new PendingUpdate('openssl', '1', '2', true, advisories: ['USN-7012-1'], since: self::NOW - 4 * 86400),
				new PendingUpdate('tzdata', '1', '2', false),
			],
			OsStatus::SOURCE_SYSTEM,
			true,
			5,
			[new HeldPackage('mariadb-server', 'apt-mark hold')],
			self::NOW - 7200,
			rebootReasons: [],
			restartServices: null,
			failedUnits: ['nginx.service'],
			timeSynchronized: true,
		));

		$summary = $this->service->monitoringSummary();
		$encoded = (string)json_encode($summary);

		$this->assertSame('warning', $summary['level']);
		$this->assertSame(['classified' => true, 'security' => 1, 'total' => 7, 'pendingDays' => 4, 'held' => 1], $summary['updates']);
		$this->assertFalse($summary['rebootRequired']);
		$this->assertNull($summary['servicesNeedRestart'], 'Unknown is null, never 0');
		$this->assertSame(1, $summary['failedUnits']);
		$this->assertSame(2, $summary['listsAgeHours']);
		$this->assertSame('supported', $summary['support']['state']);
		$this->assertArrayNotHasKey('kernel', $summary);
		foreach (['openssl', 'USN-7012', 'mariadb', 'nginx', '6.8.0-45'] as $secret) {
			$this->assertStringNotContainsString($secret, $encoded);
		}
	}

	public function testUnclassifiedDistributionsReportNoSecurityCount(): void {
		$this->store->method('load')->willReturn(new OsStatus(OsStatus::STATE_OK, self::NOW, updates: [new PendingUpdate('linux', '1', '2', false)], securityClassified: false));

		$this->assertNull($this->service->monitoringSummary()['updates']['security']);
	}

	public function testBeforeTheFirstCheck(): void {
		$this->assertSame(['state' => 'pending', 'level' => 'unknown'], $this->service->monitoringSummary());
	}

	public function testOutdatedWhenPackagesChangedAfterTheCheck(): void {
		$status = new OsStatus(OsStatus::STATE_OK, self::NOW - 600, packageDatabase: '/var/lib/dpkg/status');

		$this->host->modified['/var/lib/dpkg/status'] = self::NOW - 900;
		$this->assertFalse($this->service->isOutdated($status));

		$this->host->modified['/var/lib/dpkg/status'] = self::NOW - 300;
		$this->assertTrue($this->service->isOutdated($status));
	}

	public function testSamplingOnlyInWebRequests(): void {
		$this->host->phpSapi = 'cli';
		$this->store->expects($this->never())->method('savePhpSample');

		$this->service->samplePhpProcess();
	}

	public function testFreshnessFollowsTheCronModeAndTheJobInterval(): void {
		$status = new OsStatus(OsStatus::STATE_OK, self::NOW - 2 * 86400);

		$fresh = $this->service->evaluate($status)?->get(Concern::FRESHNESS);
		$this->assertSame(Concern::LEVEL_OK, $fresh?->level, 'Two days are within three daily runs');
		$this->assertTrue($fresh->facts['systemCron']);

		$this->mode = 'webcron';
		$stale = $this->service->evaluate($status)?->get(Concern::FRESHNESS);
		$this->assertSame(Concern::LEVEL_WARNING, $stale?->level);
		$this->assertFalse($stale->facts['systemCron']);
	}
}
