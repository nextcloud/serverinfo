<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Verdict;

use OCA\ServerInfo\OsStatus\NextcloudStack;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\Packages\AutomaticUpdates;
use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessSample;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;

/**
 * Turns a collected status into levels.
 *
 * The page, the setup checks, the notifications, the monitoring API and the
 * dashboard widget all ask this class, so they never disagree.
 */
class OsVerdict {
	/** Package managers whose metadata is refreshed in place and can go stale */
	public const LISTS_AGE_MATTERS = ['apt', 'dnf', 'zypper', 'apk'];

	private const DAY = 86400;

	public function evaluate(OsStatus $status, ?PhpProcessSample $php, ?int $phpOutdatedSince, Thresholds $thresholds, int $now): Verdict {
		if ($status->state !== OsStatus::STATE_OK) {
			return new Verdict([]);
		}

		$managed = $status->environment->managesPackages();
		$concerns = [];

		if ($managed) {
			$concerns[] = $this->updates($status, $thresholds, $now);
			$concerns[] = $this->reboot($status, $thresholds, $now);
		}
		$concerns[] = $this->restart($php, $phpOutdatedSince, $thresholds, $now);
		$concerns[] = $this->support($status, $thresholds, $now);
		if ($managed) {
			$concerns[] = $this->packageLists($status, $thresholds, $now);
			$concerns[] = $this->automaticUpdates($status);
		}
		$concerns[] = $this->failedUnits($status);
		$concerns[] = $this->timeSync($status);

		return new Verdict($concerns);
	}

	private function updates(OsStatus $status, Thresholds $thresholds, int $now): Concern {
		if ($status->updates === null) {
			return new Concern(Concern::UPDATES, Concern::LEVEL_UNKNOWN);
		}

		$security = array_values(array_filter($status->updates, static fn (PendingUpdate $u): bool => $u->security));
		$relevant = $status->securityClassified ? $security : $status->updates;
		$facts = [
			'security' => count($security),
			'other' => count($status->updates) - count($security) + $status->updatesOmitted,
			'classified' => $status->securityClassified,
			'stack' => count(array_filter($relevant, static fn (PendingUpdate $u): bool => $u->isStack())),
			'days' => null,
			// The package manager only knows the updates its lists on disk tell it about.
			'listsStale' => in_array($status->packageManager, self::LISTS_AGE_MATTERS, true)
				&& $status->listsRefreshedAt !== null
				&& self::days($now - $status->listsRefreshedAt) >= $thresholds->listsWarning,
		];

		if ($relevant === []) {
			return new Concern(Concern::UPDATES, Concern::LEVEL_OK, $facts);
		}

		$since = min(array_map(static fn (PendingUpdate $u): int => $u->since ?? $now, $relevant));
		$days = self::days($now - $since);
		$facts['days'] = $days;

		return new Concern(Concern::UPDATES, self::escalate($days, $thresholds->securityWarning, $thresholds->securityError), $facts);
	}

	private function reboot(OsStatus $status, Thresholds $thresholds, int $now): Concern {
		if ($status->rebootReasons === null) {
			return new Concern(Concern::REBOOT, Concern::LEVEL_UNKNOWN);
		}
		if ($status->rebootReasons === []) {
			return new Concern(Concern::REBOOT, Concern::LEVEL_OK);
		}

		$since = min(array_map(static fn (RebootReason $r): int => $r->since ?? $now, $status->rebootReasons));
		$days = self::days($now - $since);
		$level = self::escalate($days, $thresholds->rebootWarning, $thresholds->rebootError);

		$kernelOnly = array_filter($status->rebootReasons, static fn (RebootReason $r): bool => !$r->isKernelOnly()) === [];
		$livePatched = $kernelOnly && ($status->livePatches ?? []) !== [];
		if ($livePatched) {
			$level = Concern::lower($level);
		}

		return new Concern(Concern::REBOOT, $level, [
			'days' => $days,
			'kinds' => array_values(array_unique(array_map(static fn (RebootReason $r): string => $r->kind, $status->rebootReasons))),
			'livePatched' => $livePatched,
		]);
	}

	private function restart(?PhpProcessSample $php, ?int $phpOutdatedSince, Thresholds $thresholds, int $now): Concern {
		if ($php === null) {
			return new Concern(Concern::RESTART, Concern::LEVEL_UNKNOWN);
		}
		if (!$php->isOutdated()) {
			return new Concern(Concern::RESTART, Concern::LEVEL_OK, ['php' => false, 'days' => null]);
		}

		$days = self::days($now - ($phpOutdatedSince ?? $now));

		return new Concern(Concern::RESTART, $days >= $thresholds->restartWarning ? Concern::LEVEL_WARNING : Concern::LEVEL_INFO, [
			'php' => true,
			'days' => $days,
		]);
	}

