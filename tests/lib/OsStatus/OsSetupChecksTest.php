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
use OCA\ServerInfo\SetupChecks\AbstractOsCheck;
use OCA\ServerInfo\SetupChecks\OsRebootCheck;
use OCA\ServerInfo\SetupChecks\OsSupportCheck;
use OCA\ServerInfo\SetupChecks\OsUpdatesCheck;
use OCP\IDateTimeFormatter;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

class OsSetupChecksTest extends \Test\TestCase {
	private OsStatusService&MockObject $service;
	private IL10N $l10n;
	private IURLGenerator&MockObject $urlGenerator;
	/** The uid of the session user, null without a session as in occ and cron */
	private ?string $sessionUser = 'admin';
	private bool $sessionUserIsAdmin = true;

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

	/**
	 * @param class-string<AbstractOsCheck> $class
	 */
	private function check(string $class, ?ConcernTexts $texts = null): AbstractOsCheck {
		$session = $this->createMock(IUserSession::class);
		if ($this->sessionUser !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->sessionUser);
			$session->method('getUser')->willReturn($user);
		}
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === $this->sessionUser && $this->sessionUserIsAdmin);

		return new $class($this->l10n, $this->urlGenerator, $this->service, $texts ?? $this->texts(), $session, $groups);
	}

	private function given(?OsStatus $status, Concern ...$concerns): void {
		$this->service->method('getStatus')->willReturn($status);
		$this->service->method('evaluate')->willReturn(new Verdict($concerns));
	}

	public function testNotCheckedYet(): void {
		$this->given(null);

		$result = $this->check(OsUpdatesCheck::class)->run();

		$this->assertSame(SetupResult::INFO, $result->getSeverity());
		$this->assertSame('security', $this->check(OsUpdatesCheck::class)->getCategory());
	}

	public function testTheWorstConcernOfTheCheckDecides(): void {
		$this->given(
			new OsStatus(OsStatus::STATE_OK, 1),
			new Concern(Concern::UPDATES, Concern::LEVEL_INFO, ['classified' => true, 'security' => 1, 'other' => 0]),
			new Concern(Concern::PACKAGE_LISTS, Concern::LEVEL_ERROR, ['days' => 40]),
			new Concern(Concern::REBOOT, Concern::LEVEL_WARNING, ['days' => 9, 'kinds' => ['kernel']]),
		);

		$result = $this->check(OsUpdatesCheck::class)->run();

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

		$result = $this->check(OsSupportCheck::class)->run();

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

		$result = $this->check(OsUpdatesCheck::class)->run();

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

		$result = $this->check(OsRebootCheck::class)->run();

		$this->assertSame(SetupResult::WARNING, $result->getSeverity());
		$this->assertStringStartsWith('Reboot required. A newer kernel is installed. Pending for 9 days.', (string)$result->getDescription());
	}

	public function testAllGoodHasNoLink(): void {
		$this->given(new OsStatus(OsStatus::STATE_OK, 1), new Concern(Concern::REBOOT, Concern::LEVEL_OK), new Concern(Concern::RESTART, Concern::LEVEL_OK));

		$result = $this->check(OsRebootCheck::class)->run();

		$this->assertSame(SetupResult::SUCCESS, $result->getSeverity());
		$this->assertStringNotContainsString('{link}', (string)$result->getDescription());
	}

	public function testUnknownIsInfo(): void {
		$this->given(new OsStatus(OsStatus::STATE_OK, 1), new Concern(Concern::SUPPORT, Concern::LEVEL_UNKNOWN));

		$this->assertSame(SetupResult::INFO, $this->check(OsSupportCheck::class)->run()->getSeverity());
	}

	public function testTheSupportSentenceIsFilledIn(): void {
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDate')->willReturn('May 31, 2029');
		$this->given(
			new OsStatus(OsStatus::STATE_OK, 1, new OsRelease('ubuntu', ['debian'], 'Ubuntu', 'Ubuntu 24.04.3 LTS', '24.04', 'noble')),
			new Concern(Concern::SUPPORT, Concern::LEVEL_OK, ['state' => SupportStatus::STATE_SUPPORTED, 'ends' => '2029-05-31']),
		);

		$result = $this->check(OsSupportCheck::class, new ConcernTexts($formatter))->run();

		$this->assertSame('Ubuntu 24.04.3 LTS receives security updates until May 31, 2029.', $result->getDescription());
	}

	public function testRestrictedMode(): void {
		$this->given(OsStatus::restricted(1));

		$this->assertSame(SetupResult::SUCCESS, $this->check(OsSupportCheck::class)->run()->getSeverity());
	}

	public function testItSamplesThePhpProcess(): void {
		$this->given(null);
		$this->service->expects($this->once())->method('samplePhpProcess');

		$this->check(OsRebootCheck::class)->run();
	}

	private function aPendingSecurityUpdate(): void {
		$this->given(
			new OsStatus(OsStatus::STATE_OK, 1),
			new Concern(Concern::UPDATES, Concern::LEVEL_ERROR, ['classified' => true, 'security' => 3, 'other' => 0]),
		);
	}

	public function testAdminsSeeTheStatus(): void {
		$this->aPendingSecurityUpdate();

		$result = $this->check(OsUpdatesCheck::class)->run();

		$this->assertSame(SetupResult::ERROR, $result->getSeverity());
		$this->assertStringContainsString('3 security updates pending', (string)$result->getDescription());
	}

	public function testOccAndCronWithoutASessionSeeTheStatus(): void {
		$this->sessionUser = null;
		$this->aPendingSecurityUpdate();

		$result = $this->check(OsUpdatesCheck::class)->run();

		$this->assertSame(SetupResult::ERROR, $result->getSeverity());
		$this->assertStringContainsString('3 security updates pending', (string)$result->getDescription());
	}

	public function testSubAdminsLearnNothingAboutTheSystem(): void {
		$this->sessionUser = 'subadmin';
		$this->sessionUserIsAdmin = false;
		$this->aPendingSecurityUpdate();
		$this->service->expects($this->never())->method('samplePhpProcess');

		foreach ([OsUpdatesCheck::class, OsRebootCheck::class, OsSupportCheck::class] as $class) {
			$result = $this->check($class)->run();

			$this->assertSame(SetupResult::INFO, $result->getSeverity());
			$this->assertSame('Only administrators can see the operating system status.', $result->getDescription());
			$this->assertNull($result->getDescriptionParameters());
		}
	}

	public static function dataMarkupInTheReleaseName(): array {
		return [
			'supported, no link' => [Concern::LEVEL_OK, ['state' => SupportStatus::STATE_SUPPORTED, 'ends' => '2029-05-31'], SetupResult::SUCCESS],
			'ended, with a link' => [Concern::LEVEL_ERROR, ['state' => SupportStatus::STATE_ENDED, 'ends' => '2024-06-30'], SetupResult::ERROR],
		];
	}

	#[DataProvider('dataMarkupInTheReleaseName')]
	public function testMarkupInTheReleaseNameIsEscaped(string $level, array $facts, string $severity): void {
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDate')->willReturn('May 31, 2029');
		$this->given(
			new OsStatus(OsStatus::STATE_OK, 1, new OsRelease('ubuntu', ['debian'], 'Ubuntu', 'Ubuntu "Noble" <img src=x onerror=alert(1)> & co', '24.04', 'noble')),
			new Concern(Concern::SUPPORT, $level, $facts),
		);

		$result = $this->check(OsSupportCheck::class, new ConcernTexts($formatter))->run();
		$description = (string)$result->getDescription();

		$this->assertSame($severity, $result->getSeverity());
		$this->assertStringContainsString('Ubuntu "Noble" &lt;img src=x onerror=alert(1)&gt; &amp; co', $description);
		$this->assertStringNotContainsString('<', $description);
		if ($severity === SetupResult::SUCCESS) {
			$this->assertStringNotContainsString('{link}', $description);
		} else {
			$this->assertStringEndsWith(' {link}', $description);
			$this->assertSame('highlight', $result->getDescriptionParameters()['link']['type'] ?? null);
		}
	}
}
