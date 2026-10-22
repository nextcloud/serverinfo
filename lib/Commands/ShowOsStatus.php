<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Commands;

use OC\Core\Command\Base;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @psalm-api
 */
class ShowOsStatus extends Base {
	/** Exit codes of monitoring plugins */
	public const EXIT_OK = 0;
	public const EXIT_WARNING = 1;
	public const EXIT_CRITICAL = 2;
	public const EXIT_UNKNOWN = 3;

	public function __construct(
		private OsStatusCollector $collector,
		private OsStatusService $service,
	) {
		parent::__construct();
	}

	#[\Override]
	public function configure(): void {
		parent::configure();
		$this->setName('serverinfo:os-status')
			->setDescription('Shows pending operating system updates, reboots and other concerns')
			->addOption('refresh', 'r', InputOption::VALUE_NONE, 'Collect the status now instead of showing the stored one');
	}

	public function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('refresh')) {
			if ($this->collector->collectAndStore() === null) {
				$output->writeln('<error>The operating system status could not be collected; see the Nextcloud log.</error>');

				return self::EXIT_UNKNOWN;
			}
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
		$securityUpdates = $status->securityUpdates();

		$this->writeArrayInOutputFormat($input, $output, [
			'level' => $verdict?->level() ?? Concern::LEVEL_UNKNOWN,
			'collectedAt' => date(DATE_ATOM, $status->collectedAt),
			'release' => $status->release?->prettyName ?? 'unknown',
			'kernel' => $status->kernel,
			'environment' => $status->environment->kind,
			'packageManager' => $status->packageManager ?? 'none',
			'securityUpdates' => $securityUpdates === null ? 'unknown' : count($securityUpdates),
			'updates' => $status->updates === null ? 'unknown' : count($status->updates) + $status->updatesOmitted,
			'updatesPendingDays' => is_int($pendingDays) ? $pendingDays : 'none',
			'rebootRequired' => $status->rebootReasons === null ? 'unknown' : ($status->rebootReasons !== [] ? 'yes' : 'no'),
			'concerns' => $concerns,
		]);

		return self::exitCode($verdict?->level());
	}

	/**
	 * @param Concern::LEVEL_*|null $level
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
