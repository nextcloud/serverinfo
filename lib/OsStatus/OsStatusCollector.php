<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

use OCA\ServerInfo\Os;
use OCA\ServerInfo\OsStatus\Health\SecurityProbe;
use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Health\TimeSyncProbe;
use OCA\ServerInfo\OsStatus\Host\IHost;
use OCA\ServerInfo\OsStatus\Packages\AutomaticUpdates;
use OCA\ServerInfo\OsStatus\Packages\IPackageManager;
use OCA\ServerInfo\OsStatus\Packages\PackageManagerFactory;
use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;
use OCA\ServerInfo\OsStatus\Packages\ProbeException;
use OCA\ServerInfo\OsStatus\Reboot\CoreLibraryProbe;
use OCA\ServerInfo\OsStatus\Reboot\KernelProbe;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Support\SubscriptionProbe;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;
use OCA\ServerInfo\OsStatus\Support\SupportTable;
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

		$environment = $this->environmentDetector->detect();
		$uptime = $this->os->getUptime();
		$bootTime = $uptime >= 0 ? $now - $uptime : null;

		$support = $release !== null ? $this->supportTable->lookup($release) : SupportStatus::unknown();
		if ($support->extended !== null) {
			$support = $support->withExtendedActive($this->subscriptions->isActive($support->extended));
		}

		$packageManager = null;
		if ($release !== null && $environment->managesPackages()) {
			$packageManager = $this->packageManagers->forRelease($release);
			if ($packageManager === null) {
				$diagnostics[] = sprintf('No supported package manager was found for %s.', $release->prettyName);
			}
		}

		$updates = null;
		$updatesSource = null;
		if ($packageManager !== null) {
			try {
				$updates = $packageManager->getPendingUpdates();
				$updatesSource = OsStatus::SOURCE_SYSTEM;
			} catch (ProbeException $e) {
				$diagnostics[] = $e->getMessage();
			} catch (\Throwable $e) {
				$this->logger->warning('Pending updates could not be determined', ['exception' => $e]);
				$diagnostics[] = 'Pending updates could not be determined; see the Nextcloud log.';
			}
		}

		$rebootReasons = $environment->managesPackages()
			? $this->rebootReasons($packageManager, $environment, $bootTime)
			: null;

		[$updates, $rebootReasons] = $this->applyFirstSeen(
			$updates,
			$packageManager?->classifiesSecurity() ?? false,
			$rebootReasons,
			$now,
		);

		$omitted = 0;
		if ($updates !== null) {
			$updates = self::sortUpdates($updates);
			$omitted = max(0, count($updates) - self::MAX_LISTED_UPDATES);
			$updates = array_slice($updates, 0, self::MAX_LISTED_UPDATES);
		}

		$timeSync = $this->timeSync->probe();

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
			$packageManager?->classifiesSecurity() ?? false,
			$omitted,
			$packageManager?->getHeldPackages(),
			$packageManager?->getListsRefreshedAt(),
			$packageManager?->getLastUpgradeAt(),
			$packageManager?->getAutomaticUpdates() ?? AutomaticUpdates::unknown(),
			$rebootReasons,
			$this->security->getLivePatches(),
			$this->systemd->getFailedUnits(),
			$timeSync['synchronized'],
			$timeSync['source'],
			$this->security->getSelinux(),
			$this->security->getApparmor(),
			$packageManager?->getUpgradeCommands() ?? ['all' => null, 'security' => null],
			$packageManager?->getPackageDatabasePath(),
			$bootTime,
			$diagnostics,
		);
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
	private function rebootReasons(?IPackageManager $packageManager, Environment $environment, ?int $bootTime): array {
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
		foreach ($this->kernelProbe->getRebootReasons() as $reason) {
			$add($reason);
		}

		$hasDistributionAnswer = ($packageManager?->hasOwnRebootCheck() ?? false)
			|| array_filter($reasons, static fn (RebootReason $r): bool => $r->kind === RebootReason::KIND_DISTRIBUTION) !== [];
		if (!$hasDistributionAnswer && $bootTime !== null) {
			foreach ($this->coreLibraryProbe->getRebootReasons($bootTime) as $reason) {
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
	 * @return array{0: list<PendingUpdate>|null, 1: list<RebootReason>|null}
	 */
	private function applyFirstSeen(?array $updates, bool $classified, ?array $reasons, int $now): array {
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
		if ($scope === []) {
			return [$updates, $reasons];
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
}
