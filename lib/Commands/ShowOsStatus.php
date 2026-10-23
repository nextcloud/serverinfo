<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Commands;

use OCA\ServerInfo\OsStatus\Notifications\OsNotificationSender;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCP\Console\Attribute\AsCommand;
use OCP\Console\Attribute\Option;
use OCP\Console\IOutput;

/**
 * @psalm-api
 */
#[AsCommand(
	name: 'serverinfo:os-status',
	description: 'Shows pending operating system updates, reboots and other concerns',
	supportsOutputFormat: true,
)]
class ShowOsStatus {
	/** Exit codes of monitoring plugins */
	public const EXIT_OK = 0;
	public const EXIT_WARNING = 1;
	public const EXIT_CRITICAL = 2;
	public const EXIT_UNKNOWN = 3;

	public function __construct(
		private OsStatusCollector $collector,
		private OsStatusService $service,
		private OsNotificationSender $notifications,
	) {
	}

	/**
	 * @return self::EXIT_* the monitoring plugin exit code, which needs 3 for unknown
	 */
	public function __invoke(
		IOutput $output,
		#[Option(description: 'Collect the status now instead of showing the stored one', shortcut: 'r')]
		bool $refresh = false,
	): int {
		if ($refresh) {
			if ($this->collector->collectAndStore() === null) {
				$output->writeln('<error>The operating system status could not be collected; see the Nextcloud log.</error>');

				return self::EXIT_UNKNOWN;
			}
			$this->notifications->sync();
		}

		$status = $this->service->getStatus();
		if ($status === null) {
			$output->writeln('The operating system has not been checked yet. Run with --refresh to check it now.');

			return self::EXIT_UNKNOWN;
		}

		$verdict = $this->service->evaluate($status);
		$concerns = [];
		foreach ($verdict?->concerns ?? [] as $concern) {
			$concerns[$concern->id] = ['level' => $concern->level] + array_filter(
				$concern->facts,
				static fn (mixed $fact): bool => $fact !== null && $fact !== [],
			);
		}
		$pendingDays = $verdict?->get(Concern::UPDATES)?->facts['days'] ?? null;
		$age = $verdict?->get(Concern::FRESHNESS)?->facts['ageSeconds'] ?? null;
		$securityUpdates = $status->securityUpdates();

		$output->writeArrayInOutputFormat([
			'level' => $verdict?->level() ?? Concern::LEVEL_UNKNOWN,
			'collectedAt' => date(DATE_ATOM, $status->collectedAt),
			'statusAge' => is_int($age) ? self::formatAge($age) : 'unknown',
			'release' => $status->release?->prettyName ?? 'unknown',
			'kernel' => $status->kernel,
			'environment' => $status->environment->kind,
			'packageManager' => $status->packageManager ?? 'none',
			'securityUpdates' => $securityUpdates === null ? 'unknown' : count($securityUpdates),
			'updates' => $status->updates === null ? 'unknown' : count($status->updates) + $status->updatesOmitted,
			'updatesPendingDays' => is_int($pendingDays) ? $pendingDays : 'none',
			'rebootRequired' => $status->rebootReasons === null ? 'unknown' : ($status->rebootReasons !== [] ? 'yes' : 'no'),
			'helper' => $status->helper->state,
			'concerns' => $concerns,
		]);

		return self::exitCode($verdict?->level());
	}

	/**
	 * A duration in its two largest units, e.g. "3 d 4 h" or "12 min".
	 */
	public static function formatAge(int $seconds): string {
		return match (true) {
			$seconds >= 86400 => sprintf('%d d %d h', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600)),
			$seconds >= 3600 => sprintf('%d h %d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
			default => sprintf('%d min', intdiv(max(0, $seconds), 60)),
		};
	}

	/**
	 * @param Concern::LEVEL_*|null $level
	 * @return self::EXIT_*
	 */
	public static function exitCode(?string $level): int {
		return match ($level) {
			Concern::LEVEL_OK, Concern::LEVEL_INFO => self::EXIT_OK,
			Concern::LEVEL_WARNING => self::EXIT_WARNING,
			Concern::LEVEL_ERROR => self::EXIT_CRITICAL,
			default => self::EXIT_UNKNOWN,
		};
	}
}
