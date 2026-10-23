<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;
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
	private IL10N $l10n;
	private IURLGenerator&MockObject $urlGenerator;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(OsStatusService::class);
		$this->l10n = new FakeL10N();
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/settings/admin/serverinfo');
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
		$this->assertSame('https://cloud.example.org/settings/admin/serverinfo#os', $result->getDescriptionParameters()['link']['link'] ?? null);
	}

	public function testAnOldStatusMakesEveryCheckAWarning(): void {
		$this->given(
			new OsStatus(OsStatus::STATE_OK, 1),
			new Concern(Concern::SUPPORT, Concern::LEVEL_OK, ['state' => 'rolling']),
			new Concern(Concern::FRESHNESS, Concern::LEVEL_WARNING, ['collectedAt' => 1, 'ageSeconds' => 3 * 86400, 'systemCron' => true]),
		);

		$result = (new OsSupportCheck($this->l10n, $this->urlGenerator, $this->service, $this->texts()))->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringContainsString('The operating system was last checked 3 days ago', (string)$result->getDescription());
		$this->assertStringContainsString('Check that the system cron job for Nextcloud runs.', (string)$result->getDescription());
	}

	public function testWithoutSystemCronTheChecksSayHowToFixIt(): void {
		$this->given(
			new OsStatus(OsStatus::STATE_OK, 1),
			new Concern(Concern::UPDATES, Concern::LEVEL_ERROR, ['classified' => true, 'security' => 3, 'other' => 0]),
			new Concern(Concern::FRESHNESS, Concern::LEVEL_WARNING, ['collectedAt' => 1, 'ageSeconds' => 7200, 'systemCron' => false]),
		);

		$result = (new OsUpdatesCheck($this->l10n, $this->urlGenerator, $this->service, $this->texts()))->run();

		$this->assertSame(SetupResult::ERROR, $result->getSeverity(), 'An old status never lowers a level');
		$this->assertStringContainsString('The operating system was last checked 2 hours ago', (string)$result->getDescription());
		$this->assertStringContainsString('Switch background jobs to Cron', (string)$result->getDescription());
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

	public function testTheSupportSentenceIsFilledIn(): void {
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDate')->willReturn('May 31, 2029');
		$this->given(
			new OsStatus(OsStatus::STATE_OK, 1, new OsRelease('ubuntu', ['debian'], 'Ubuntu', 'Ubuntu 24.04.3 LTS', '24.04', 'noble')),
			new Concern(Concern::SUPPORT, Concern::LEVEL_OK, ['state' => SupportStatus::STATE_SUPPORTED, 'ends' => '2029-05-31']),
		);

		$result = (new OsSupportCheck($this->l10n, $this->urlGenerator, $this->service, new ConcernTexts($formatter)))->run();

		$this->assertSame('Ubuntu 24.04.3 LTS receives security updates until May 31, 2029.', $result->getDescription());
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
