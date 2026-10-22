<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Jobs;

use OCA\ServerInfo\Jobs\UpdateOsStatus;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;

class UpdateOsStatusTest extends \Test\TestCase {
	public static function dataModes(): array {
		return [
			'system cron' => ['cron', 1],
			'webcron' => ['webcron', 0],
			'ajax' => ['ajax', 0],
		];
	}

	#[DataProvider('dataModes')]
	public function testRunsOnlyFromSystemCron(string $mode, int $runs): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(3600);
		$appConfig->method('getValueString')->with('core', 'backgroundjobs_mode', 'ajax')->willReturn($mode);
		$collector = $this->createMock(OsStatusCollector::class);
		$collector->expects($this->exactly($runs))->method('collectAndStore');

		$job = new UpdateOsStatus($this->createMock(ITimeFactory::class), $collector, $appConfig);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}
}
