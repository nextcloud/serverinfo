<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\SetupChecks;

use OCA\ServerInfo\Database\Advisor;
use OCA\ServerInfo\Database\CheckSummary;
use OCA\ServerInfo\Database\DatabaseProbe;
use OCA\ServerInfo\Database\RuleSet\Mysql;
use OCA\ServerInfo\Database\RuleSet\Postgres;
use OCA\ServerInfo\SetupChecks\DatabaseChecks;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

class DatabaseChecksTest extends \Test\TestCase {
	private CheckSummary&MockObject $summary;
	private DatabaseProbe&MockObject $probe;
	private DatabaseChecks $check;

	protected function setUp(): void {
		parent::setUp();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural),
		);

		$this->summary = $this->createMock(CheckSummary::class);
		$this->probe = $this->createMock(DatabaseProbe::class);

		$this->check = new DatabaseChecks(
			$l10n,
			$this->createMock(IURLGenerator::class),
			$this->summary,
			$this->probe,
			$this->createMock(Advisor::class),
			new Mysql(),
			new Postgres(),
		);
	}

	public static function summaryProvider(): array {
		return [
			'all pass' => [0, 0, 0, SetupResult::SUCCESS, 'All database checks pass.', false],
			'one notice' => [0, 0, 1, SetupResult::SUCCESS, 'No database problems found. 1 tuning hint is available. {link}', true],
			'notices only' => [0, 0, 2, SetupResult::SUCCESS, 'No database problems found. 2 tuning hints are available. {link}', true],
			'one warning' => [0, 1, 4, SetupResult::WARNING, '1 database check is failing. {link}', true],
			'alerts and warnings' => [1, 1, 4, SetupResult::WARNING, '2 database checks are failing. {link}', true],
		];
	}

	#[DataProvider('summaryProvider')]
	public function testRun(int $alerts, int $warnings, int $notices, string $severity, string $description, bool $hasLink): void {
		$this->summary->method('latest')->willReturn([
			'ranAt' => time(),
			'supported' => true,
			'failing' => $alerts + $warnings + $notices,
			'alerts' => $alerts,
			'warnings' => $warnings,
			'notices' => $notices,
		]);

		$result = $this->check->run();

		$this->assertSame($severity, $result->getSeverity());
		$this->assertSame($description, $result->getDescription());
		$this->assertSame($hasLink, isset($result->getDescriptionParameters()['link']));
	}

	public function testRunUnsupported(): void {
		$this->summary->method('latest')->willReturn([
			'ranAt' => time(),
			'supported' => false,
			'failing' => 0,
			'alerts' => 0,
			'warnings' => 0,
			'notices' => 0,
		]);

		$this->assertSame(SetupResult::SUCCESS, $this->check->run()->getSeverity());
	}

	public function testRunProbeFails(): void {
		$this->summary->method('latest')->willReturn(null);
		$this->probe->method('snapshot')->willThrowException(new \RuntimeException());

		$this->assertSame(SetupResult::INFO, $this->check->run()->getSeverity());
	}
}
