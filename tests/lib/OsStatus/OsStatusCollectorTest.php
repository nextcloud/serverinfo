<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\OsStatus\Environment;
use OCA\ServerInfo\OsStatus\EnvironmentDetector;
use OCA\ServerInfo\OsStatus\Health\SecurityProbe;
use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Health\TimeSyncProbe;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCA\ServerInfo\OsStatus\OsStatusStore;
use OCA\ServerInfo\OsStatus\Packages\Apt;
use OCA\ServerInfo\OsStatus\Packages\PackageManagerFactory;
use OCA\ServerInfo\OsStatus\Reboot\CoreLibraryProbe;
use OCA\ServerInfo\OsStatus\Reboot\KernelProbe;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Support\SubscriptionProbe;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;
use OCA\ServerInfo\OsStatus\Support\SupportTable;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

class OsStatusCollectorTest extends \Test\TestCase {
	private const NOW = 1792411200;
	private const BOOT = 1792000000;

	private FakeHost $host;
	/** @var array<string, mixed> */
	private array $config = [];

	protected function setUp(): void {
		parent::setUp();
		$this->host = new FakeHost();
		$this->host->host = 'cloud.example.org';
		$this->host->withFile('/etc/os-release', FakeHost::fixture('ubuntu-24.04-os-release'));
	}

