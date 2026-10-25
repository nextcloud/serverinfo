<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Jobs;

use OCA\ServerInfo\Jobs\RefreshOsStatus;
use OCA\ServerInfo\OsStatus\Notifications\OsNotificationSender;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;

class RefreshOsStatusTest extends \Test\TestCase {
	public static function dataRuns(): array {
		return [
			'system cron' => ['cron', OsStatus::restricted(1792411200), 1, 1],
			'collection skipped or failed' => ['cron', null, 1, 0],
			'webcron' => ['webcron', null, 0, 0],
			'ajax' => ['ajax', null, 0, 0],
		];
	}

	#[DataProvider('dataRuns')]
	public function testCollectsOnlyFromSystemCron(string $mode, ?OsStatus $result, int $collections, int $syncs): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('core', 'backgroundjobs_mode', 'ajax')->willReturn($mode);
		$collector = $this->createMock(OsStatusCollector::class);
		$collector->expects($this->exactly($collections))->method('collectAndStore')->willReturn($result);
		$notifications = $this->createMock(OsNotificationSender::class);
		$notifications->expects($this->exactly($syncs))->method('sync');

		$job = new RefreshOsStatus($this->createMock(ITimeFactory::class), $collector, $notifications, $appConfig);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
