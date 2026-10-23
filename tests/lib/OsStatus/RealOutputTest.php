<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\Packages\Apk;
use OCA\ServerInfo\OsStatus\Packages\Apt;
use OCA\ServerInfo\OsStatus\Packages\AutomaticUpdates;
use OCA\ServerInfo\OsStatus\Packages\Dnf;
use OCA\ServerInfo\OsStatus\Packages\Pacman;
use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;
use OCA\ServerInfo\OsStatus\Packages\Rpm;
use OCA\ServerInfo\OsStatus\Packages\Zypper;

/**
 * Output captured from real systems (arm64 containers of each distribution,
 * October 2026), run as an unprivileged user unless named otherwise.
 */
class RealOutputTest extends \Test\TestCase {
	/**
	 * @param list<PendingUpdate> $updates
	 * @return array<string, bool> name => security
	 */
	private static function names(array $updates): array {
		$names = [];
		foreach ($updates as $update) {
			$names[$update->name] = $update->security;
		}

		return $names;
	}

	public function testDebian12(): void {
		$updates = Apt::parseSimulation(FakeHost::fixture('debian-12-apt-get-s-pending'), new OsRelease('debian', [], 'Debian GNU/Linux', 'Debian GNU/Linux 12 (bookworm)', '12', 'bookworm'));

		// tzdata is held, so the simulation leaves it out.
		$this->assertSame(['libssl3' => true, 'openssl' => true], self::names($updates));
		$this->assertSame('3.0.20-1~deb12u2', $updates[0]->installedVersion);
		$this->assertSame('3.0.22-1~deb12u1', $updates[0]->candidateVersion);
		$this->assertSame(AutomaticUpdates::SCOPE_ALL, Apt::unattendedScope(FakeHost::fixture('debian-12-apt-config-dump')));
	}

	public function testUbuntu2404(): void {
		$updates = Apt::parseSimulation(FakeHost::fixture('ubuntu-24.04-apt-get-s-pending'), OsRelease::parse(FakeHost::fixture('ubuntu-24.04-os-release')));

		$this->assertSame([
			'libc6' => true, 'libc-bin' => true, 'libssl3t64' => true, 'openssl' => true,
			'tzdata' => false, 'curl' => true, 'libcurl4t64' => true,
		], self::names($updates));
	}

	public function testAlmaLinux9WithDnf4(): void {
		$host = new FakeHost();
		$host->binaries[] = 'dnf-3';
		$host->withCommand('dnf-3', ['-q', '--cacheonly', 'check-update'], FakeHost::fixture('alma-9-dnf-check-update-pending'), 100)
			->withCommand('dnf-3', ['-q', '--cacheonly', 'updateinfo', 'list', '--security', '--available'], FakeHost::fixture('alma-9-dnf-updateinfo-security'))
			->withCommand('dnf-3', ['-q', '--cacheonly', 'versionlock', 'list'], FakeHost::fixture('alma-9-dnf-versionlock'))
			->withCommand('dnf-3', ['-q', '--cacheonly', 'needs-restarting', '-r'], FakeHost::fixture('alma-9-dnf-needs-restarting-r'), 1)
			->withFile('/etc/dnf/automatic.conf', FakeHost::fixture('alma-9-dnf-automatic.conf'));
		$dnf = new Dnf($host, new Rpm($host), new SystemdProbe($host));

		$updates = $dnf->getPendingUpdates();

		$this->assertSame([
			'curl-minimal' => true, 'glibc' => true, 'glibc-common' => true, 'glibc-minimal-langpack' => true,
			'libcurl-minimal' => true, 'openssl' => true, 'openssl-fips-provider' => false, 'openssl-libs' => true,
			'tzdata' => false,
		], self::names($updates), 'sudo has an advisory but is versionlocked, so it is not pending');
		$this->assertSame(['ALSA-2026:77396'], $updates[5]->advisories);
		$this->assertSame('Important', $updates[5]->severity);
		$this->assertSame('1:3.5.8-2.el9_8', $updates[5]->candidateVersion);

		$this->assertSame(['sudo'], array_map(static fn ($h) => $h->name, $dnf->getHeldPackages() ?? []));
		$this->assertSame(['glibc'], $dnf->getRebootReasons()[0]->packages);
	}

