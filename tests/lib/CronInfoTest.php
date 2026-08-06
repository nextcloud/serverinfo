<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\CronInfo;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;

class CronInfoTest extends \Test\TestCase {
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private CronInfo $instance;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->instance = new CronInfo($this->config, $this->appConfig);
	}

	public function testNeverRanIsCritical(): void {
		$this->config->method('getAppValue')->willReturn('cron');
		$this->appConfig->method('getValueInt')->willReturn(0);

		$info = $this->instance->getCronInfo();

		$this->assertSame('cron', $info['mode']);
		$this->assertSame(0, $info['lastRun']);
		$this->assertSame(-1, $info['secondsSince']);
		$this->assertSame('critical', $info['status']);
	}

	public function testCronModeRecentRunIsOk(): void {
		$this->config->method('getAppValue')->willReturn('cron');
		$this->appConfig->method('getValueInt')->willReturn(time() - 60);

		$info = $this->instance->getCronInfo();

		$this->assertSame('ok', $info['status']);
	}

	public function testCronModeOver15MinIsWarning(): void {
		$this->config->method('getAppValue')->willReturn('cron');
		$this->appConfig->method('getValueInt')->willReturn(time() - 1000);

		$info = $this->instance->getCronInfo();

		$this->assertSame('warning', $info['status']);
	}

	public function testCronModeOver1HourIsCritical(): void {
		$this->config->method('getAppValue')->willReturn('cron');
		$this->appConfig->method('getValueInt')->willReturn(time() - 4000);

		$info = $this->instance->getCronInfo();

		$this->assertSame('critical', $info['status']);
	}

	public function testWebcronModeOver2HoursIsCritical(): void {
		$this->config->method('getAppValue')->willReturn('webcron');
		$this->appConfig->method('getValueInt')->willReturn(time() - 8000);

		$info = $this->instance->getCronInfo();

		$this->assertSame('webcron', $info['mode']);
		$this->assertSame('critical', $info['status']);
	}

	public function testWebcronModeOver1HourIsWarning(): void {
		$this->config->method('getAppValue')->willReturn('webcron');
		$this->appConfig->method('getValueInt')->willReturn(time() - 5000);

		$info = $this->instance->getCronInfo();

		$this->assertSame('warning', $info['status']);
	}

	public function testWebcronModeRecentRunIsOk(): void {
		$this->config->method('getAppValue')->willReturn('webcron');
		$this->appConfig->method('getValueInt')->willReturn(time() - 60);

		$info = $this->instance->getCronInfo();

		$this->assertSame('ok', $info['status']);
	}
}
