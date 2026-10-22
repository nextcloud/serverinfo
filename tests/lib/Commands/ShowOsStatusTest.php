<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Commands;

use OCA\ServerInfo\Commands\ShowOsStatus;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowOsStatusTest extends \Test\TestCase {
	public static function dataExitCodes(): array {
		return [
			'ok' => [Concern::LEVEL_OK, ShowOsStatus::EXIT_OK],
			'info' => [Concern::LEVEL_INFO, ShowOsStatus::EXIT_OK],
			'warning' => [Concern::LEVEL_WARNING, ShowOsStatus::EXIT_WARNING],
			'error' => [Concern::LEVEL_ERROR, ShowOsStatus::EXIT_CRITICAL],
			'unknown' => [Concern::LEVEL_UNKNOWN, ShowOsStatus::EXIT_UNKNOWN],
			'no verdict' => [null, ShowOsStatus::EXIT_UNKNOWN],
		];
	}

	#[DataProvider('dataExitCodes')]
	public function testExitCodes(?string $level, int $exitCode): void {
		$this->assertSame($exitCode, ShowOsStatus::exitCode($level));
	}

	public function testNotCheckedYetIsUnknown(): void {
		$service = $this->createMock(OsStatusService::class);
		$service->method('getStatus')->willReturn(null);

		$this->assertSame(ShowOsStatus::EXIT_UNKNOWN, $this->execute($service));
	}

	public function testUnknownVerdictIsUnknown(): void {
		$service = $this->createMock(OsStatusService::class);
		$service->method('getStatus')->willReturn(OsStatus::restricted(1792411200));
		$service->method('evaluate')->willReturn(new Verdict([new Concern(Concern::UPDATES, Concern::LEVEL_UNKNOWN), new Concern(Concern::SUPPORT, Concern::LEVEL_OK)]));

		$this->assertSame(ShowOsStatus::EXIT_UNKNOWN, $this->execute($service));
	}

	private function execute(OsStatusService $service): int {
		$command = new ShowOsStatus($this->createMock(OsStatusCollector::class), $service);
		$input = $this->createMock(InputInterface::class);
		$input->method('getOption')->willReturnMap([['refresh', false], ['output', 'json']]);

		return (new \ReflectionMethod($command, 'execute'))->invoke($command, $input, $this->createMock(OutputInterface::class));
	}
}
