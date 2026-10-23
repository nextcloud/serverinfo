<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Host\CommandResult;
use OCA\ServerInfo\OsStatus\Packages\Apt;
use OCA\ServerInfo\OsStatus\Packages\AutomaticUpdates;
use OCA\ServerInfo\OsStatus\Packages\ProbeException;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use PHPUnit\Framework\Attributes\DataProvider;

class AptTest extends \Test\TestCase {
	private const SIMULATE = ['-s', '-o', 'Debug::NoLocking=1', 'dist-upgrade'];

	private FakeHost $host;
	private Apt $apt;

	protected function setUp(): void {
		parent::setUp();
		$this->host = new FakeHost();
		$this->apt = new Apt($this->host, new SystemdProbe($this->host));
	}

	public function testPhasedUpdatesAreNotPending(): void {
		$this->host->withCommand('apt-get', self::SIMULATE, FakeHost::fixture('ubuntu-26.04-apt-get-s-phased'));

		$this->assertSame([], $this->apt->getPendingUpdates());
	}

	public function testParsesSimulatedUpgrades(): void {
		$updates = Apt::parseSimulation(implode("\n", [
			'NOTE: This is only a simulation!',
			'Inst libssl3t64 [3.0.13-0ubuntu3.4] (3.0.13-0ubuntu3.5 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])',
			'Inst tzdata [2025a-0ubuntu0.24.04] (2025b-0ubuntu0.24.04 Ubuntu:24.04/noble-updates [all])',
			'Inst linux-image-6.8.0-48-generic (6.8.0-48.48 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])',
			'Inst libc6 [2.36-9+deb12u7] (2.36-9+deb12u9 Debian-Security:12/stable-security [arm64]) []',
			'Inst curl [7.88.1-10+deb12u7] (7.88.1-10+deb12u8 Debian:12.8/stable [amd64])',
			'Conf libssl3t64 (3.0.13-0ubuntu3.5 Ubuntu:24.04/noble-updates [amd64])',
			'Remv old-package [1.0]',
		]));

		$this->assertCount(5, $updates);
		[$ssl, $tz, $kernel, $libc, $curl] = $updates;

		$this->assertSame('libssl3t64', $ssl->name);
		$this->assertSame('3.0.13-0ubuntu3.4', $ssl->installedVersion);
		$this->assertSame('3.0.13-0ubuntu3.5', $ssl->candidateVersion);
		$this->assertTrue($ssl->security);
		$this->assertSame('Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security', $ssl->origin);

		$this->assertFalse($tz->security);
		$this->assertNull($kernel->installedVersion, 'A newly installed package has no installed version');
		$this->assertTrue($kernel->security);
		$this->assertTrue($libc->security);
		$this->assertFalse($curl->security, 'A point release is not a security update');
	}

	public function testKeepsRealNamesVersionsAndArchives(): void {
		$updates = Apt::parseSimulation(implode("\n", [
			'Inst libc6:i386 [1:2.36-9+deb12u7] (1:2.36-9+deb12u9 Debian Backports:12/bookworm-backports [i386])',
			'Inst tzdata [2024a-2ubuntu1] (2026e-0ubuntu0.24.04.1 Ubuntu:24.04/noble-updates [all])',
			'Inst libssl3 [3.0.20-1~deb12u2] (3.0.22-1~deb12u1 Debian-Security:12/oldstable-security [arm64])',
			'Inst php8.3-cli (8.3.12-1+0~20240927.43+debian12~1.gbp_abc LP-PPA-ondrej-php:12/bookworm [amd64])',
		]));

		$this->assertSame(['libc6:i386', 'tzdata', 'libssl3', 'php8.3-cli'], array_map(static fn ($u) => $u->name, $updates));
		$this->assertSame('1:2.36-9+deb12u7', $updates[0]->installedVersion);
		$this->assertSame('Debian Backports:12/bookworm-backports', $updates[0]->origin);
		$this->assertSame('8.3.12-1+0~20240927.43+debian12~1.gbp_abc', $updates[3]->candidateVersion);
	}

	public static function dataMalformedLines(): array {
		return [
			'upper-case name' => ['Inst LibSSL3 [1.0] (1.1 Debian:12/stable [amd64])'],
			'markup in the name' => ['Inst <b>x</b> [1.0] (1.1 Debian:12/stable [amd64])'],
			'markup in the installed version' => ['Inst libssl3 [1.0<script>] (1.1 Debian:12/stable [amd64])'],
			'quote in the candidate' => ['Inst libssl3 [1.0] (1.1"x Debian:12/stable [amd64])'],
			'overlong candidate' => ['Inst libssl3 [1.0] (' . str_repeat('1', 129) . ' Debian:12/stable [amd64])'],
			'markup in an archive' => ['Inst libssl3 [1.0] (1.1 Debian:12/stable, <img src=x>:1/x [amd64])'],
			'overlong archive' => ['Inst libssl3 [1.0] (1.1 ' . str_repeat('a', 129) . ':12/stable [amd64])'],
		];
	}