	private function support(OsStatus $status, Thresholds $thresholds, int $now): Concern {
		$support = $status->support;
		$state = $support->state($now);
		$daysLeft = $support->daysLeft($now);
		$facts = [
			'state' => $state,
			'ends' => $support->effectiveEnd(),
			'daysLeft' => $daysLeft,
			'extended' => $support->extended,
			'extendedEnds' => $support->extendedEnds,
		];

		$level = match ($state) {
			SupportStatus::STATE_ROLLING => Concern::LEVEL_OK,
			SupportStatus::STATE_UNKNOWN => Concern::LEVEL_UNKNOWN,
			SupportStatus::STATE_ENDED => Concern::LEVEL_ERROR,
			SupportStatus::STATE_EXTENDED_AVAILABLE => Concern::LEVEL_WARNING,
			default => $daysLeft !== null && $daysLeft <= $thresholds->eolWarning ? Concern::LEVEL_WARNING : Concern::LEVEL_OK,
		};

		return new Concern(Concern::SUPPORT, $level, $facts);
	}

	private function packageLists(OsStatus $status, Thresholds $thresholds, int $now): Concern {
		if (!in_array($status->packageManager, self::LISTS_AGE_MATTERS, true)) {
			return new Concern(Concern::PACKAGE_LISTS, Concern::LEVEL_OK, ['days' => null]);
		}
		if ($status->listsRefreshedAt === null) {
			return new Concern(Concern::PACKAGE_LISTS, Concern::LEVEL_UNKNOWN);
		}

		$days = self::days($now - $status->listsRefreshedAt);
		$level = self::escalate($days, $thresholds->listsWarning, $thresholds->listsError);

		// Below the warning threshold a refresh is simply routine.
		return new Concern(Concern::PACKAGE_LISTS, $level === Concern::LEVEL_INFO ? Concern::LEVEL_OK : $level, ['days' => $days]);
	}

	private function automaticUpdates(OsStatus $status): Concern {
		$automatic = $status->automaticUpdates;
		$level = match ($automatic->state) {
			AutomaticUpdates::STATE_ENABLED => Concern::LEVEL_OK,
			AutomaticUpdates::STATE_DISABLED => Concern::LEVEL_INFO,
			default => Concern::LEVEL_UNKNOWN,
		};

		return new Concern(Concern::AUTOMATIC_UPDATES, $level, [
			'mechanism' => $automatic->mechanism,
			'scope' => $automatic->scope,
		]);
	}

	private function failedUnits(OsStatus $status): Concern {
		if ($status->failedUnits === null) {
			return new Concern(Concern::FAILED_UNITS, Concern::LEVEL_UNKNOWN);
		}

		$stack = array_values(array_filter($status->failedUnits, static fn (string $unit): bool => NextcloudStack::isStackService($unit)));
		$level = match (true) {
			$stack !== [] => Concern::LEVEL_WARNING,
			$status->failedUnits !== [] => Concern::LEVEL_INFO,
			default => Concern::LEVEL_OK,
		};

		return new Concern(Concern::FAILED_UNITS, $level, [
			'units' => array_slice($status->failedUnits, 0, 20),
			'stack' => $stack,
		]);
	}

	private function timeSync(OsStatus $status): Concern {
		$level = match ($status->timeSynchronized) {
			true => Concern::LEVEL_OK,
			false => Concern::LEVEL_WARNING,
			null => Concern::LEVEL_UNKNOWN,
		};

		return new Concern(Concern::TIME_SYNC, $level, ['source' => $status->timeSource]);
	}

	/**
	 * Applies a pair of thresholds; below the warning threshold a finding is
	 * worth showing but not worth an alert.
	 *
	 * @return Concern::LEVEL_INFO|Concern::LEVEL_WARNING|Concern::LEVEL_ERROR
	 */
	private static function escalate(int $days, int $warning, int $error): string {
		return match (true) {
			$days >= $error => Concern::LEVEL_ERROR,
			$days >= $warning => Concern::LEVEL_WARNING,
			default => Concern::LEVEL_INFO,
		};
	}

	private static function days(int $seconds): int {
		return max(0, intdiv($seconds, self::DAY));
	}
}