	public function testFedora44WithDnf5(): void {
		$updates = Dnf::parseCheckUpdate(FakeHost::fixture('fedora-44-dnf-check-update-pending'));
		$advisories = Dnf::parseAdvisories(FakeHost::fixture('fedora-44-dnf-advisory-security'));

		$this->assertSame(['curl', 'glibc', 'glibc-common', 'glibc-minimal-langpack', 'libcurl', 'openssl-libs', 'tzdata'], array_keys($updates));
		$this->assertSame(['1:3.5.9-1.fc44', 'updates'], $updates['openssl-libs']);
		$this->assertSame(['FEDORA-2026-12f3f3d569'], $advisories['openssl-libs']['ids']);
		$this->assertSame('Moderate', $advisories['curl']['severity']);
		$this->assertArrayHasKey('sudo', $advisories, 'Advisories cover locked packages, which check-update leaves out');

		$held = Dnf::parseVersionlock(FakeHost::fixture('fedora-44-dnf-versionlock'));
		$this->assertSame(['sudo'], array_map(static fn ($h) => $h->name, $held));
	}

	public function testOpenSuseLeap16(): void {
		$patches = Zypper::parsePatches(FakeHost::fixture('leap-16-zypper-patches-security.xml'));
		$packages = Zypper::parsePackageUpdates(FakeHost::fixture('leap-16-zypper-updates.xml'));

		$this->assertCount(6, $patches);
		$this->assertSame('openSUSE-Leap-16.0-1157', $patches[0]->name);
		$this->assertSame('Security update for glibc', $patches[0]->summary);
		$this->assertSame(['openSUSE-Leap-16.0-1157', 'CVE-2026-5435', 'CVE-2026-6238'], $patches[0]->advisories);
		$this->assertSame('Moderate', $patches[0]->severity);
		$this->assertSame(['Moderate', 'Important', 'Important', 'Moderate', 'Important', 'Important'], array_map(static fn ($p) => $p->severity, $patches));

		$this->assertSame(['glibc', 'glibc-locale-base', 'libcurl4', 'libopenssl-3-fips-provider', 'libopenssl3', 'openssl-3', 'timezone'], array_keys(self::names($packages)));
		$this->assertSame('2.40-160000.5.1', $packages[0]->installedVersion);
		$this->assertSame(['curl'], array_map(static fn ($h) => $h->name, Zypper::parseLocks(FakeHost::fixture('leap-16-zypper-locks.xml'))));
	}

	public function testOpenSuseLeap16PatchesBecomePackageSecurityUpdates(): void {
		$coverage = Zypper::parsePatchConflicts(FakeHost::fixture('leap-16-zypper-info-patch'));

		$this->assertSame(['openSUSE-Leap-16.0-1157', 'openSUSE-Leap-16.0-1187'], array_keys($coverage));
		$this->assertContains('glibc-locale-base', $coverage['openSUSE-Leap-16.0-1157']);
		$this->assertContains('cross-ppc64le-glibc-devel', $coverage['openSUSE-Leap-16.0-1157']);
		$this->assertContains('libcurl4', $coverage['openSUSE-Leap-16.0-1187']);
		$this->assertNotContains('glibc.aarch64', $coverage['openSUSE-Leap-16.0-1157'], 'Architectures are stripped');

		$merged = Zypper::mergePatches(
			Zypper::parsePatches(FakeHost::fixture('leap-16-zypper-patches-security.xml')),
			Zypper::parsePackageUpdates(FakeHost::fixture('leap-16-zypper-updates.xml')),
			$coverage,
		);
		$byName = [];
		foreach ($merged as $update) {
			$byName[$update->name] = $update;
		}

		$this->assertTrue($byName['glibc']->security);
		$this->assertSame(['openSUSE-Leap-16.0-1157', 'CVE-2026-5435', 'CVE-2026-6238'], $byName['glibc']->advisories);
		$this->assertSame('Moderate', $byName['glibc']->severity);
		$this->assertTrue($byName['libcurl4']->security, 'curl itself is locked, its library is not');
		$this->assertSame('Important', $byName['libcurl4']->severity);
		$this->assertFalse($byName['timezone']->security);
		$this->assertArrayNotHasKey('openSUSE-Leap-16.0-1157', $byName, 'A patch whose packages are listed is not repeated');
		$this->assertSame(PendingUpdate::KIND_PATCH, $byName['openSUSE-Leap-16.0-1592']->kind, 'Patches without looked-up packages stay visible');
	}