	#[DataProvider('dataMalformedLines')]
	public function testMalformedLinesAreLeftOut(string $line): void {
		$updates = Apt::parseSimulation($line . "\nInst curl [7.88.1-10+deb12u7] (7.88.1-10+deb12u8 Debian:12.8/stable [amd64])");

		$this->assertSame(['curl'], array_map(static fn ($u) => $u->name, $updates));
	}

	public function testFailuresAreReported(): void {
		$this->host->withCommand('apt-get', self::SIMULATE, '', 100, "E: Could not open lock file\n");

		$this->expectException(ProbeException::class);
		$this->expectExceptionMessage('E: Could not open lock file');
		$this->apt->getPendingUpdates();
	}

	public static function dataRunnerFailures(): array {
		return [
			'time limit' => [new CommandResult(137, "Inst libssl3 [1] (2 Debian-Security:12/stable-security [amd64])\n", '', timedOut: true), 'apt-get -s dist-upgrade failed: timed out after 120 s'],
			'output cap' => [new CommandResult(137, "Inst libssl3 [1] (2 Debian-Security:12/stable-security [amd64])\n", '', truncated: true), 'apt-get -s dist-upgrade failed: output too large'],
			'no output' => [new CommandResult(100, ''), 'apt-get -s dist-upgrade failed: exit code 100'],
		];
	}

	#[DataProvider('dataRunnerFailures')]
	public function testRunnerFailuresSayWhatHappened(CommandResult $result, string $message): void {
		$this->host->withCommand('apt-get', self::SIMULATE, '');
		$this->host->commands['apt-get ' . implode(' ', self::SIMULATE)] = $result;

		$this->expectException(ProbeException::class);
		$this->expectExceptionMessage($message);
		$this->apt->getPendingUpdates();
	}

	public function testAvailability(): void {
		$this->assertFalse($this->apt->isAvailable(), 'Known to be missing');

		$this->host->hiddenBinaries = ['apt-get'];
		$this->assertTrue($this->apt->isAvailable(), 'Hidden by open_basedir, so it is tried');

		$this->host->binaries = ['apt-get'];
		$this->assertTrue($this->apt->isAvailable());
	}

	public function testMissingAptGetIsReported(): void {
		$this->expectException(ProbeException::class);
		$this->apt->getPendingUpdates();
	}

	public function testHeldPackages(): void {
		$this->host->withCommand('apt-mark', ['showhold'], "mariadb-server\nlinux-image-generic\n");

		$held = $this->apt->getHeldPackages();

		$this->assertSame(['mariadb-server', 'linux-image-generic'], array_map(static fn ($h) => $h->name, $held ?? []));
		$this->assertSame('apt-mark hold', $held[0]->mechanism);
	}

	public function testUnattendedUpgradesLimitedToSecurity(): void {
		$this->host->withCommand('apt-config', ['dump'], FakeHost::fixture('ubuntu-26.04-apt-config-dump'))
			->withCommand('systemctl', ['is-enabled', 'apt-daily-upgrade.timer'], "enabled\n");
		$this->host->files['/usr/bin/unattended-upgrade'] = '';

		$automatic = $this->apt->getAutomaticUpdates();

		$this->assertSame(AutomaticUpdates::STATE_ENABLED, $automatic->state);
		$this->assertSame('unattended-upgrades', $automatic->mechanism);
		$this->assertSame(AutomaticUpdates::SCOPE_SECURITY, $automatic->scope);
	}

	public function testDisabledTimerMeansNoAutomaticUpdates(): void {
		$this->host->withCommand('apt-config', ['dump'], FakeHost::fixture('ubuntu-26.04-apt-config-dump'))
			->withCommand('systemctl', ['is-enabled', 'apt-daily-upgrade.timer'], "masked\n", 1);
		$this->host->files['/usr/bin/unattended-upgrade'] = '';

		$this->assertSame(AutomaticUpdates::STATE_DISABLED, $this->apt->getAutomaticUpdates()->state);
	}

	public function testNotInstalledMeansNoAutomaticUpdates(): void {
		$this->host->withCommand('apt-config', ['dump'], FakeHost::fixture('ubuntu-26.04-apt-config-dump'));

		$this->assertSame(AutomaticUpdates::STATE_DISABLED, $this->apt->getAutomaticUpdates()->state);
	}

