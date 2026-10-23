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
use OCA\ServerInfo\OsStatus\Verdict\OsVerdict;
use OCA\ServerInfo\OsStatus\Verdict\Thresholds;
use OCA\ServerInfo\OsStatus\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
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

	public function testAStaleStatusWarnsAndShowsItsAge(): void {
		$status = new OsStatus(OsStatus::STATE_OK, 1792411200 - 3 * 86400 - 7200);
		$service = $this->createMock(OsStatusService::class);
		$service->method('getStatus')->willReturn($status);
		$service->method('evaluate')->willReturn((new OsVerdict())->evaluate($status, null, null, new Thresholds(), 1792411200));

		$output = new BufferedOutput();
		$this->assertSame(ShowOsStatus::EXIT_WARNING, $this->execute($service, $output));
		$this->assertSame('3 d 2 h', json_decode($output->fetch(), true)['statusAge'] ?? null);
	}

	public static function dataAges(): array {
		return [
			[0, '0 min'],
			[59 * 60 + 59, '59 min'],
			[3600 + 5 * 60, '1 h 5 min'],
			[86400 + 3 * 3600 + 120, '1 d 3 h'],
		];
	}

	#[DataProvider('dataAges')]
	public function testAges(int $seconds, string $formatted): void {
		$this->assertSame($formatted, ShowOsStatus::formatAge($seconds));
	}

	private function execute(OsStatusService $service, ?OutputInterface $output = null): int {
		$command = new ShowOsStatus($this->createMock(OsStatusCollector::class), $service);
		$input = $this->createMock(InputInterface::class);
		$input->method('getOption')->willReturnMap([['refresh', false], ['output', 'json']]);

		return (new \ReflectionMethod($command, 'execute'))->invoke($command, $input, $output ?? $this->createMock(OutputInterface::class));
	}
}