	private function collector(int $now = self::NOW): OsStatusCollector {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(fn (string $key, string $default = ''): string => (string)($this->config[$key] ?? $default));
		$appConfig->method('getAppValueArray')->willReturnCallback(fn (string $key): array => $this->config[$key] ?? []);
		$appConfig->method('setAppValueArray')->willReturnCallback(function (string $key, array $value): bool {
			$this->config[$key] = $value;

			return true;
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		$os = $this->createMock(Os::class);
		$os->method('getUptime')->willReturn($now - self::BOOT);

		$host = $this->host;
		$systemd = new SystemdProbe($host);

		return new OsStatusCollector(
			$host,
			$appConfig,
			$time,
			$os,
			new EnvironmentDetector($host),
			new SupportTable(),
			new SubscriptionProbe($host),
			new PackageManagerFactory(new Apt($host, $systemd)),
			new KernelProbe($host),
			new CoreLibraryProbe($host),
			$systemd,
			new TimeSyncProbe($host),
			new SecurityProbe($host),
			new OsStatusStore($appConfig),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function withPendingUpdates(): void {
		$this->host->withCommand('apt-get', ['-s', '-o', 'Debug::NoLocking=1', 'dist-upgrade'], implode("\n", [
			'Inst tzdata [2025a-0ubuntu0.24.04] (2025b-0ubuntu0.24.04 Ubuntu:24.04/noble-updates [all])',
			'Inst libssl3t64 [3.0.13-0ubuntu3.4] (3.0.13-0ubuntu3.5 Ubuntu:24.04/noble-security [amd64])',
		]));
	}

	public function testRestrictedModeReadsNothing(): void {
		$this->config['restricted_mode'] = 'yes';

		$status = $this->collector()->collect();

		$this->assertSame(OsStatus::STATE_RESTRICTED, $status->state);
		$this->assertSame([], $this->host->ran);
	}

	public function testOnlyLinuxIsInspected(): void {
		$this->host->family = 'BSD';
		$this->host->release = '14.1-RELEASE';

		$status = $this->collector()->collect();

		$this->assertSame(OsStatus::STATE_UNSUPPORTED, $status->state);
		$this->assertSame('14.1-RELEASE', $status->kernel);
	}

	public function testAnUbuntuHost(): void {
		$this->withPendingUpdates();
		$this->host->withCommand('systemd-detect-virt', ['--vm'], "kvm\n")
			->withCommand('apt-mark', ['showhold'], '')
			->withCommand('systemctl', ['list-units', '--failed', '--all', '--no-legend', '--plain', '--no-pager'], '')
			->withCommand('timedatectl', ['show', '--property=NTPSynchronized', '--value'], "yes\n")
			->withFile('/run/reboot-required', "*** System restart required ***\n", self::NOW - 2 * 86400)
			->withFile('/var/lib/ubuntu-advantage/status.json', FakeHost::fixture('ubuntu-pro-status-unattached.json'));
		$this->host->modified['/var/lib/apt/periodic/update-success-stamp'] = self::NOW - 3600;

		$status = $this->collector()->collect();

		$this->assertSame(OsStatus::STATE_OK, $status->state);
		$this->assertSame('Ubuntu 24.04.3 LTS', $status->release?->prettyName);
		$this->assertSame('kvm', $status->environment->virtualization);
		$this->assertSame('apt', $status->packageManager);
		$this->assertSame(OsStatus::SOURCE_SYSTEM, $status->updatesSource);
		$this->assertSame(['libssl3t64', 'tzdata'], array_map(static fn ($u) => $u->name, $status->updates ?? []), 'Security updates come first');
		$this->assertSame(self::NOW, $status->updates[0]->since);
		$this->assertSame(RebootReason::KIND_DISTRIBUTION, $status->rebootReasons[0]->kind);
		$this->assertSame(self::NOW - 2 * 86400, $status->rebootReasons[0]->since);
		$this->assertSame(SupportStatus::STATE_SUPPORTED, $status->support->state(self::NOW));
		$this->assertFalse($status->support->extendedActive);
		$this->assertSame(self::NOW - 3600, $status->listsRefreshedAt);
		$this->assertSame([], $status->failedUnits);
		$this->assertTrue($status->timeSynchronized);
		$this->assertSame(self::BOOT, $status->bootTime);
	}

	public function testFirstSeenSurvivesTheNextRun(): void {
		$this->withPendingUpdates();
		$this->collector()->collect();
		$status = $this->collector(self::NOW + 86400)->collect();

		$this->assertSame(self::NOW, $status->updates[0]->since);
	}

	public function testASupersedingUpdateKeepsCounting(): void {
		$this->withPendingUpdates();
		$this->collector()->collect();
		$this->host->withCommand('apt-get', ['-s', '-o', 'Debug::NoLocking=1', 'dist-upgrade'],
			'Inst libssl3t64 [3.0.13-0ubuntu3.4] (3.0.13-0ubuntu3.6 Ubuntu:24.04/noble-security [amd64])');
		$status = $this->collector(self::NOW + 86400)->collect();

		$this->assertSame('3.0.13-0ubuntu3.6', $status->updates[0]->candidateVersion);
		$this->assertSame(self::NOW, $status->updates[0]->since);
	}

	public function testCoreLibrariesReplacedSinceBoot(): void {
		$this->host->changed['/lib64/libc.so.6'] = self::BOOT + 86400;

		$this->assertSame(RebootReason::KIND_CORE_LIBRARY, $this->collector()->collect()->rebootReasons[0]->kind ?? null);
	}

	public function testASystemContainerDoesNotCompareCoreLibrariesWithTheHostsBoot(): void {
		$this->host->withFile('/run/systemd/container', "lxc\n");
		$this->host->changed['/lib64/libc.so.6'] = self::BOOT + 86400;

		$status = $this->collector()->collect();

		$this->assertSame(Environment::KIND_SYSTEM_CONTAINER, $status->environment->kind);
		$this->assertSame([], $status->rebootReasons);
	}

	public function testAContainerIsNotAskedAboutPackagesOrReboots(): void {
		$this->host->files['/.dockerenv'] = '';
		$this->withPendingUpdates();

		$status = $this->collector()->collect();

		$this->assertSame(Environment::KIND_APPLICATION_CONTAINER, $status->environment->kind);
		$this->assertNull($status->packageManager);
		$this->assertNull($status->updates);
		$this->assertNull($status->rebootReasons);
		$this->assertNotContains('apt-get -s -o Debug::NoLocking=1 dist-upgrade', $this->host->ran);
	}

	public function testFailuresBecomeDiagnostics(): void {
		$this->host->withCommand('apt-get', ['-s', '-o', 'Debug::NoLocking=1', 'dist-upgrade'], '', 100, "E: The package lists are damaged\n");

		$status = $this->collector()->collect();

		$this->assertNull($status->updates);
		$this->assertContains('apt-get -s dist-upgrade failed: E: The package lists are damaged', $status->diagnostics);
	}

	public function testMissingPackageListsLeaveTheUpdatesUnknown(): void {
		$this->host->withCommand('apt-get', ['-s', '-o', 'Debug::NoLocking=1', 'dist-upgrade'], '');

		$status = $this->collector()->collect();

		$this->assertNull($status->updates);
		$this->assertNull($status->updatesSource);
		$this->assertContains('The package lists could not be found, so pending updates are unknown until the lists are refreshed.', $status->diagnostics);
	}

	public function testNoUpdatesWithRefreshedPackageLists(): void {
		$this->host->withCommand('apt-get', ['-s', '-o', 'Debug::NoLocking=1', 'dist-upgrade'], '');
		$this->host->modified['/var/lib/apt/periodic/update-success-stamp'] = self::NOW - 3600;

		$this->assertSame([], $this->collector()->collect()->updates);
	}

	public function testAptIsRunWhenOpenBasedirHidesIt(): void {
		$this->withPendingUpdates();
		$this->host->binaries = [];
		$this->host->hiddenBinaries = ['apt-get'];
		$this->host->basedir = ['/var/www'];
		$this->host->modified['/var/lib/apt/periodic/update-success-stamp'] = self::NOW - 3600;

		$status = $this->collector()->collect();

		$this->assertSame('apt', $status->packageManager);
		$this->assertCount(2, $status->updates ?? []);
	}

	public function testWithoutProcesses(): void {
		$this->host->commandsAllowed = false;

		$status = $this->collector()->collect();

		$this->assertSame(OsStatus::STATE_OK, $status->state);
		$this->assertNull($status->updates);
		$this->assertStringContainsString('proc_open', $status->diagnostics[0]);
	}

	public function testCollectAndStorePersists(): void {
		$this->withPendingUpdates();

		$this->collector()->collectAndStore();

		$this->assertSame(1, $this->config[ConfigLexicon::CACHED_OS_STATUS]['schema'] ?? null);
	}
}