	public function testZypperFallsBackToPatchRowsWhenInfoFails(): void {
		$host = new FakeHost();
		$host->binaries[] = 'zypper';
		$host->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-patches', '--category', 'security'], FakeHost::fixture('leap-16-zypper-patches-security.xml'))
			->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-updates'], FakeHost::fixture('leap-16-zypper-updates.xml'));

		$updates = (new Zypper($host, new Rpm($host), new SystemdProbe($host)))->getPendingUpdates();

		$this->assertCount(13, $updates);
		$this->assertCount(6, array_filter($updates, static fn (PendingUpdate $u): bool => $u->kind === PendingUpdate::KIND_PATCH));
	}

	public function testOpenSuseTumbleweed(): void {
		$host = new FakeHost();
		$host->binaries[] = 'zypper';
		$host->withFile('/etc/os-release', FakeHost::fixture('tumbleweed-os-release'))
			->withCommand('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'list-updates'], FakeHost::fixture('tumbleweed-zypper-updates.xml'));
		$zypper = new Zypper($host, new Rpm($host), new SystemdProbe($host));

		$this->assertFalse($zypper->classifiesSecurity());
		$this->assertSame(['openSUSE-build-key', 'update-test-reboot-needed', 'update-test-security'], array_keys(self::names($zypper->getPendingUpdates())));
	}

	public function testArchLinux(): void {
		$this->assertSame(['python-urllib3' => false], self::names(Pacman::parseUpdates(FakeHost::fixture('arch-checkupdates-pending'))));
		$this->assertSame([], Pacman::parseUpdates(FakeHost::fixture('arch-pacman-qu-ignored')), 'Ignored packages are held, not pending');
		$this->assertSame(['python-requests'], array_map(static fn ($h) => $h->name, Pacman::parseIgnored(FakeHost::fixture('arch-pacman-conf-ignorepkg'))));

		$host = new FakeHost();
		$host->binaries = ['pacman', 'checkupdates', 'arch-audit'];
		$host->withCommand('checkupdates', [], FakeHost::fixture('arch-checkupdates-pending'))
			->withCommand('arch-audit', ['--upgradable', '--quiet'], FakeHost::fixture('arch-audit-u-pending'));

		$this->assertSame(['python-urllib3' => true], self::names((new Pacman($host))->getPendingUpdates()));
	}

	public function testArchFallsBackWhenCheckupdatesCannotRun(): void {
		$host = new FakeHost();
		$host->binaries = ['pacman', 'checkupdates'];
		$host->withCommand('checkupdates', [], '', 1, "==> ERROR: Cannot find the fakeroot binary.\n")
			->withCommand('pacman', ['-Qu'], "python-urllib3 1.26.3-1 -> 2.8.0-1\n");

		$this->assertSame(['python-urllib3' => false], self::names((new Pacman($host))->getPendingUpdates()));
	}

	public function testAlpine(): void {
		$host = new FakeHost();
		$host->binaries[] = 'apk';
		$host->withCommand('apk', ['list', '--upgradable'], FakeHost::fixture('alpine-apk-list-upgradable'))
			->withFile('/etc/apk/world', FakeHost::fixture('alpine-apk-world-pinned'));

		$updates = (new Apk($host))->getPendingUpdates();

		$this->assertSame(['libcrypto3' => false, 'libssl3' => false], self::names($updates), 'zlib is pinned to its installed version');
		$this->assertSame('3.5.8-r0', $updates[0]->installedVersion);
	}
}
