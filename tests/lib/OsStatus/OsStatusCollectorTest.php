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
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class OsStatusCollectorTest extends \Test\TestCase {
	private const NOW = 1792411200;
	private const BOOT = 1792000000;

	private FakeHost $host;
	/** @var array<string, mixed> */
	private array $config = [];
	private ?string $stored = null;
	private ?TimeSyncProbe $timeSync = null;
	private ILockingProvider&MockObject $locks;

	protected function setUp(): void {
		parent::setUp();
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->host = new FakeHost();
		$this->host->host = 'cloud.example.org';
		$this->host->withFile('/etc/os-release', FakeHost::fixture('ubuntu-24.04-os-release'));
	}

	private function collector(int $now = self::NOW): OsStatusCollector {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(fn (string $key, string $default = ''): string => (string)($this->config[$key] ?? $default));
		$appConfig->method('getAppValueBool')->willReturnCallback(fn (string $key, bool $default = false): bool => (bool)($this->config[$key] ?? $default));
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
			new PackageManagerFactory(new Apt($host, $systemd), new Dnf($host, $rpm, $systemd), new Zypper($host, $rpm, $systemd), new Pacman($host, $time, $appConfig), new Apk($host)),
			new KernelProbe($host),
			new CoreLibraryProbe($host),
			$systemd,
			$this->timeSync ?? new TimeSyncProbe($host),
			new SecurityProbe($host),
			new HelperReportReader($host),
			new OsStatusStore($appConfig, $this->appData(), $cacheFactory),
			$this->createMock(LoggerInterface::class),
			$this->locks,
		);
	}

	/**
	 * App data that keeps the stored status in $this->stored.
	 */
	private function appData(): IAppData {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->stored ?? throw new NotFoundException());
		$file->method('putContent')->willReturnCallback(function (mixed $content): void {
			$this->stored = (string)$content;
		});
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(fn (): bool => $this->stored !== null);
		$folder->method('getFile')->willReturnCallback(fn (): ISimpleFile => $this->stored !== null ? $file : throw new NotFoundException());
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

	public function testCollectAndStoreHoldsTheLock(): void {
		$this->withPendingUpdates();
		$this->locks->expects($this->once())->method('acquireLock')->with(OsStatusCollector::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		$this->locks->expects($this->once())->method('releaseLock')->with(OsStatusCollector::LOCK, ILockingProvider::LOCK_EXCLUSIVE);

		$this->assertNotNull($this->collector()->collectAndStore());
	}

	public function testCollectAndStoreSkipsWhileAnotherCollectionRuns(): void {
		$this->locks->method('acquireLock')->willThrowException(new LockedException(OsStatusCollector::LOCK));
		$this->locks->expects($this->never())->method('releaseLock');

		$this->assertNull($this->collector()->collectAndStore());
		$this->assertSame([], $this->host->ran);
		$this->assertNull($this->stored, 'Nothing was saved');
	}

	private const APT_SIMULATION = 'apt-get -s -o Debug::NoLocking=1 dist-upgrade';

	/**
	 * An Ubuntu host whose package state, lists and apt configuration have
	 * known modification times.
	 */
	private function withAptHost(): void {
		$this->withPendingUpdates();
		$this->host->withCommand('apt-mark', ['showhold'], "nginx\n")
			->withCommand('timedatectl', ['show', '--property=NTPSynchronized', '--value'], "yes\n");
		foreach ([
			'/var/lib/dpkg/status' => self::NOW - 7200,
			'/var/lib/dpkg/updates' => self::NOW - 7200,
			'/var/lib/apt/lists' => self::NOW - 3600,
			'/var/lib/apt/periodic/update-success-stamp' => self::NOW - 3600,
			'/etc/apt' => self::NOW - 86400,
			'/etc/apt/sources.list.d' => self::NOW - 86400,
			'/etc/apt/apt.conf.d' => self::NOW - 86400,
		] as $path => $time) {
			$this->host->modified[$path] = $time;
		}
	}

	private function scheduledRun(int $now): OsStatus {
		$this->host->ran = [];
		$status = $this->collector($now)->collectAndStore(reuseUnchangedUpdates: true);
		$this->assertNotNull($status);

		return $status;
	}

	public function testUnchangedInputsReuseThePendingUpdates(): void {
		$this->withAptHost();
		$first = $this->scheduledRun(self::NOW);
		$this->assertContains(self::APT_SIMULATION, $this->host->ran);
		$this->assertFalse($first->updatesReused);
		$this->assertSame(self::NOW, $first->updatesCheckedAt);
		$this->assertSame(self::NOW - 7200, $first->updateInputs['/var/lib/dpkg/status'] ?? null);
		$this->assertSame(0, $first->updateInputs['/etc/apt/preferences'] ?? null, 'A missing file counts as absent');

		$this->host->withFile('/run/reboot-required', "*** System restart required ***\n", self::NOW + 1800);
		$second = $this->scheduledRun(self::NOW + 3600);

		$this->assertNotContains(self::APT_SIMULATION, $this->host->ran);
		$this->assertNotContains('apt-mark showhold', $this->host->ran);
		$this->assertContains('timedatectl show --property=NTPSynchronized --value', $this->host->ran, 'The cheap checks still run');
		$this->assertTrue($second->updatesReused);
		$this->assertSame(self::NOW, $second->updatesCheckedAt);
		$this->assertSame(self::NOW + 3600, $second->collectedAt);
		$this->assertSame(['libssl3t64', 'tzdata'], array_map(static fn ($u) => $u->name, $second->updates ?? []));
		$this->assertTrue($second->securityClassified);
		$this->assertSame('nginx', $second->held[0]->name ?? null);
		$this->assertSame(self::NOW, $second->updates[0]->since, 'First seen still counts from the first run');
		$this->assertSame(RebootReason::KIND_DISTRIBUTION, $second->rebootReasons[0]->kind ?? null, 'Reboots are checked again');
		$this->assertSame($first->updateInputs, $second->updateInputs);

		$third = $this->scheduledRun(self::NOW + 7200);
		$this->assertTrue($third->updatesReused);
		$this->assertSame(self::NOW, $third->updatesCheckedAt, 'Reuse does not move the time of the last real check');
	}

	public static function dataChanges(): array {
		return [
			'packages installed' => [static function (FakeHost $host): void {
				$host->modified['/var/lib/dpkg/status'] = self::NOW + 600;
			}],
			'lists refreshed' => [static function (FakeHost $host): void {
				$host->modified['/var/lib/apt/lists'] = self::NOW + 600;
			}],
			'source added' => [static function (FakeHost $host): void {
				$host->globs['/etc/apt/sources.list.d/*'] = ['/etc/apt/sources.list.d/extra.sources'];
				$host->modified['/etc/apt/sources.list.d/extra.sources'] = self::NOW - 86400;
			}],
			'pinning added' => [static function (FakeHost $host): void {
				$host->modified['/etc/apt/preferences'] = self::NOW + 600;
			}],
			'lists hidden' => [static function (FakeHost $host): void {
				unset($host->modified['/var/lib/apt/lists']);
				$host->files['/var/lib/apt/lists'] = '';
			}],
			'release upgraded' => [static function (FakeHost $host): void {
				$host->withFile('/etc/os-release', str_replace('24.04.3', '24.04.4', FakeHost::fixture('ubuntu-24.04-os-release')));
			}],
		];
	}

	/**
	 * @param \Closure(FakeHost): void $change
	 */
	#[DataProvider('dataChanges')]
	public function testAChangeAsksThePackageManagerAgain(\Closure $change): void {
		$this->withAptHost();
		$this->scheduledRun(self::NOW);

		$change($this->host);
		$status = $this->scheduledRun(self::NOW + 3600);

		$this->assertContains(self::APT_SIMULATION, $this->host->ran);
		$this->assertContains('apt-mark showhold', $this->host->ran);
		$this->assertFalse($status->updatesReused);
		$this->assertSame(self::NOW + 3600, $status->updatesCheckedAt);
	}

	public function testThePackageManagerIsAskedAtLeastDaily(): void {
		$this->withAptHost();
		$this->scheduledRun(self::NOW);

		$this->assertTrue($this->scheduledRun(self::NOW + OsStatusCollector::REUSE_UPDATES_FOR - 1)->updatesReused);
		$status = $this->scheduledRun(self::NOW + OsStatusCollector::REUSE_UPDATES_FOR);

		$this->assertContains(self::APT_SIMULATION, $this->host->ran);
		$this->assertFalse($status->updatesReused);
	}

	public function testAFailedQueryIsNotReused(): void {
		$this->withAptHost();
		$this->host->withCommand('apt-get', ['-s', '-o', 'Debug::NoLocking=1', 'dist-upgrade'], '', 100, "E: Could not get lock\n");
		$failed = $this->scheduledRun(self::NOW);
		$this->assertNull($failed->updates);
		$this->assertNull($failed->updateInputs);

		$this->withPendingUpdates();
		$status = $this->scheduledRun(self::NOW + 3600);

		$this->assertContains(self::APT_SIMULATION, $this->host->ran);
		$this->assertCount(2, $status->updates ?? []);
	}

	public function testAnIncompleteAnswerIsNotReused(): void {
		$this->host->withFile('/etc/os-release', "NAME=\"AlmaLinux\"\nID=\"almalinux\"\nID_LIKE=\"rhel centos fedora\"\nVERSION_ID=\"9.8\"\nPRETTY_NAME=\"AlmaLinux 9.8\"\n");
		$this->host->binaries = ['dnf', 'dnf-3'];
		$this->host->globs['/var/cache/dnf/*-updateinfo.solvx'] = ['/var/cache/dnf/baseos-updateinfo.solvx'];
		foreach (['/var/cache/dnf/last_makecache', '/var/lib/rpm/rpmdb.sqlite', '/var/lib/rpm', '/var/cache', '/var/cache/dnf', '/etc/dnf', '/etc/dnf/plugins', '/etc/yum.repos.d'] as $path) {
			$this->host->modified[$path] = self::NOW - 600;
		}
		$this->host->withCommand('dnf-3', ['-q', '--cacheonly', 'check-update'], FakeHost::fixture('alma-9-dnf-check-update-pending'), 100)
			->withCommand('dnf-3', ['-q', '--cacheonly', 'updateinfo', 'list', '--security', '--available'], '', 1, "Error: something broke\n");

		$first = $this->scheduledRun(self::NOW);
		$this->assertCount(9, $first->updates ?? []);
		$this->assertNull($first->updateInputs, 'The advisories are missing');

		$this->scheduledRun(self::NOW + 3600);
		$this->assertContains('dnf-3 -q --cacheonly check-update', $this->host->ran);
	}

	public function testUpdatesFromTheHelperAreNotReused(): void {
		$this->withRootsHelperReportPath();
		$this->host->withFile(HelperReportReader::DEFAULT_PATH, (string)json_encode([
			'schema' => 1, 'helperVersion' => '1.0.0', 'generatedAt' => self::NOW - 600, 'hostname' => 'cloud.example.org',
			'bootTime' => self::BOOT,
			'pendingUpdates' => [['name' => 'openssl', 'installed' => '1', 'candidate' => '2', 'security' => true]],
		]));

		$status = $this->scheduledRun(self::NOW);

		$this->assertSame(OsStatus::SOURCE_HELPER, $status->updatesSource);
		$this->assertSame(self::NOW - 600, $status->updatesCheckedAt, 'When the helper asked');
		$this->assertNull($status->updateInputs);
	}

	public function testCheckNowAlwaysAsksThePackageManager(): void {
		$this->withAptHost();
		$this->scheduledRun(self::NOW);

		$this->host->ran = [];
		$status = $this->collector(self::NOW + 3600)->collectAndStore();

		$this->assertContains(self::APT_SIMULATION, $this->host->ran);
		$this->assertFalse($status?->updatesReused);
		$this->assertSame(self::NOW + 3600, $status?->updatesCheckedAt);
	}

	public function testTheReuseFieldsSurviveStorage(): void {
		$status = new OsStatus(OsStatus::STATE_OK, self::NOW, updatesCheckedAt: self::NOW - 60, updatesReused: true, updateInputs: ['/var/lib/dpkg/status' => self::NOW - 600]);

		$restored = OsStatus::fromArray(json_decode((string)json_encode($status->toArray()), true));

		$this->assertSame(self::NOW - 60, $restored?->updatesCheckedAt);
		$this->assertTrue($restored?->updatesReused);
		$this->assertSame(['/var/lib/dpkg/status' => self::NOW - 600], $restored?->updateInputs);
		$this->assertNull(OsStatus::fromArray(['updateInputs' => ['/x' => 'soon']] + $status->toArray())?->updateInputs, 'A damaged fingerprint is no fingerprint');
	}
}
