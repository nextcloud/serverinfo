<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Host\CommandResult;
use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;
use OCA\ServerInfo\OsStatus\Packages\ProbeException;
use OCA\ServerInfo\OsStatus\Packages\Rpm;
use OCA\ServerInfo\OsStatus\Packages\Zypper;

class ZypperTest extends \Test\TestCase {
	private const PATCHES = <<<'XML'
		<?xml version='1.0'?>
		<stream>
		<message type="info">Loading repository data...</message>
		<update-status version="0.6">
		<update-list>
		<update kind="patch" name="openSUSE-SLE-15.6-2026-3456" edition="1" arch="noarch" status="needed" category="security" severity="important" pkgmanager="false" restart="false" interactive="false"><summary>Security update for openssl-3</summary></update>
		<update kind="patch" name="openSUSE-SLE-15.6-2026-3000" edition="1" arch="noarch" status="applied" category="security" severity="moderate" pkgmanager="false" restart="false" interactive="false"><summary>old</summary></update>
		<update kind="patch" name="openSUSE-SLE-15.6-2026-3500" edition="1" arch="noarch" status="needed" category="recommended" severity="low" pkgmanager="false" restart="false" interactive="false"><summary>bugfix</summary></update>
		</update-list>
		</update-status>
		</stream>
		XML;

	private const UPDATES = <<<'XML'
		<?xml version='1.0'?>
		<stream>
		<update-status version="0.6">
		<update-list>
		<update kind="package" name="libopenssl3" edition="3.1.4-150600.5.27.1" arch="x86_64" edition-old="3.1.4-150600.5.24.1"><summary>Secure Sockets</summary><source url="http://download.opensuse.org/update/leap/15.6/sle/" alias="repo-sle-update"/></update>
		</update-list>
		</update-status>
		</stream>
		XML;

	private FakeHost $host;
	private Zypper $zypper;

	protected function setUp(): void {
		parent::setUp();
		$this->host = new FakeHost();
		$this->host->binaries[] = 'zypper';
		$this->zypper = new Zypper($this->host, new Rpm($this->host), new SystemdProbe($this->host));
	}

	public function testOnlyNeededSecurityPatchesCount(): void {
		$patches = Zypper::parsePatches(self::PATCHES);

		$this->assertCount(1, $patches);
		$this->assertSame('openSUSE-SLE-15.6-2026-3456', $patches[0]->name);
		$this->assertSame(PendingUpdate::KIND_PATCH, $patches[0]->kind);
		$this->assertSame('Important', $patches[0]->severity);
		$this->assertTrue($patches[0]->security);
	}

	public function testPackageUpdates(): void {
		$updates = Zypper::parsePackageUpdates(self::UPDATES);

		$this->assertSame('libopenssl3', $updates[0]->name);
		$this->assertSame('3.1.4-150600.5.24.1', $updates[0]->installedVersion);
		$this->assertSame('repo-sle-update', $updates[0]->origin);
	}

	public function testEntitiesAreNotExpanded(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE stream [<!ENTITY x SYSTEM "file:///etc/passwd">]><stream><update-status><update-list>'
			. '<update kind="package" name="&x;" edition="1"/></update-list></update-status></stream>';

		foreach (Zypper::parsePackageUpdates($xml) as $update) {
			$this->assertStringNotContainsString('root:', $update->name);
		}
		$this->addToAssertionCount(1);
	}

	public function testCombinesPatchesAndPackages(): void {
		$this->host->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-patches', '--category', 'security'], self::PATCHES)
			->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-updates'], self::UPDATES);

		$this->assertCount(2, $this->zypper->getPendingUpdates());
		$this->assertTrue($this->zypper->classifiesSecurity());
	}

	public function testErrorsInsideTheXmlStream(): void {
		$this->host->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-patches', '--category', 'security'],
			"<?xml version='1.0'?><stream><message type=\"error\">Repository &apos;main&apos; is invalid.</message></stream>");

