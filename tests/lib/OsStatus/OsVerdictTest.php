<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Environment;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\Packages\AutomaticUpdates;
use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessSample;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\OsVerdict;
use OCA\ServerInfo\OsStatus\Verdict\Thresholds;
use OCA\ServerInfo\OsStatus\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;

class OsVerdictTest extends \Test\TestCase {
	private const NOW = 1792411200;
	private const DAY = 86400;

	private function osStatus(array $overrides = []): OsStatus {
		$defaults = [
			'state' => OsStatus::STATE_OK,
			'collectedAt' => self::NOW,
			'environment' => new Environment(Environment::KIND_HOST, null, 'kvm'),
			'support' => new SupportStatus(false, '2029-05-31', '2034-04-25', 'ubuntu-pro', false),
			'packageManager' => 'apt',
			'updates' => [],
			'securityClassified' => true,
			'listsRefreshedAt' => self::NOW - 3600,
			'automaticUpdates' => new AutomaticUpdates(AutomaticUpdates::STATE_ENABLED, 'unattended-upgrades', AutomaticUpdates::SCOPE_SECURITY),
			'rebootReasons' => [],
			'livePatches' => [],
			'failedUnits' => [],
			'timeSynchronized' => true,
		];

		return new OsStatus(...array_merge($defaults, $overrides));
	}

	private function evaluate(OsStatus $status, ?PhpProcessSample $php = null, ?int $phpSince = null, ?Thresholds $thresholds = null): Verdict {
		return (new OsVerdict())->evaluate($status, $php, $phpSince, $thresholds ?? new Thresholds(), self::NOW);
	}

	private function securityUpdate(int $daysAgo, string $name = 'openssl'): PendingUpdate {
		return new PendingUpdate($name, '1', '2', true, since: self::NOW - $daysAgo * self::DAY);
	}

	public function testAllGood(): void {
		$verdict = $this->evaluate($this->osStatus(), new PhpProcessSample(self::NOW, 'fpm-fcgi', [], 'php8.3-fpm', null));

		$this->assertSame(Concern::LEVEL_OK, $verdict->level());
	}

	public static function dataSecurityUpdates(): array {
		return [
			'fresh' => [0, Concern::LEVEL_INFO],
			'just below the warning' => [2, Concern::LEVEL_INFO],
			'at the warning' => [3, Concern::LEVEL_WARNING],
			'just below the error' => [13, Concern::LEVEL_WARNING],
			'at the error' => [14, Concern::LEVEL_ERROR],
		];
	}

	#[DataProvider('dataSecurityUpdates')]
	public function testSecurityUpdatesEscalateWithAge(int $days, string $level): void {
		$verdict = $this->evaluate($this->osStatus(['updates' => [$this->securityUpdate(0, 'curl'), $this->securityUpdate($days)]]));

		$concern = $verdict->get(Concern::UPDATES);
		$this->assertSame($level, $concern?->level);
		$this->assertSame($days, $concern->facts['days']);
		$this->assertSame(2, $concern->facts['security']);
	}

	public function testOtherUpdatesAloneAreFine(): void {
		$verdict = $this->evaluate($this->osStatus(['updates' => [new PendingUpdate('tzdata', '1', '2', false, since: self::NOW - 90 * self::DAY)]]));

		$this->assertSame(Concern::LEVEL_OK, $verdict->get(Concern::UPDATES)?->level);
		$this->assertSame(1, $verdict->get(Concern::UPDATES)?->facts['other']);
	}

	public function testUnclassifiedUpdatesCountAsRelevant(): void {
		$verdict = $this->evaluate($this->osStatus([
			'securityClassified' => false,
			'packageManager' => 'pacman',
			'updates' => [new PendingUpdate('linux', '1', '2', false, since: self::NOW - 20 * self::DAY)],
		]));

		$this->assertSame(Concern::LEVEL_ERROR, $verdict->get(Concern::UPDATES)?->level);
	}

	public function testUpdatesFromOldPackageListsAreMarked(): void {
		$fresh = $this->evaluate($this->osStatus(['listsRefreshedAt' => self::NOW - 6 * self::DAY]));
		$stale = $this->evaluate($this->osStatus(['listsRefreshedAt' => self::NOW - 7 * self::DAY]));
		$pacman = $this->evaluate($this->osStatus(['packageManager' => 'pacman', 'listsRefreshedAt' => self::NOW - 90 * self::DAY]));

		$this->assertFalse($fresh->get(Concern::UPDATES)?->facts['listsStale']);
		$this->assertTrue($stale->get(Concern::UPDATES)?->facts['listsStale']);
		$this->assertFalse($pacman->get(Concern::UPDATES)?->facts['listsStale']);
	}