	public function testUnattendedScope(): void {
		$debian = implode("\n", [
			'Unattended-Upgrade::Origins-Pattern:: "origin=Debian,codename=${distro_codename},label=Debian";',
			'Unattended-Upgrade::Origins-Pattern:: "origin=Debian,codename=${distro_codename},label=Debian-Security";',
		]);
		$this->assertSame(AutomaticUpdates::SCOPE_ALL, Apt::unattendedScope($debian));

		$updates = 'Unattended-Upgrade::Allowed-Origins:: "${distro_id}:${distro_codename}-updates";';
		$this->assertSame(AutomaticUpdates::SCOPE_ALL, Apt::unattendedScope($updates));

		$this->assertSame(AutomaticUpdates::SCOPE_UNKNOWN, Apt::unattendedScope('APT::Periodic "";'));
	}

	public function testRebootRequired(): void {
		$this->host->withFile('/run/reboot-required', "*** System restart required ***\n", 1760400000)
			->withFile('/run/reboot-required.pkgs', "linux-image-6.8.0-48-generic\nlibc6\nlibc6\n; rm -rf\n");

		$reasons = $this->apt->getRebootReasons();

		$this->assertCount(1, $reasons);
		$this->assertSame(RebootReason::KIND_DISTRIBUTION, $reasons[0]->kind);
		$this->assertSame(['linux-image-6.8.0-48-generic', 'libc6'], $reasons[0]->packages);
		$this->assertSame(1760400000, $reasons[0]->since);
	}

	public function testNoRebootRequired(): void {
		$this->assertSame([], $this->apt->getRebootReasons());
	}

	/**
	 * Fills the host with the timestamps of a real `ls -la --time-style=+%s`
	 * of /var/lib/apt/lists.
	 */
	private function listsFromFixture(string $name): void {
		foreach (preg_split('/\R/', FakeHost::fixture($name)) ?: [] as $line) {
			if (preg_match('/^[-d]\S+\s+\d+\s+\S+\s+\S+\s+\d+\s+(\d+)\s+(\S+)$/', $line, $matches) !== 1 || in_array($matches[2], ['.', '..'], true)) {
				continue;
			}

			$path = '/var/lib/apt/lists/' . $matches[2];
			$this->host->modified[$path] = (int)$matches[1];
			foreach (['*InRelease', '*_Release'] as $pattern) {
				if (fnmatch($pattern, $matches[2])) {
					$this->host->globs['/var/lib/apt/lists/' . $pattern][] = $path;
				}
			}
		}
	}

	public function testListsRefreshTimeIsTheSuccessStamp(): void {
		$this->listsFromFixture('debian-12-ls-apt-lists');
		$this->host->modified['/var/lib/apt/periodic/update-success-stamp'] = 1792430940;

		$this->assertSame(1792430940, $this->apt->getListsRefreshedAt());
	}

	public function testListsRefreshTimeFallsBackToTheNewestReleaseFile(): void {
		$this->listsFromFixture('debian-12-ls-apt-lists');

		$this->assertSame(1792418975, $this->apt->getListsRefreshedAt(), 'bookworm-updates, not the newer lists/partial');
	}

	public function testListsRefreshTimeFromReleaseFilesWithoutInRelease(): void {
		$this->host->globs['/var/lib/apt/lists/*_Release'] = ['/var/lib/apt/lists/mirror.example.org_debian_dists_bookworm_Release'];
		$this->host->modified['/var/lib/apt/lists/mirror.example.org_debian_dists_bookworm_Release'] = 1792000000;
		$this->host->modified['/var/lib/apt/lists/partial'] = 1792400000;

		$this->assertSame(1792000000, $this->apt->getListsRefreshedAt());
	}

	public function testNoListsNoRefreshTime(): void {
		$this->host->modified['/var/lib/apt/lists/partial'] = 1792400000;

		$this->assertNull($this->apt->getListsRefreshedAt(), 'A failing apt-get update still writes lists/partial');
	}

	public function testLastUpgradeIsReadInTheSystemTimeZone(): void {
		$this->host->links['/etc/localtime'] = '/usr/share/zoneinfo/Europe/Berlin';
		$this->host->withFile('/var/log/dpkg.log', implode("\n", [
			'2026-10-12 06:41:00 startup archives unpack',
			'2026-10-12 06:41:02 upgrade libssl3:amd64 3.0.13-1 3.0.13-2',
			'2026-10-12 06:41:05 status installed libssl3:amd64 3.0.13-2',
			'2026-10-13 09:00:00 remove foo:amd64 1.0 <none>',
		]) . "\n");

		$this->assertSame((int)strtotime('2026-10-12 04:41:02 UTC'), $this->apt->getLastUpgradeAt());
	}

	public function testLastUpgradeFallsBackToTheDpkgDatabase(): void {
		$this->host->modified['/var/lib/dpkg/status'] = 1760000000;

		$this->assertSame(1760000000, $this->apt->getLastUpgradeAt());
	}

	public function testUpgradeCommands(): void {
		$this->assertNull($this->apt->getUpgradeCommands()['security']);

		$this->host->files['/usr/bin/unattended-upgrade'] = '';
		$this->assertSame('sudo unattended-upgrade -v', $this->apt->getUpgradeCommands()['security']);
	}
}
