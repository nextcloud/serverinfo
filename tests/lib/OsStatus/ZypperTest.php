<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
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
		$this->host->withFile('/etc/os-release', "NAME=\"openSUSE Tumbleweed\"\nID=\"opensuse-tumbleweed\"\n")
			->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-updates'], self::UPDATES);

		$this->assertFalse($this->zypper->classifiesSecurity());
		$this->assertCount(1, $this->zypper->getPendingUpdates());
		$this->assertStringContainsString('dup', $this->zypper->getUpgradeCommands()['all']);
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
		$this->assertTrue($this->zypper->hasOwnRebootCheck());
	}
}