	public function testUnknownUpdatesLeaveNoAllClear(): void {
		$verdict = $this->evaluate($this->osStatus(['updates' => null]));

		$this->assertSame(Concern::LEVEL_UNKNOWN, $verdict->get(Concern::UPDATES)?->level);
		$this->assertSame(Concern::LEVEL_UNKNOWN, $verdict->level());
	}

	public function testUnknownUpdatesDoNotHideAWarning(): void {
		$verdict = $this->evaluate($this->osStatus(['updates' => null, 'timeSynchronized' => false]));

		$this->assertSame(Concern::LEVEL_WARNING, $verdict->level());
	}

	public function testRebootEscalatesAndLivePatchingSoftensKernelReboots(): void {
		$kernel = new RebootReason(RebootReason::KIND_KERNEL, '6.8.0-48-generic', [], self::NOW - 10 * self::DAY);

		$this->assertSame(Concern::LEVEL_WARNING, $this->evaluate($this->osStatus(['rebootReasons' => [$kernel]]))->get(Concern::REBOOT)?->level);

		$patched = $this->evaluate($this->osStatus(['rebootReasons' => [$kernel], 'livePatches' => ['lkp_6_8_0_45']]))->get(Concern::REBOOT);
		$this->assertSame(Concern::LEVEL_INFO, $patched?->level);
		$this->assertTrue($patched->facts['livePatched']);

		$library = new RebootReason(RebootReason::KIND_CORE_LIBRARY, null, ['glibc'], self::NOW - 40 * self::DAY);
		$mixed = $this->evaluate($this->osStatus(['rebootReasons' => [$kernel, $library], 'livePatches' => ['lkp_6_8_0_45']]))->get(Concern::REBOOT);
		$this->assertSame(Concern::LEVEL_ERROR, $mixed?->level, 'A live patch does not cover the C library');
	}

	public function testRestartOfPhp(): void {
		$php = new PhpProcessSample(self::NOW, 'fpm-fcgi', ['libssl.so.3'], 'php8.3-fpm', null);

		$fresh = $this->evaluate($this->osStatus(), $php, self::NOW - 3600)->get(Concern::RESTART);
		$this->assertSame(Concern::LEVEL_INFO, $fresh?->level);
		$this->assertTrue($fresh->facts['php']);

		$old = $this->evaluate($this->osStatus(), $php, self::NOW - 4 * self::DAY)->get(Concern::RESTART);
		$this->assertSame(Concern::LEVEL_WARNING, $old?->level);
	}

	public function testRestartIsUnknownWithoutAnyInformation(): void {
		$this->assertSame(Concern::LEVEL_UNKNOWN, $this->evaluate($this->osStatus())->get(Concern::RESTART)?->level);
	}

	public static function dataSupport(): array {
		return [
			'years left' => [new SupportStatus(false, '2031-05-29'), Concern::LEVEL_OK],
			'ends in 30 days' => [new SupportStatus(false, '2026-11-18'), Concern::LEVEL_WARNING],
			'ended, programme available' => [new SupportStatus(false, '2026-06-01', '2031-06-01', 'ubuntu-pro', false), Concern::LEVEL_WARNING],
			'ended, programme active' => [new SupportStatus(false, '2026-06-01', '2031-06-01', 'ubuntu-pro', true), Concern::LEVEL_OK],
			'ended' => [new SupportStatus(false, '2024-06-30'), Concern::LEVEL_ERROR],
			'rolling' => [new SupportStatus(true, null), Concern::LEVEL_OK],
			'unknown' => [new SupportStatus(false, null), Concern::LEVEL_UNKNOWN],
		];
	}

	#[DataProvider('dataSupport')]
	public function testSupport(SupportStatus $support, string $level): void {
		$this->assertSame($level, $this->evaluate($this->osStatus(['support' => $support]))->get(Concern::SUPPORT)?->level);
	}

	public function testOldPackageLists(): void {
		$this->assertSame(Concern::LEVEL_OK, $this->evaluate($this->osStatus(['listsRefreshedAt' => self::NOW - 6 * self::DAY]))->get(Concern::PACKAGE_LISTS)?->level);
		$this->assertSame(Concern::LEVEL_WARNING, $this->evaluate($this->osStatus(['listsRefreshedAt' => self::NOW - 7 * self::DAY]))->get(Concern::PACKAGE_LISTS)?->level);
		$this->assertSame(Concern::LEVEL_ERROR, $this->evaluate($this->osStatus(['listsRefreshedAt' => self::NOW - 31 * self::DAY]))->get(Concern::PACKAGE_LISTS)?->level);
		$this->assertSame(Concern::LEVEL_OK, $this->evaluate($this->osStatus(['packageManager' => 'pacman', 'listsRefreshedAt' => null]))->get(Concern::PACKAGE_LISTS)?->level);
	}

