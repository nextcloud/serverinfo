<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\Os;
use OCA\ServerInfo\OsStatus\Environment;
use OCA\ServerInfo\OsStatus\EnvironmentDetector;
use OCA\ServerInfo\OsStatus\Health\SecurityProbe;
use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Health\TimeSyncProbe;
use OCA\ServerInfo\OsStatus\Helper\HelperReportReader;
use OCA\ServerInfo\OsStatus\Helper\HelperState;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCA\ServerInfo\OsStatus\OsStatusStore;
use OCA\ServerInfo\OsStatus\Packages\Apk;
use OCA\ServerInfo\OsStatus\Packages\Apt;
use OCA\ServerInfo\OsStatus\Packages\Dnf;
use OCA\ServerInfo\OsStatus\Packages\PackageManagerFactory;
use OCA\ServerInfo\OsStatus\Packages\Pacman;
use OCA\ServerInfo\OsStatus\Packages\Rpm;
use OCA\ServerInfo\OsStatus\Packages\Zypper;
use OCA\ServerInfo\OsStatus\Reboot\CoreLibraryProbe;
use OCA\ServerInfo\OsStatus\Reboot\KernelProbe;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Support\SubscriptionProbe;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;
use OCA\ServerInfo\OsStatus\Support\SupportTable;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class OsStatusCollectorTest extends \Test\TestCase {
	private const NOW = 1792411200;
	private const BOOT = 1792000000;

	private FakeHost $host;
	/** @var array<string, mixed> */
	private array $config = [];
	private ?string $stored = null;
	private ?TimeSyncProbe $timeSync = null;

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

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		$host = $this->host;
		$systemd = new SystemdProbe($host);
		$rpm = new Rpm($host);

		return new OsStatusCollector(
			$host,
			$appConfig,
			$time,
			$os,
			new EnvironmentDetector($host),
			new SupportTable(),
			new SubscriptionProbe($host),
			new PackageManagerFactory(new Apt($host, $systemd), new Dnf($host, $rpm, $systemd), new Zypper($host, $rpm, $systemd), new Pacman($host), new Apk($host)),
			new KernelProbe($host),
			new CoreLibraryProbe($host),
			$systemd,
			$this->timeSync ?? new TimeSyncProbe($host),
			new SecurityProbe($host),
			new HelperReportReader($host),
			new OsStatusStore($appConfig, $this->appData(), $cacheFactory),
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * App data that keeps the stored status in $this->stored.
	 */
	private function appData(): IAppData {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('newFile')->willReturnCallback(function (string $name, mixed $content = null): ISimpleFile {
			$this->stored = (string)$content;

			return $this->createMock(ISimpleFile::class);
		});
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);

		return $appData;
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
		$this->assertNull($status->restartServices, 'Without the helper other services are unknown');
		$this->assertSame(SupportStatus::STATE_SUPPORTED, $status->support->state(self::NOW));
		$this->assertFalse($status->support->extendedActive);
		$this->assertSame(self::NOW - 3600, $status->listsRefreshedAt);
		$this->assertSame([], $status->failedUnits);
		$this->assertTrue($status->timeSynchronized);
		$this->assertSame(HelperState::STATE_ABSENT, $status->helper->state);
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

	/**
	 * The helper's report and every directory above it are root's alone.
	 */
	private function withRootsHelperReportPath(): void {
		$this->host->owners[HelperReportReader::DEFAULT_PATH] = ['owner' => 0, 'mode' => 0o100644];
		foreach (['/var/lib/serverinfo', '/var/lib', '/var', '/'] as $directory) {
			$this->host->owners[$directory] = ['owner' => 0, 'mode' => 0o40755];
		}
	}

	public function testHelperFillsWhatTheWebServerCannotSee(): void {
		$this->withPendingUpdates();
		$this->withRootsHelperReportPath();
		$this->host->withFile(HelperReportReader::DEFAULT_PATH, (string)json_encode([
			'schema' => 1, 'helperVersion' => '1.0.0', 'generatedAt' => self::NOW - 600, 'hostname' => 'cloud.example.org',
			'bootTime' => self::BOOT, 'listsRefreshedAt' => self::NOW - 600,
			'restartServices' => ['php8.3-fpm.service'],
			'rebootReasons' => [['kind' => 'microcode', 'detail' => null]],
		]));

		$status = $this->collector()->collect();

		$this->assertSame(HelperState::STATE_OK, $status->helper->state);
		$this->assertSame('php8.3-fpm.service', $status->restartServices[0]->unit);
		$this->assertSame(self::NOW, $status->restartServices[0]->since);
		$this->assertSame(self::NOW - 600, $status->listsRefreshedAt);
		$this->assertContains(RebootReason::KIND_MICROCODE, array_map(static fn ($r) => $r->kind, $status->rebootReasons ?? []));
	}

	public function testUpdatesFromTheHelperWhenTheWebServerCannotAsk(): void {
		$this->withRootsHelperReportPath();
		$this->host->withFile(HelperReportReader::DEFAULT_PATH, (string)json_encode([
			'schema' => 1, 'helperVersion' => '1.0.0', 'generatedAt' => self::NOW - 600, 'hostname' => 'cloud.example.org',
			'bootTime' => self::BOOT,
			'pendingUpdates' => [['name' => 'openssl', 'installed' => '1', 'candidate' => '2', 'security' => true]],
		]));

		$status = $this->collector()->collect();

		$this->assertNull($status->packageManager, 'apt-get is not available to the web server here');
		$this->assertSame(OsStatus::SOURCE_HELPER, $status->updatesSource);
		$this->assertSame('openssl', $status->updates[0]->name);
		$this->assertContains('No supported package manager was found for Ubuntu 24.04.3 LTS.', $status->diagnostics);
	}

	public function testDnf5LeavesTheUpdatesToTheHelperWithoutComplaint(): void {
		$this->host->withFile('/etc/os-release', "NAME=\"Fedora Linux\"\nID=fedora\nVERSION_ID=44\nPRETTY_NAME=\"Fedora Linux 44\"\n");
		$this->host->binaries = ['dnf', 'dnf5'];

		$without = $this->collector()->collect();

		$this->assertSame('dnf', $without->packageManager);
		$this->assertNull($without->updates);
		$this->assertContains('dnf 5 can only be asked about pending updates as root, so the root helper reports them on this system.', $without->diagnostics);

		$this->withRootsHelperReportPath();
		$this->host->withFile(HelperReportReader::DEFAULT_PATH, (string)json_encode([
			'schema' => 1, 'helperVersion' => '1.0.0', 'generatedAt' => self::NOW - 600, 'hostname' => 'cloud.example.org',
			'bootTime' => self::BOOT, 'listsRefreshedAt' => self::NOW - 600,
			'pendingUpdates' => [['name' => 'openssl-libs', 'installed' => '1', 'candidate' => '2', 'security' => true]],
		]));

		$with = $this->collector()->collect();

		$this->assertSame(OsStatus::SOURCE_HELPER, $with->updatesSource);
		$this->assertSame([], array_values(array_filter($with->diagnostics, static fn (string $d): bool => str_contains($d, 'dnf 5'))));
		$this->assertSame([], array_values(array_filter($this->host->ran, static fn (string $c): bool => str_starts_with($c, 'dnf'))), 'dnf 5 never runs');
	}

	public function testUnclassifiedDnfUpdatesAreKeptWithADiagnostic(): void {
		$this->host->withFile('/etc/os-release', "NAME=\"AlmaLinux\"\nID=\"almalinux\"\nID_LIKE=\"rhel centos fedora\"\nVERSION_ID=\"9.8\"\nPRETTY_NAME=\"AlmaLinux 9.8\"\n");
		$this->host->binaries = ['dnf', 'dnf-3'];
		$this->host->globs['/var/cache/dnf/*-updateinfo.solvx'] = ['/var/cache/dnf/baseos-updateinfo.solvx'];
		$this->host->modified['/var/cache/dnf/last_makecache'] = self::NOW - 600;
		$this->host->withCommand('dnf-3', ['-q', '--cacheonly', 'check-update'], FakeHost::fixture('alma-9-dnf-check-update-pending'), 100)
			->withCommand('dnf-3', ['-q', '--cacheonly', 'updateinfo', 'list', '--security', '--available'], '', 1, "Error: something broke\n");

		$status = $this->collector()->collect();

		$this->assertCount(9, $status->updates ?? []);
		$this->assertFalse($status->securityClassified);
		$this->assertContains('dnf could not list security advisories, so pending updates are not told apart into security fixes and others: Error: something broke', $status->diagnostics);
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

	public function testAThrowingProbeCostsOnlyItsOwnFinding(): void {
		$this->withPendingUpdates();
		$this->host->modified['/var/lib/apt/periodic/update-success-stamp'] = self::NOW - 3600;
		$this->timeSync = $this->createMock(TimeSyncProbe::class);
		$this->timeSync->method('probe')->willThrowException(new \RuntimeException('broken'));

		$status = $this->collector()->collect();

		$this->assertCount(2, $status->updates ?? []);
		$this->assertNull($status->timeSynchronized);
		$this->assertContains('The time synchronisation could not be determined; see the Nextcloud log.', $status->diagnostics);
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

		$this->assertSame(1, json_decode((string)$this->stored, true)['schema'] ?? null);
	}
}
