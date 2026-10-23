<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\OsStatus\Health\SecurityProbe;
use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Health\TimeSyncProbe;
use OCA\ServerInfo\OsStatus\Helper\HelperReport;
use OCA\ServerInfo\OsStatus\Helper\HelperReportReader;
use OCA\ServerInfo\OsStatus\Host\IHost;
use OCA\ServerInfo\OsStatus\Packages\AutomaticUpdates;
use OCA\ServerInfo\OsStatus\Packages\IPackageManager;
use OCA\ServerInfo\OsStatus\Packages\IReportsDiagnostics;
use OCA\ServerInfo\OsStatus\Packages\NeedsRootException;
use OCA\ServerInfo\OsStatus\Packages\PackageManagerFactory;
use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;
use OCA\ServerInfo\OsStatus\Packages\ProbeException;
use OCA\ServerInfo\OsStatus\Reboot\CoreLibraryProbe;
use OCA\ServerInfo\OsStatus\Reboot\KernelProbe;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Restart\ServiceRestart;
use OCA\ServerInfo\OsStatus\Support\SubscriptionProbe;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;
use OCA\ServerInfo\OsStatus\Support\SupportTable;
use OCA\ServerInfo\OsStatus\Verdict\OsVerdict;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Asks the system everything the operating system page shows.
 *
 * This runs commands, so it belongs in the background job or behind an
 * explicit, rate-limited refresh; pages read the stored result.
 *
 * @psalm-api
 */
class OsStatusCollector {
	/** More pending updates than this are counted but not listed. */
	public const MAX_LISTED_UPDATES = 500;

	public function __construct(
		private IHost $host,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private Os $os,
		private EnvironmentDetector $environmentDetector,
		private SupportTable $supportTable,
		private SubscriptionProbe $subscriptions,
		private PackageManagerFactory $packageManagers,
		private KernelProbe $kernelProbe,
		private CoreLibraryProbe $coreLibraryProbe,
		private SystemdProbe $systemd,
		private TimeSyncProbe $timeSync,
		private SecurityProbe $security,
		private HelperReportReader $helperReader,
		private OsStatusStore $store,
		private LoggerInterface $logger,
	) {
	}

