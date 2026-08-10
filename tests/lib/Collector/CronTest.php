<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Collector;

use OCA\ServerInfo\Collector\Cron;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;

class CronTest extends \Test\TestCase {
	private IAppConfig&MockObject $appConfig;
	private Cron $instance;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->instance = new Cron($this->appConfig);
	}

	public function testReadsBothValuesFromCore(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('core', 'backgroundjobs_mode', 'ajax')
			->willReturn('cron');
		$this->appConfig->expects($this->once())
			->method('getValueInt')
			->with('core', 'lastcron', 0)
			->willReturn(1700000000);

		$info = $this->instance->get();

		$this->assertSame('cron', $info['mode']);
		$this->assertSame(1700000000, $info['lastRun']);
	}

	public function testNeverRanReportsZero(): void {
		$this->appConfig->method('getValueString')->willReturn('ajax');
		$this->appConfig->method('getValueInt')->willReturn(0);

		$this->assertSame(0, $this->instance->get()['lastRun']);
	}

	public function testNowIsTheServerClock(): void {
		$this->appConfig->method('getValueString')->willReturn('cron');
		$this->appConfig->method('getValueInt')->willReturn(0);

		$before = time();
		$now = $this->instance->get()['now'];

		$this->assertGreaterThanOrEqual($before, $now);
		$this->assertLessThanOrEqual(time(), $now);
	}
}
