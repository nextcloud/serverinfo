<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Packages\AutomaticUpdates;
use OCA\ServerInfo\OsStatus\Packages\Dnf;
use OCA\ServerInfo\OsStatus\Packages\NeedsRootException;
use OCA\ServerInfo\OsStatus\Packages\ProbeException;
use OCA\ServerInfo\OsStatus\Packages\Rpm;

class DnfTest extends \Test\TestCase {
	private FakeHost $host;
	private Dnf $dnf;

	protected function setUp(): void {
		parent::setUp();
		$this->host = new FakeHost();
		$this->host->binaries[] = 'dnf-3';
		$this->dnf = new Dnf($this->host, new Rpm($this->host), new SystemdProbe($this->host));
	}

	public function testParsesCheckUpdate(): void {
		$updates = Dnf::parseCheckUpdate(implode("\n", [
			'',
			'openssl-libs.x86_64              1:3.2.2-6.el9_5.1          baseos',
			'kernel.x86_64                    5.14.0-503.15.1.el9_5      baseos',
			'tzdata.noarch                    2025a-1.el9                appstream',
			'Obsoleting Packages',
			'grub2-tools.x86_64               1:2.06-92.el9              baseos',
		]));

		$this->assertSame([
			'openssl-libs' => ['1:3.2.2-6.el9_5.1', 'baseos'],
			'kernel' => ['5.14.0-503.15.1.el9_5', 'baseos'],
			'tzdata' => ['2025a-1.el9', 'appstream'],
		], $updates);
	}

	public function testAcceptsEveryArchitecture(): void {
		$updates = Dnf::parseCheckUpdate(implode("\n", [
			'openssl-libs.x86_64_v2           1:3.2.2-6.el10_0.1         baseos',
			'glibc.ppc64                      2.39-37.el10               baseos',
			'zlib-ng.s390                     2.2.3-1.el10               baseos',
			'python3.12-pip.noarch            23.2.1-4.el10              appstream',
		]));

		$this->assertSame(['openssl-libs', 'glibc', 'zlib-ng', 'python3.12-pip'], array_keys($updates));
	}

	public function testParsesDnf4Advisories(): void {
		$advisories = Dnf::parseAdvisories(implode("\n", [
			'RHSA-2026:1234 Important/Sec.  openssl-libs-1:3.2.2-6.el9_5.1.x86_64',
			'RHSA-2026:1240 Moderate/Sec.   openssl-libs-1:3.2.2-6.el9_5.1.x86_64',
			'ALSA-2026:0099 Critical/Sec.   kernel-5.14.0-503.15.1.el9_5.x86_64',
			'not an advisory line',
		]));

		$this->assertSame(['RHSA-2026:1234', 'RHSA-2026:1240'], $advisories['openssl-libs']['ids']);
		$this->assertSame('Important', $advisories['openssl-libs']['severity']);
		$this->assertSame('Critical', $advisories['kernel']['severity']);
	}

	public function testParsesDnf5Advisories(): void {
		$advisories = Dnf::parseAdvisories(implode("\n", [
			'Name                 Type     Severity  Package                                Issued',
			'FEDORA-2026-1a2b3c4d security Moderate  curl-8.11.1-4.fc43.x86_64              2026-10-01 00:00:00',
			'FEDORA-2026-99887766 security None      libxml2-2.12.9-1.fc43.x86_64           2026-10-02 00:00:00',
		]));

		$this->assertSame(['FEDORA-2026-1a2b3c4d'], $advisories['curl']['ids']);
		$this->assertSame('Moderate', $advisories['curl']['severity']);
		$this->assertNull($advisories['libxml2']['severity']);
	}

	public function testPendingUpdatesAreMatchedWithAdvisories(): void {
		$this->host->withCommand('dnf-3', ['-q', '--cacheonly', 'check-update'], "openssl-libs.x86_64  1:3.2.2-6.el9_5.1  baseos\ntzdata.noarch  2025a-1.el9  appstream\n", 100)
			->withCommand('dnf-3', ['-q', '--cacheonly', 'updateinfo', 'list', '--security', '--available'], "RHSA-2026:1234 Important/Sec. openssl-libs-1:3.2.2-6.el9_5.1.x86_64\n")
			->withCommand('rpm', ['-qa', '--qf', Rpm::QUERY_FORMAT], "openssl-libs 1:3.2.2-6.el9_5 1792000000\ntzdata 0:2024b-2.el9 1792100000\n");

		$updates = $this->dnf->getPendingUpdates();

		$this->assertCount(2, $updates);
		$this->assertTrue($updates[0]->security);
		$this->assertSame(['RHSA-2026:1234'], $updates[0]->advisories);
		$this->assertSame('1:3.2.2-6.el9_5', $updates[0]->installedVersion);
		$this->assertFalse($updates[1]->security);
		$this->assertSame('2024b-2.el9', $updates[1]->installedVersion);
		$this->assertSame(1792100000, $this->dnf->getLastUpgradeAt());
		$this->assertCount(1, array_filter($this->host->ran, static fn (string $command): bool => str_starts_with($command, 'rpm ')), 'rpm -qa runs once');
	}

