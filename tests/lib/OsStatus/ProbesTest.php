<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Health\SecurityProbe;
use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Health\TimeSyncProbe;
use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\Reboot\CoreLibraryProbe;
use OCA\ServerInfo\OsStatus\Reboot\KernelProbe;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessProbe;
use OCA\ServerInfo\OsStatus\Support\SubscriptionProbe;

class ProbesTest extends \Test\TestCase {
	private FakeHost $host;

	protected function setUp(): void {
		parent::setUp();
		$this->host = new FakeHost();
	}

	/**
	 * @param array<string, int> $kernels release => install time
	 */
	private function kernels(array $kernels): void {
		$files = [];
		foreach ($kernels as $release => $time) {
			$file = "/lib/modules/$release/modules.dep";
			$files[] = $file;
			$this->host->changed[$file] = $time;
		}
		$this->host->globs['/lib/modules/*/modules.dep'] = $files;
	}

	public function testNewerKernelOfTheSameFlavour(): void {
		$this->host->release = '6.8.0-45-generic';
		$this->kernels(['6.8.0-45-generic' => 100, '6.8.0-47-generic' => 200, '6.8.0-50-lowlatency' => 300, '6.8.0-9-generic' => 50]);

		$reasons = (new KernelProbe($this->host))->getRebootReasons();

		$this->assertCount(1, $reasons);
		$this->assertSame(RebootReason::KIND_KERNEL, $reasons[0]->kind);
		$this->assertSame('6.8.0-47-generic', $reasons[0]->detail);
		$this->assertSame(200, $reasons[0]->since);
	}

	public function testRhelKernelsAcrossMinorReleases(): void {
		$this->host->release = '5.14.0-427.13.1.el9_4.x86_64';
		$this->kernels(['5.14.0-427.13.1.el9_4.x86_64' => 100, '5.14.0-503.15.1.el9_5.x86_64' => 200]);

		$this->assertSame('5.14.0-503.15.1.el9_5.x86_64', (new KernelProbe($this->host))->getRebootReasons()[0]->detail);
	}

	public function testRunningTheNewestKernel(): void {
		$this->host->release = '6.8.0-47-generic';
		$this->kernels(['6.8.0-45-generic' => 100, '6.8.0-47-generic' => 200]);

		$this->assertSame([], (new KernelProbe($this->host))->getRebootReasons());
	}

	public function testRunningKernelWasRemoved(): void {
		$this->host->release = '6.11.2-arch1-1';
		$this->kernels(['6.11.3-arch1-1' => 300]);

		$reasons = (new KernelProbe($this->host))->getRebootReasons();

		$this->assertSame(RebootReason::KIND_KERNEL_REMOVED, $reasons[0]->kind);
		$this->assertSame('6.11.3-arch1-1', $reasons[0]->detail);
	}

	public function testNoModulesToCompareWith(): void {
		$this->assertSame([], (new KernelProbe($this->host))->getRebootReasons());
	}

	public function testCoreLibrariesReplacedSinceBoot(): void {
		$this->host->globs['/lib/*-linux-gnu*/libc.so.6'] = ['/lib/x86_64-linux-gnu/libc.so.6'];
		$this->host->changed['/lib/x86_64-linux-gnu/libc.so.6'] = 5000;
		$this->host->changed['/usr/lib/systemd/systemd'] = 6000;
		$this->host->changed['/usr/bin/dbus-daemon'] = 7000;

		$reasons = (new CoreLibraryProbe($this->host))->getRebootReasons(1000);

		$this->assertSame(['glibc', 'dbus'], $reasons[0]->packages);
		$this->assertSame(5000, $reasons[0]->since);
	}

	public function testCoreLibrariesWithinTheBootSlack(): void {
		$this->host->changed['/usr/bin/dbus-daemon'] = 1030;

		$this->assertSame([], (new CoreLibraryProbe($this->host))->getRebootReasons(1000));
	}

