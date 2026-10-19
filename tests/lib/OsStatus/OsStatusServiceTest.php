<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\OsStatusStore;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessProbe;
use OCA\ServerInfo\OsStatus\Verdict\OsVerdict;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;

class OsStatusServiceTest extends \Test\TestCase {
	private const NOW = 1792411200;

	private OsStatusStore&MockObject $store;
	private FakeHost $host;
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

		$this->service = new OsStatusService($this->store, new OsVerdict(), new PhpProcessProbe($this->host), $config, $time, $this->host);
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
}