	public function testNoUpdatesSkipsTheAdvisories(): void {
		$this->host->withCommand('dnf-3', ['-q', '--cacheonly', 'check-update'], '', 0);

		$this->assertSame([], $this->dnf->getPendingUpdates());
		$this->assertNotContains('dnf-3 -q --cacheonly updateinfo list --security --available', $this->host->ran);
	}

	public function testCheckUpdateFailure(): void {
		$this->host->withCommand('dnf-3', ['-q', '--cacheonly', 'check-update'], '', 1, "Error: Cache-only enabled but no cache for 'baseos'\n");

		$this->expectException(ProbeException::class);
		$this->expectExceptionMessage("no cache for 'baseos'");
		$this->dnf->getPendingUpdates();
	}

	public function testVersionlock(): void {
		$dnf4 = Dnf::parseVersionlock("Last metadata expiration check: 0:10:00 ago.\nmariadb-server-3:10.11.10-1.el9.*\n");
		$this->assertSame('mariadb-server', $dnf4[0]->name);
		$this->assertSame('3:10.11.10-1.el9', $dnf4[0]->constraint, 'The release is not taken for an architecture');

		$dnf5 = Dnf::parseVersionlock("# Added by 'versionlock add' command on 2026-10-01 10:00:00\nPackage name: nginx\nevr = 1.26.2-1.fc43\n");
		$this->assertSame('nginx', $dnf5[0]->name);
	}

	public function testAutomaticInstallTimer(): void {
		$this->host->withCommand('systemctl', ['is-enabled', 'dnf-automatic-install.timer'], "enabled\n")
			->withFile('/etc/dnf/automatic.conf', "[commands]\nupgrade_type = security\napply_updates = no\n");

		$automatic = $this->dnf->getAutomaticUpdates();

		$this->assertSame(AutomaticUpdates::STATE_ENABLED, $automatic->state);
		$this->assertSame(AutomaticUpdates::SCOPE_SECURITY, $automatic->scope);
	}

	public function testAutomaticTimerThatOnlyDownloads(): void {
		$this->host->withCommand('systemctl', ['is-enabled', 'dnf-automatic-install.timer'], "disabled\n", 1)
			->withCommand('systemctl', ['is-enabled', 'dnf-automatic.timer'], "enabled\n")
			->withFile('/etc/dnf/automatic.conf', "[commands]\napply_updates = no\n");

		$automatic = $this->dnf->getAutomaticUpdates();

		$this->assertSame(AutomaticUpdates::STATE_DISABLED, $automatic->state);
		$this->assertSame(AutomaticUpdates::SCOPE_DOWNLOAD, $automatic->scope);
	}

	public function testNeedsRestartingReboot(): void {
		$this->host->withCommand('dnf-3', ['-q', '--cacheonly', 'needs-restarting', '-r'], "Core libraries or services have been updated since boot-up:\n  * glibc\n  * kernel\n\nReboot is required to fully utilize these updates.\n", 1);

		$reasons = $this->dnf->getRebootReasons();

		$this->assertSame(['glibc', 'kernel'], $reasons[0]->packages);
	}

	public function testNeedsRestartingNoReboot(): void {
		$this->host->withCommand('dnf-3', ['-q', '--cacheonly', 'needs-restarting', '-r'], "No core libraries or services have been updated since boot-up.\n", 0);

		$this->assertSame([], $this->dnf->getRebootReasons());
	}