	public function testPhpWithCurrentLibraries(): void {
		$this->host->withFile('/proc/self/maps', FakeHost::fixture('apache2-maps'));
		$this->host->phpSapi = 'apache2handler';

		$sample = (new PhpProcessProbe($this->host))->sample(OsRelease::parse(FakeHost::fixture('ubuntu-26.04-os-release')), 123);

		$this->assertNotNull($sample);
		$this->assertFalse($sample->isOutdated());
		$this->assertSame('apache2', $sample->service);
	}

	public function testPhpWithReplacedLibraries(): void {
		$this->host->withFile('/proc/self/maps', FakeHost::fixture('apache2-maps') . implode("\n", [
			'7f1c2a000000-7f1c2a1c0000 r-xp 00000000 fc:00 1048602                    /usr/lib/x86_64-linux-gnu/libssl.so.3 (deleted)',
			'7f1c2a1c0000-7f1c2a1c2000 r--p 001c0000 fc:00 1048602                    /usr/lib/x86_64-linux-gnu/libssl.so.3 (deleted)',
			'7f1c2b000000-7f1c2b010000 r-xp 00000000 fc:00 1048700                    /usr/lib/php/20240924/opcache.so (deleted)',
			'7f1c2c000000-7f1c2c001000 rw-s 00000000 00:01 4096                       /memfd:opcache_jit (deleted)',
			'7f1c2d000000-7f1c2d001000 rw-s 00000000 00:05 1234                       /dev/zero (deleted)',
			'7f1c2e000000-7f1c2e001000 r--p 00000000 fc:00 99                         /tmp/somefile.so (deleted)',
		]) . "\n");
		$this->host->links['/proc/self/exe'] = '/usr/sbin/php-fpm8.3 (deleted)';

		$release = new OsRelease('debian', [], 'Debian', 'Debian 12', '12', 'bookworm');
		$sample = (new PhpProcessProbe($this->host))->sample($release, 123);

		$this->assertSame(['php-fpm8.3', 'libssl.so.3', 'opcache.so'], $sample?->outdatedLibraries);
		$this->assertSame('php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm', $sample?->service);
	}

	public function testPhpServiceNamesPerDistribution(): void {
		$this->host->withFile('/proc/self/maps', '');
		$probe = new PhpProcessProbe($this->host);
		$rhel = new OsRelease('almalinux', ['rhel', 'centos', 'fedora'], 'AlmaLinux', 'AlmaLinux 9', '9.4', null);

		$this->assertSame('php-fpm', $probe->sample($rhel, 1)?->service);

		$this->host->phpSapi = 'apache2handler';
		$this->assertSame('httpd', $probe->sample($rhel, 1)?->service);

		$this->host->phpSapi = 'cli';
		$this->assertNull($probe->sample($rhel, 1), 'The CLI process says nothing about the web server');
	}

	public function testPhpConfinement(): void {
		$this->host->withFile('/proc/self/maps', '')
			->withFile('/proc/self/attr/apparmor/current', "unconfined\n");

		$this->assertSame('unconfined', (new PhpProcessProbe($this->host))->sample(null, 1)?->confinement);
	}

	public function testFailedUnits(): void {
		$this->host->withCommand('systemctl', ['list-units', '--failed', '--all', '--no-legend', '--plain', '--no-pager'],
			"php8.3-fpm.service loaded failed failed The PHP 8.3 FastCGI Process Manager\n● logrotate.service loaded failed failed Rotate log files\n");

		$this->assertSame(['php8.3-fpm.service', 'logrotate.service'], (new SystemdProbe($this->host))->getFailedUnits());
	}

	public function testNoFailedUnits(): void {
		$this->host->withCommand('systemctl', ['list-units', '--failed', '--all', '--no-legend', '--plain', '--no-pager'], '');

		$this->assertSame([], (new SystemdProbe($this->host))->getFailedUnits());
	}