	public function collect(): OsStatus {
		$now = $this->timeFactory->getTime();

		if ($this->appConfig->getAppValueString('restricted_mode', 'no') === 'yes') {
			return OsStatus::restricted($now);
		}

		$kernel = trim($this->host->uname('r'));
		$architecture = trim($this->host->uname('m'));
		if ($this->host->osFamily() !== 'Linux') {
			return OsStatus::unsupported($now, $kernel, $architecture);
		}

		$diagnostics = [];
		if (!$this->host->canRunCommands()) {
			$diagnostics[] = 'Commands cannot be run because proc_open() is disabled in the PHP configuration; only files are read.';
		}
		if ($this->host->openBasedir() !== []) {
			$diagnostics[] = 'open_basedir is set, so system files outside the allowed directories cannot be read.';
		}

		$release = OsRelease::parse($this->host->readFile('/etc/os-release', 65536) ?? $this->host->readFile('/usr/lib/os-release', 65536) ?? '');
		if ($release === null) {
			$diagnostics[] = '/etc/os-release could not be read, so the distribution is unknown.';
		}

		$environment = $this->probe('The environment', fn (): Environment => $this->environmentDetector->detect(), new Environment(Environment::KIND_HOST), $diagnostics);
		$uptime = $this->probe('The boot time', fn (): int => $this->os->getUptime(), -1, $diagnostics);
		$bootTime = $uptime >= 0 ? $now - $uptime : null;

		$helperPath = $this->appConfig->getAppValueString(ConfigLexicon::OS_HELPER_REPORT_PATH, HelperReportReader::DEFAULT_PATH);
		[$helperState, $report] = $this->helperReader->read($helperPath, $bootTime, $now);

		$support = $release !== null
			? $this->probe('The support period', fn (): SupportStatus => $this->supportTable->lookup($release), SupportStatus::unknown(), $diagnostics)
			: SupportStatus::unknown();
		if ($support->extended !== null) {
			$extended = $support->extended;
			$support = $support->withExtendedActive($this->probe('The extended support subscription', fn (): ?bool => $this->subscriptions->isActive($extended), null, $diagnostics) ?? $report?->subscriptionActive);
		}

		$packageManager = null;
		if ($release !== null && $environment->managesPackages()) {
			$packageManager = $this->probe('The package manager', fn (): ?IPackageManager => $this->packageManagers->forRelease($release), null, $diagnostics);
			if ($packageManager === null) {
				$diagnostics[] = sprintf('No supported package manager was found for %s.', $release->prettyName);
			}
		}

		$updates = null;
		$updatesSource = null;
		$needsRoot = null;
		if ($packageManager !== null) {
			try {
				$updates = $packageManager->getPendingUpdates();
				$updatesSource = OsStatus::SOURCE_SYSTEM;
			} catch (NeedsRootException $e) {
				$needsRoot = $e->getMessage();
			} catch (ProbeException $e) {
				$diagnostics[] = $e->getMessage();
			} catch (\Throwable $e) {
				$this->logger->warning('Pending updates could not be determined', ['exception' => $e]);
				$diagnostics[] = 'Pending updates could not be determined; see the Nextcloud log.';
			}
		}
		$reportedUpdates = $report?->pendingUpdates;
		if ($updates === null && $reportedUpdates !== null && $environment->managesPackages()) {
			$updates = $reportedUpdates;
			$updatesSource = OsStatus::SOURCE_HELPER;
		}
		if ($needsRoot !== null && $updatesSource !== OsStatus::SOURCE_HELPER) {
			$diagnostics[] = $needsRoot;
		}

		$listsRefreshedAt = $this->latest(
			$packageManager !== null ? $this->probe('The package list age', fn (): ?int => $packageManager->getListsRefreshedAt(), null, $diagnostics) : null,
			$report?->listsRefreshedAt,
		);
		// Without package lists the package manager knows of no newer versions at all.
		if ($updates === [] && $updatesSource === OsStatus::SOURCE_SYSTEM && $listsRefreshedAt === null && in_array($packageManager?->getId(), OsVerdict::LISTS_AGE_MATTERS, true)) {
			$updates = null;
			$updatesSource = null;
			$diagnostics[] = 'The package lists could not be found, so pending updates are unknown until the lists are refreshed.';
		}

		$rebootReasons = $environment->managesPackages()
			? $this->probe('Whether a reboot is needed', fn (): array => $this->rebootReasons($packageManager, $environment, $bootTime, $report), null, $diagnostics)
			: null;
		$restartServices = $environment->managesPackages() ? $report?->restartServices : null;

		$classified = $packageManager?->classifiesSecurity() ?? ($updatesSource === OsStatus::SOURCE_HELPER);
		[$updates, $rebootReasons, $restartServices] = $this->probe(
			'How long the findings have been pending',
			fn (): array => $this->applyFirstSeen($updates, $classified, $rebootReasons, $restartServices, $now),
			[$updates, $rebootReasons, $restartServices],
			$diagnostics,
		);

		$omitted = 0;
		if ($updates !== null) {
			$updates = self::sortUpdates($updates);
			$omitted = max(0, count($updates) - self::MAX_LISTED_UPDATES);
			$updates = array_slice($updates, 0, self::MAX_LISTED_UPDATES);
		}

		if ($packageManager instanceof IReportsDiagnostics) {
			array_push($diagnostics, ...$packageManager->getDiagnostics());
		}

		$timeSync = $this->probe('The time synchronisation', fn (): array => $this->timeSync->probe(), ['synchronized' => null, 'source' => null], $diagnostics);

		return new OsStatus(
			OsStatus::STATE_OK,
			$now,
			$release,
			$kernel,
			$architecture,
			$environment,
			$support,
			$packageManager?->getId(),
			$updates,
			$updatesSource,
			$classified,
			$omitted,
			$packageManager !== null ? $this->probe('The held packages', fn (): ?array => $packageManager->getHeldPackages(), null, $diagnostics) : null,
			$listsRefreshedAt,
			$packageManager !== null ? $this->probe('The last upgrade', fn (): ?int => $packageManager->getLastUpgradeAt(), null, $diagnostics) : null,
			$packageManager !== null
				? $this->probe('The automatic updates', fn (): AutomaticUpdates => $packageManager->getAutomaticUpdates(), AutomaticUpdates::unknown(), $diagnostics)
				: AutomaticUpdates::unknown(),
			$rebootReasons,
			$restartServices,
			$this->probe('The live kernel patches', fn (): ?array => $this->security->getLivePatches(), null, $diagnostics),
			$this->probe('The failed system services', fn (): ?array => $this->systemd->getFailedUnits(), null, $diagnostics),
			$timeSync['synchronized'],
			$timeSync['source'],
			$this->probe('The SELinux state', fn (): ?string => $this->security->getSelinux(), null, $diagnostics),
			$this->probe('The AppArmor state', fn (): ?string => $this->security->getApparmor(), null, $diagnostics),
			$packageManager?->getUpgradeCommands() ?? ['all' => null, 'security' => null],
			$packageManager?->getPackageDatabasePath(),
			$helperState,
			$bootTime,
			$diagnostics,
		);
	}