	public function testDnf5IsNeverRunAsTheWebServer(): void {
		$this->host->binaries[] = 'dnf5';
		$this->host->withFile('/etc/dnf/versionlock.toml', FakeHost::fixture('fedora-44-versionlock.toml'));

		try {
			$this->dnf->getPendingUpdates();
			$this->fail('dnf 5 must not be asked');
		} catch (ProbeException $e) {
			$this->assertStringContainsString('root helper', $e->getMessage());
		}
		$held = $this->dnf->getHeldPackages();

		$this->assertSame(['sudo' => '1.9.17-10.p2.fc44', 'curl' => '8.18.0-14.fc44'], array_combine(
			array_map(static fn ($h) => $h->name, $held ?? []),
			array_map(static fn ($h) => $h->constraint, $held ?? []),
		));
		$this->assertSame([], $this->dnf->getRebootReasons());
		$this->assertFalse($this->dnf->hasOwnRebootCheck());
		$this->assertSame([], $this->host->ran, 'Not a single dnf process');
	}

	public function testDnf4RunsAsDnf3EvenWhenDnfIsThere(): void {
		$this->host->binaries[] = 'dnf';
		$this->host->withCommand('dnf-3', ['-q', '--cacheonly', 'check-update'], '', 0);

		$this->assertTrue($this->dnf->isAvailable());
		$this->assertSame([], $this->dnf->getPendingUpdates());
		$this->assertSame(['dnf-3 -q --cacheonly check-update'], $this->host->ran);
	}

	public function testDnfThatIsNotDnf4IsNeverRun(): void {
		$host = new FakeHost();
		$host->binaries = ['dnf', 'rpm'];
		$dnf = new Dnf($host, new Rpm($host), new SystemdProbe($host));

		$this->assertTrue($dnf->isAvailable());
		try {
			$dnf->getPendingUpdates();
			$this->fail('An unidentified dnf must not be asked');
		} catch (NeedsRootException $e) {
			$this->assertStringContainsString('root helper', $e->getMessage());
		}
		$this->assertSame([], $dnf->getRebootReasons());
		$this->assertFalse($dnf->hasOwnRebootCheck());
		$this->assertNull($dnf->getHeldPackages());
		$this->assertSame([], $host->ran);
	}

	public function testDnf5NextToDnf4IsLeftToTheHelper(): void {
		$this->host->binaries[] = 'dnf5';

		$this->expectException(NeedsRootException::class);
		$this->dnf->getPendingUpdates();
	}

	public function testDnf5WithoutLocks(): void {
		$this->host->binaries[] = 'dnf5';
		$this->host->modified['/etc/dnf'] = 1;

		$this->assertSame([], $this->dnf->getHeldPackages());
	}

	public function testListsRefreshTimeIncludesSingleFiles(): void {
		$this->host->globs['/var/cache/dnf/*/metalink.xml'] = ['/var/cache/dnf/baseos-1/metalink.xml'];
		$this->host->modified['/var/cache/dnf/baseos-1/metalink.xml'] = 1792438330;
		$this->host->modified['/var/cache/dnf/last_makecache'] = 1792438500;

		$this->assertSame(1792438500, $this->dnf->getListsRefreshedAt());
	}

	public function testListsRefreshTimeFromMetalinks(): void {
		$this->host->globs['/var/cache/libdnf5/*/metalink.xml'] = ['/var/cache/libdnf5/fedora-1/metalink.xml', '/var/cache/libdnf5/updates-2/metalink.xml'];
		$this->host->modified['/var/cache/libdnf5/fedora-1/metalink.xml'] = 1792438330;
		$this->host->modified['/var/cache/libdnf5/updates-2/metalink.xml'] = 1792438400;

		$this->assertSame(1792438400, $this->dnf->getListsRefreshedAt());
	}

	public function testSplitNevra(): void {
		$this->assertSame(['openssl-libs', '1:3.2.2-6.el9_5.1'], Rpm::splitNevra('openssl-libs-1:3.2.2-6.el9_5.1.x86_64'));
		$this->assertSame(['python3-dnf-plugin-versionlock', '4.3.0-16.el9'], Rpm::splitNevra('python3-dnf-plugin-versionlock-4.3.0-16.el9.noarch'));
		$this->assertSame(['openssl-libs', '1:3.2.2-6.el10_0.1'], Rpm::splitNevra('openssl-libs-1:3.2.2-6.el10_0.1.x86_64_v2'));
		$this->assertSame(['glibc', '2.39-37.el10'], Rpm::splitNevra('glibc-2.39-37.el10.s390'));
		$this->assertSame(['sudo', '0:1.9.17p2-3.el9_8.1'], Rpm::splitNevra('sudo-0:1.9.17p2-3.el9_8.1', false));
		$this->assertNull(Rpm::splitNevra('nonsense'));
	}
}