	public function testSystemdUnavailable(): void {
		$this->host->withCommand('systemctl', ['list-units', '--failed', '--all', '--no-legend', '--plain', '--no-pager'],
			'', 1, "System has not been booted with systemd as init system (PID 1). Can't operate.\n");

		$this->assertNull((new SystemdProbe($this->host))->getFailedUnits());
	}

	public function testUnitStateRejectsOddNames(): void {
		$this->assertNull((new SystemdProbe($this->host))->getUnitFileState('foo; reboot'));
		$this->assertSame([], $this->host->ran);
	}

	public function testTimeSync(): void {
		$this->host->withCommand('timedatectl', ['show', '--property=NTPSynchronized', '--value'], "yes\n");
		$this->assertSame(['synchronized' => true, 'source' => 'timedatectl'], (new TimeSyncProbe($this->host))->probe());

		$this->host->withCommand('timedatectl', ['show', '--property=NTPSynchronized', '--value'], "no\n");
		$this->assertFalse((new TimeSyncProbe($this->host))->probe()['synchronized']);
	}

	public function testTimeSyncFallsBackToChrony(): void {
		$this->host->withCommand('chronyc', ['-n', 'tracking'], "Reference ID    : C0A80001 (192.168.0.1)\nLeap status     : Not synchronised\n");

		$this->assertSame(['synchronized' => false, 'source' => 'chrony'], (new TimeSyncProbe($this->host))->probe());
	}

	public function testTimeSyncUnknown(): void {
		$this->assertSame(['synchronized' => null, 'source' => null], (new TimeSyncProbe($this->host))->probe());
	}

	public function testSecurityFrameworks(): void {
		$this->host->withFile('/sys/fs/selinux/enforce', '1')
			->withFile('/sys/module/apparmor/parameters/enabled', "N\n");
		$probe = new SecurityProbe($this->host);

		$this->assertSame('enforcing', $probe->getSelinux());
		$this->assertSame('disabled', $probe->getApparmor());
	}

	public function testLivePatches(): void {
		$this->host->modified['/sys/kernel'] = 1;
		$this->host->globs['/sys/kernel/livepatch/*/enabled'] = ['/sys/kernel/livepatch/lkp_Ubuntu_6_8_0_45/enabled', '/sys/kernel/livepatch/old/enabled'];
		$this->host->withFile('/sys/kernel/livepatch/lkp_Ubuntu_6_8_0_45/enabled', "1\n")
			->withFile('/sys/kernel/livepatch/old/enabled', "0\n");

		$this->assertSame(['lkp_Ubuntu_6_8_0_45'], (new SecurityProbe($this->host))->getLivePatches());
	}

	public function testUbuntuPro(): void {
		$this->assertFalse(SubscriptionProbe::parseUbuntuProStatus(FakeHost::fixture('ubuntu-pro-status-unattached.json')));
		$this->assertTrue(SubscriptionProbe::parseUbuntuProStatus('{"attached": true, "services": [{"name": "esm-infra", "status": "enabled"}]}'));
		$this->assertFalse(SubscriptionProbe::parseUbuntuProStatus('{"attached": true, "services": [{"name": "esm-infra", "status": "disabled"}]}'));
		$this->assertNull(SubscriptionProbe::parseUbuntuProStatus('not json'));
	}

	public function testDebianExtendedLts(): void {
		$this->host->globs['/etc/apt/sources.list.d/*.list'] = ['/etc/apt/sources.list.d/elts.list'];
		$this->host->withFile('/etc/apt/sources.list', "deb http://deb.debian.org/debian bullseye main\n")
			->withFile('/etc/apt/sources.list.d/elts.list', "deb http://deb.freexian.com/extended-lts bullseye main\n");

		$this->assertTrue((new SubscriptionProbe($this->host))->isActive('debian-elts'));
		$this->assertNull((new SubscriptionProbe(new FakeHost()))->isActive('debian-elts'));
		$this->assertNull((new SubscriptionProbe($this->host))->isActive('rhel-els'));
	}
}