	/**
	 * Runs one probe, so that a probe that throws costs its own finding and
	 * not the whole run.
	 *
	 * @template T
	 * @param string $finding what the probe finds out, to start a sentence with
	 * @param callable(): T $probe
	 * @param T $fallback the unknown answer
	 * @param list<string> $diagnostics
	 * @param-out list<string> $diagnostics
	 * @return T
	 */
	private function probe(string $finding, callable $probe, mixed $fallback, array &$diagnostics): mixed {
		try {
			return $probe();
		} catch (\Throwable $e) {
			$this->logger->warning($finding . ' could not be determined', ['exception' => $e]);
			$diagnostics[] = sprintf('%s could not be determined; see the Nextcloud log.', $finding);

			return $fallback;
		}
	}

	/**
	 * Collects and stores the result, logging rather than throwing so a
	 * failure does not take the background job queue down with it.
	 */
	public function collectAndStore(): ?OsStatus {
		try {
			$status = $this->collect();
			$this->store->save($status);

			return $status;
		} catch (\Throwable $e) {
			$this->logger->error('The operating system status could not be collected', ['exception' => $e]);

			return null;
		}
	}

	/**
	 * @return list<RebootReason>
	 */
	private function rebootReasons(?IPackageManager $packageManager, Environment $environment, ?int $bootTime, ?HelperReport $report): array {
		$reasons = [];
		$add = static function (RebootReason $reason) use (&$reasons, $environment): void {
			if (!$environment->ownsKernel() && in_array($reason->kind, [RebootReason::KIND_KERNEL, RebootReason::KIND_KERNEL_REMOVED, RebootReason::KIND_MICROCODE], true)) {
				return;
			}
			$reasons[$reason->key()] ??= $reason;
		};

		foreach ($packageManager?->getRebootReasons() ?? [] as $reason) {
			$add($reason);
		}
		foreach ($this->kernelProbe->getRebootReasons($bootTime) as $reason) {
			$add($reason);
		}
		foreach ($report?->rebootReasons ?? [] as $reason) {
			$add($reason);
		}

		$hasDistributionAnswer = ($packageManager?->hasOwnRebootCheck() ?? false)
			|| array_filter($reasons, static fn (RebootReason $r): bool => $r->kind === RebootReason::KIND_DISTRIBUTION) !== [];
		// A system container may see the host's uptime, which says nothing about when its own processes started.
		if (!$hasDistributionAnswer && $bootTime !== null && $environment->kind !== Environment::KIND_SYSTEM_CONTAINER) {
			foreach ($this->coreLibraryProbe->getRebootReasons($bootTime, $packageManager?->getPackageDatabasePath()) as $reason) {
				$add($reason);
			}
		}

		return array_values($reasons);
	}

	/**
	 * Stamps every finding with when it was first seen, which the thresholds
	 * count from.
	 *
	 * @param list<PendingUpdate>|null $updates
	 * @param list<RebootReason>|null $reasons
	 * @param list<ServiceRestart>|null $services
	 * @return array{0: list<PendingUpdate>|null, 1: list<RebootReason>|null, 2: list<ServiceRestart>|null}
	 */
	private function applyFirstSeen(?array $updates, bool $classified, ?array $reasons, ?array $services, int $now): array {
		$current = [];
		$scope = [];

		if ($updates !== null) {
			$scope[] = 'update';
			foreach ($updates as $update) {
				if ($update->security || !$classified) {
					$current['update:' . $update->key()] = null;
				}
			}
		}
		if ($reasons !== null) {
			$scope[] = 'reboot';
			foreach ($reasons as $reason) {
				$current['reboot:' . $reason->key()] = $reason->since;
			}
		}
		if ($services !== null) {
			$scope[] = 'restart';
			foreach ($services as $service) {
				$current['restart:' . $service->unit] = null;
			}
		}

		if ($scope === []) {
			return [$updates, $reasons, $services];
		}

		$seen = $this->store->firstSeen($current, $now, $scope);

		return [
			$updates === null ? null : array_map(
				static fn (PendingUpdate $u): PendingUpdate => $u->withSince($seen['update:' . $u->key()] ?? null),
				$updates,
			),
			$reasons === null ? null : array_map(
				static fn (RebootReason $r): RebootReason => $r->withSince($seen['reboot:' . $r->key()] ?? $r->since),
				$reasons,
			),
			$services === null ? null : array_map(
				static fn (ServiceRestart $s): ServiceRestart => $s->withSince($seen['restart:' . $s->unit] ?? null),
				$services,
			),
		];
	}

	/**
	 * Security updates first, then the packages Nextcloud depends on, then by name.
	 *
	 * @param list<PendingUpdate> $updates
	 * @return list<PendingUpdate>
	 */
	private static function sortUpdates(array $updates): array {
		usort($updates, static fn (PendingUpdate $a, PendingUpdate $b): int => [!$a->security, !$a->isStack(), $a->name]
			<=> [!$b->security, !$b->isStack(), $b->name]);

		return $updates;
	}

	private function latest(?int ...$times): ?int {
		$times = array_filter($times, 'is_int');

		return $times === [] ? null : max($times);
	}
}