	public function testHealth(): void {
		$verdict = $this->evaluate($this->osStatus([
			'failedUnits' => ['logrotate.service', 'php8.3-fpm.service'],
			'timeSynchronized' => false,
			'automaticUpdates' => new AutomaticUpdates(AutomaticUpdates::STATE_DISABLED),
		]));

		$this->assertSame(Concern::LEVEL_WARNING, $verdict->get(Concern::FAILED_UNITS)?->level);
		$this->assertSame(['php8.3-fpm.service'], $verdict->get(Concern::FAILED_UNITS)?->facts['stack']);
		$this->assertSame(Concern::LEVEL_WARNING, $verdict->get(Concern::TIME_SYNC)?->level);
		$this->assertSame(Concern::LEVEL_INFO, $verdict->get(Concern::AUTOMATIC_UPDATES)?->level);
		$this->assertSame(Concern::LEVEL_WARNING, $verdict->level());
	}

	public function testApplicationContainersSkipWhatIsNotTheirs(): void {
		$verdict = $this->evaluate($this->osStatus([
			'environment' => new Environment(Environment::KIND_APPLICATION_CONTAINER, 'docker'),
			'updates' => null,
			'rebootReasons' => null,
		]), new PhpProcessSample(self::NOW, 'apache2handler', [], 'apache2', null));

		$ids = array_map(static fn (Concern $c): string => $c->id, $verdict->concerns);
		$this->assertNotContains(Concern::UPDATES, $ids);
		$this->assertNotContains(Concern::REBOOT, $ids);
		$this->assertNotContains(Concern::PACKAGE_LISTS, $ids);
		$this->assertContains(Concern::RESTART, $ids);
		$this->assertSame(Concern::LEVEL_OK, $verdict->level());
	}

	public function testRestrictedOrUnsupportedHaveNoVerdict(): void {
		$this->assertSame(Concern::LEVEL_UNKNOWN, $this->evaluate(OsStatus::restricted(self::NOW))->level());
		$this->assertSame([], $this->evaluate(OsStatus::unsupported(self::NOW, '14.1-RELEASE', 'amd64'))->concerns);
	}

	public function testCustomThresholds(): void {
		$thresholds = new Thresholds(securityWarning: 0, securityError: 1);

		$this->assertSame(Concern::LEVEL_WARNING, $this->evaluate($this->osStatus(['updates' => [$this->securityUpdate(0)]]), thresholds: $thresholds)->get(Concern::UPDATES)?->level);
	}

	public static function dataFreshness(): array {
		return [
			'just collected' => [0, true, 3600, Concern::LEVEL_OK],
			'six hours at an hourly interval' => [6 * 3600, true, 3600, Concern::LEVEL_OK],
			'past six hours at an hourly interval' => [6 * 3600 + 1, true, 3600, Concern::LEVEL_WARNING],
			'three daily intervals' => [3 * 86400, true, 86400, Concern::LEVEL_OK],
			'past three daily intervals' => [3 * 86400 + 1, true, 86400, Concern::LEVEL_WARNING],
			'fresh but not from system cron' => [0, false, 3600, Concern::LEVEL_WARNING],
		];
	}

	#[DataProvider('dataFreshness')]
	public function testAnOldStatusIsStale(int $age, bool $systemCron, int $interval, string $level): void {
		$verdict = (new OsVerdict())->evaluate($this->osStatus(['collectedAt' => self::NOW - $age]), null, null, new Thresholds(), self::NOW, $systemCron, $interval);

		$concern = $verdict->get(Concern::FRESHNESS);
		$this->assertSame($level, $concern?->level);
		$this->assertSame(['collectedAt' => self::NOW - $age, 'ageSeconds' => $age, 'systemCron' => $systemCron], $concern->facts);
	}

	public function testAStaleStatusIsAtLeastAWarning(): void {
		$verdict = (new OsVerdict())->evaluate($this->osStatus(['collectedAt' => self::NOW - 30 * self::DAY, 'updates' => null]), null, null, new Thresholds(), self::NOW);

		$this->assertSame(Concern::LEVEL_UNKNOWN, $verdict->get(Concern::UPDATES)?->level);
		$this->assertSame(Concern::LEVEL_WARNING, $verdict->level(), 'Old and unknown is not "unknown" but a warning');

		$green = (new OsVerdict())->evaluate($this->osStatus(['collectedAt' => self::NOW - 30 * self::DAY]), new PhpProcessSample(self::NOW, 'fpm-fcgi', [], 'php8.3-fpm', null), null, new Thresholds(), self::NOW);
		$this->assertSame(Concern::LEVEL_WARNING, $green->level(), 'An all-clear from weeks ago is no all-clear');
	}

	public function testNothingCollectedIsUnknownFreshness(): void {
		$concern = (new OsVerdict())->freshness(null, self::NOW, true, 3600);

		$this->assertSame(Concern::LEVEL_UNKNOWN, $concern->level);
		$this->assertSame(['collectedAt' => null, 'ageSeconds' => null, 'systemCron' => true], $concern->facts);
	}
}
