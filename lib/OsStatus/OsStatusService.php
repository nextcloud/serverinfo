<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

use OCA\ServerInfo\Jobs\UpdateOsStatus;
use OCA\ServerInfo\OsStatus\Host\IHost;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessProbe;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessSample;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\OsVerdict;
use OCA\ServerInfo\OsStatus\Verdict\Thresholds;
use OCA\ServerInfo\OsStatus\Verdict\Verdict;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig as IServerConfig;

/**
 * Reads the stored status and judges it. Everything that shows the
 * operating system status goes through here, and nothing here runs a command.
 *
 * @psalm-api
 */
class OsStatusService {
	private bool $sampled = false;
	private ?OsStatus $evaluated = null;
	private ?Verdict $verdictOfEvaluated = null;

	public function __construct(
		private OsStatusStore $store,
		private OsVerdict $verdict,
		private PhpProcessProbe $phpProcessProbe,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private IHost $host,
		private IServerConfig $serverConfig,
	) {
	}

	/**
	 * Whether the operating system of this server is checked at all: not in
	 * restricted mode, and only on Linux. Reads no stored status.
	 */
	public function isApplicable(): bool {
		return $this->appConfig->getAppValueString('restricted_mode', 'no') !== 'yes'
			&& $this->host->osFamily() === 'Linux';
	}

	public function getStatus(): ?OsStatus {
		return $this->store->load();
	}

	/**
	 * Looks at the PHP process serving this request and remembers what it
	 * found. Cheap: one read of /proc/self/maps, at most once per request.
	 * Does nothing where the system is not checked at all.
	 */
	public function samplePhpProcess(): void {
		if ($this->sampled || !$this->isApplicable() || !$this->phpProcessProbe->canSample()) {
			return;
		}
		$this->sampled = true;

		$sample = $this->phpProcessProbe->sample($this->store->load()?->release, $this->timeFactory->getTime());
		if ($sample !== null) {
			$this->store->savePhpSample($sample);
			$this->evaluated = null;
		}
	}

	public function getPhpSample(): ?PhpProcessSample {
		return $this->store->loadPhpSample();
	}

	public function getThresholds(): Thresholds {
		return Thresholds::load($this->appConfig);
	}

	/**
	 * Judges the given status, or the stored one. The verdict of the same
	 * status is reused, because one page load asks for it several times.
	 */
	public function evaluate(?OsStatus $status = null): ?Verdict {
		$status ??= $this->store->load();
		if ($status === null) {
			return null;
		}
		if ($status === $this->evaluated) {
			return $this->verdictOfEvaluated;
		}

		$this->verdictOfEvaluated = $this->verdict->evaluate(
			$status,
			$this->store->loadPhpSample(),
			$this->store->phpOutdatedSince(),
			$this->getThresholds(),
			$this->timeFactory->getTime(),
			UpdateOsStatus::runsFromSystemCron($this->serverConfig),
			UpdateOsStatus::interval($this->serverConfig),
		);
		$this->evaluated = $status;

		return $this->verdictOfEvaluated;
	}

	/**
	 * Whether packages were installed or removed after the status was
	 * collected, so its pending updates may already be installed.
	 */
	public function isOutdated(OsStatus $status): bool {
		if ($status->packageDatabase === null) {
			return false;
		}

		$changedAt = $this->host->modifiedAt($status->packageDatabase);

		return $changedAt !== null && $changedAt > $status->collectedAt;
	}

	/**
	 * Counts and flags for the monitoring API. Deliberately no package names,
	 * package or kernel versions, or advisories: a leaked monitoring token must
	 * not become a list of unpatched software. Unknown values are null, never 0
	 * or false, so a monitoring system cannot mistake "unknown" for "healthy".
	 */
	public function monitoringSummary(): array {
		$status = $this->getStatus();
		if ($status === null) {
			return ['state' => 'pending', 'level' => Concern::LEVEL_UNKNOWN];
		}
		if ($status->state !== OsStatus::STATE_OK) {
			return ['state' => $status->state, 'level' => Concern::LEVEL_UNKNOWN, 'collectedAt' => $status->collectedAt];
		}

		$now = $this->timeFactory->getTime();
		$verdict = $this->evaluate($status);
		$facts = static fn (string $id, string $fact): mixed => $verdict?->get($id)?->facts[$fact] ?? null;
		$php = $this->getPhpSample();

		return [
			'state' => $status->state,
			'level' => $verdict?->level() ?? Concern::LEVEL_UNKNOWN,
			'collectedAt' => $status->collectedAt,
			'outdated' => $this->isOutdated($status),
			'name' => $status->release?->prettyName,
			'environment' => $status->environment->kind,
			'container' => $status->environment->container,
			'updates' => [
				'classified' => $status->securityClassified,
				'security' => $status->updates === null || !$status->securityClassified ? null : count($status->securityUpdates() ?? []),
				'total' => $status->updates === null ? null : count($status->updates) + $status->updatesOmitted,
				'pendingDays' => $facts(Concern::UPDATES, 'days'),
				'held' => $status->held === null ? null : count($status->held),
			],
			'rebootRequired' => $status->rebootReasons === null ? null : $status->rebootReasons !== [],
			'rebootPendingDays' => $facts(Concern::REBOOT, 'days'),
			'phpRestartRequired' => $php?->isOutdated(),
			'servicesNeedRestart' => $status->restartServices === null ? null : count($status->restartServices),
			'support' => [
				'state' => $status->support->state($now),
				'ends' => $status->support->effectiveEnd(),
				'daysLeft' => $status->support->daysLeft($now),
			],
			'automaticUpdates' => $status->automaticUpdates->state,
			'listsAgeHours' => $status->listsRefreshedAt === null ? null : intdiv(max(0, $now - $status->listsRefreshedAt), 3600),
			'timeSynchronized' => $status->timeSynchronized,
			'failedUnits' => $status->failedUnits === null ? null : count($status->failedUnits),
			'helper' => $status->helper->state,
		];
	}
}
