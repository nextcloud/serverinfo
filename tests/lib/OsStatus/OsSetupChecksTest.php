<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\ConcernTexts;
use OCA\ServerInfo\OsStatus\Verdict\Verdict;
use OCA\ServerInfo\SetupChecks\OsRebootCheck;
use OCA\ServerInfo\SetupChecks\OsSupportCheck;
use OCA\ServerInfo\SetupChecks\OsUpdatesCheck;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\MockObject\MockObject;

class OsSetupChecksTest extends \Test\TestCase {
	private OsStatusService&MockObject $service;
	private IL10N&MockObject $l10n;
	private IURLGenerator&MockObject $urlGenerator;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(OsStatusService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $one : $many));
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturn('/settings/admin/serverinfo');
	}

	private function texts(): ConcernTexts {
		return new ConcernTexts($this->createMock(IDateTimeFormatter::class));
	}

	private function given(?OsStatus $status, Concern ...$concerns): void {
		$this->service->method('getStatus')->willReturn($status);
		$this->service->method('evaluate')->willReturn(new Verdict($concerns));
	}

	public function testNotCheckedYet(): void {
		$this->given(null);

		$result = (new OsUpdatesCheck($this->l10n, $this->urlGenerator, $this->service, $this->texts()))->run();

		$this->assertSame(SetupResult::INFO, $result->getSeverity());
		$this->assertSame('security', (new OsUpdatesCheck($this->l10n, $this->urlGenerator, $this->service, $this->texts()))->getCategory());
	}

	public function testTheWorstConcernOfTheCheckDecides(): void {
		$this->given(
			new OsStatus(OsStatus::STATE_OK, 1),
			new Concern(Concern::UPDATES, Concern::LEVEL_INFO, ['classified' => true, 'security' => 1, 'other' => 0]),
			new Concern(Concern::PACKAGE_LISTS, Concern::LEVEL_ERROR, ['days' => 40]),
			new Concern(Concern::REBOOT, Concern::LEVEL_WARNING, ['days' => 9, 'kinds' => ['kernel']]),
		);

		$result = (new OsUpdatesCheck($this->l10n, $this->urlGenerator, $this->service, $this->texts()))->run();

		$this->assertSame(SetupResult::ERROR, $result->getSeverity());
		$this->assertStringContainsString('Package lists were last refreshed 40 days ago', (string)$result->getDescription());
		$this->assertStringEndsWith('{link}', (string)$result->getDescription());
		$this->assertSame('/settings/admin/serverinfo#os', $result->getDescriptionParameters()['link']['link'] ?? null);
	}

	public function testRebootCheck(): void {
		$this->given(
			new OsStatus(OsStatus::STATE_OK, 1),
			new Concern(Concern::REBOOT, Concern::LEVEL_WARNING, ['days' => 9, 'kinds' => ['kernel'], 'livePatched' => false]),
			new Concern(Concern::RESTART, Concern::LEVEL_OK, ['php' => false, 'services' => 0]),
		);

		$result = (new OsRebootCheck($this->l10n, $this->urlGenerator, $this->service, $this->texts()))->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringStartsWith('Reboot required. A newer kernel is installed. Pending for 9 days.', (string)$result->getDescription());
	}

	public function testAllGoodHasNoLink(): void {
		$this->given(new OsStatus(OsStatus::STATE_OK, 1), new Concern(Concern::REBOOT, Concern::LEVEL_OK), new Concern(Concern::RESTART, Concern::LEVEL_OK));

		$result = (new OsRebootCheck($this->l10n, $this->urlGenerator, $this->service, $this->texts()))->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
		$this->assertStringNotContainsString('{link}', (string)$result->getDescription());
	}

	public function testUnknownIsInfo(): void {
		$this->given(new OsStatus(OsStatus::STATE_OK, 1), new Concern(Concern::SUPPORT, Concern::LEVEL_UNKNOWN));

		$this->assertSame(SetupResult::INFO, (new OsSupportCheck($this->l10n, $this->urlGenerator, $this->service, $this->texts()))->run()->getSeverity());
	}

	public function testRestrictedMode(): void {
		$this->given(OsStatus::restricted(1));

		$this->assertSame(SetupResult::SUCCESS, (new OsSupportCheck($this->l10n, $this->urlGenerator, $this->service, $this->texts()))->run()->getSeverity());
	}

	public function testItSamplesThePhpProcess(): void {
		$this->given(null);
		$this->service->expects($this->once())->method('samplePhpProcess');

		(new OsRebootCheck($this->l10n, $this->urlGenerator, $this->service, $this->texts()))->run();
	}
}