		$this->expectException(ProbeException::class);
		$this->expectExceptionMessage("Repository 'main' is invalid.");
		$this->zypper->getPendingUpdates();
	}

	public function testTumbleweedHasNoPatches(): void {
		$this->host->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-updates'], self::UPDATES);
		$release = OsRelease::parse("NAME=\"openSUSE Tumbleweed\"\nID=\"opensuse-tumbleweed\"\n");
		$this->assertNotNull($release);
		$zypper = $this->zypper->withRelease($release);

		$this->assertFalse($zypper->classifiesSecurity());
		$this->assertCount(1, $zypper->getPendingUpdates());
		$this->assertStringContainsString('dup', $zypper->getUpgradeCommands()['all']);
	}

	public function testSkippedRepositoriesStillGiveUpdates(): void {
		$this->host->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-patches', '--category', 'security'], self::PATCHES, 106)
			->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-updates'],
				str_replace('<update-status', "<message type=\"error\">Repository &apos;SLE-Module-Python3&apos; is out-of-date.</message>\n<update-status", self::UPDATES), 106);

		$this->assertCount(2, $this->zypper->getPendingUpdates());
		$this->assertSame(["zypper skipped repositories it could not read, so pending updates may be incomplete: Repository 'SLE-Module-Python3' is out-of-date."], array_values(array_filter(
			$this->zypper->getDiagnostics(),
			static fn (string $d): bool => str_contains($d, 'Python3'),
		)));
		$this->assertContains('zypper skipped repositories it could not read, so pending updates may be incomplete.', $this->zypper->getDiagnostics());
	}

	public function testInformationalExitCodesAreNotFailures(): void {
		$this->host->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-patches', '--category', 'security'], self::PATCHES, 101)
			->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-updates'], self::UPDATES, 100);

		$this->assertCount(2, $this->zypper->getPendingUpdates());
		$this->assertSame([], $this->zypper->getDiagnostics());
	}

	public function testLockedZypperIsBusy(): void {
		$this->host->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-patches', '--category', 'security'], '', 7, "System management is locked by the application with pid 4242 (zypper).\n");

		$this->expectException(ProbeException::class);
		$this->expectExceptionMessage('zypper is busy');
		$this->zypper->getPendingUpdates();
	}

	/**
	 * @return array<string, array{int, string, bool}>
	 */
	public static function failedRuns(): array {
		return [
			'zypp problem' => [4, self::PATCHES, false],
			'interrupted' => [105, self::PATCHES, false],
			'no XML' => [106, 'Segmentation fault', false],
			'timed out' => [0, self::PATCHES, true],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('failedRuns')]
	public function testFailedRuns(int $exitCode, string $stdout, bool $timedOut): void {
		$this->host->commands['zypper --non-interactive --no-refresh --xmlout list-patches --category security'] = new CommandResult($exitCode, $stdout, 'Problem', $timedOut);

		$this->expectException(ProbeException::class);
		$this->expectExceptionMessage('zypper list-patches failed');
		$this->zypper->getPendingUpdates();
	}

	public function testPatchNamesMustStartWithALetterOrDigit(): void {
		$patches = '<?xml version="1.0"?><stream><update-status><update-list>'
			. '<update kind="patch" name="--help" edition="1" status="needed" category="security"/>'
			. '<update kind="patch" name="openSUSE-Leap-16.0-1157" edition="1" status="needed" category="security"/>'
			. '</update-list></update-status></stream>';
		$this->host->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-patches', '--category', 'security'], $patches)
			->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-updates'], self::UPDATES);

		$this->zypper->getPendingUpdates();

		$this->assertContains('zypper --non-interactive --no-refresh info -t patch openSUSE-Leap-16.0-1157', $this->host->ran);
	}

	public function testPatchConflictsWithAnyArchitecture(): void {
		$coverage = Zypper::parsePatchConflicts(implode("\n", [
			'Information for patch SUSE-SLE-Module-Basesystem-15-SP6-2026-1:',
			'Conflicts   : [4]',
			'    libopenssl3.s390x < 3.1.4-150600.5.27.1',
			'    libopenssl3.x86_64_v3 < 3.1.4-150600.5.27.1',
			'    go1.22 < 1.22.5-150000.1.3.1',
			'    glibc.ppc64 < 2.38-150600.14.1',
		]));

		$this->assertSame(['libopenssl3', 'go1.22', 'glibc'], $coverage['SUSE-SLE-Module-Basesystem-15-SP6-2026-1']);
	}

	public function testLocks(): void {
		$locks = Zypper::parseLocks("<?xml version='1.0'?><stream><locks><lock number=\"1\"><name>kernel-default</name><type>package</type></lock></locks></stream>");

		$this->assertSame('kernel-default', $locks[0]->name);
	}

	public function testNeedsRebooting(): void {
		$this->host->withCommand('zypper', ['--non-interactive', 'needs-rebooting'], "Core libraries or services have been updated.\n", 102);
		$this->assertCount(1, $this->zypper->getRebootReasons());

		$this->host->withCommand('zypper', ['--non-interactive', 'needs-rebooting'], "No core libraries or services have been updated since the last system boot.\n", 0);
		$this->assertSame([], $this->zypper->getRebootReasons());
		$this->assertFalse($this->zypper->hasOwnRebootCheck(), 'needs-rebooting only knows what /etc/zypp/needreboot lists');
		$this->assertCount(2, $this->host->ran, 'hasOwnRebootCheck() runs nothing');
	}
}
