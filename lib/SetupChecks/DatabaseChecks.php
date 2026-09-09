<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\SetupChecks;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\Database\Advisor;
use OCA\ServerInfo\Database\CheckSummary;
use OCA\ServerInfo\Database\DatabaseProbe;
use OCA\ServerInfo\Database\RuleSet\Mysql as MysqlRuleSet;
use OCA\ServerInfo\Database\RuleSet\Postgres as PostgresRuleSet;
use OCA\ServerInfo\Database\Snapshot;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Puts failing database checks in front of an admin on Settings → Overview,
 * where they already look for things that are wrong, rather than only on a
 * page they have to know to visit.
 */
class DatabaseChecks implements ISetupCheck {
	/**
	 * How long a recorded result is trusted. Opening the database page records
	 * a fresh one, so in practice this only matters for a server nobody has
	 * looked at in a day.
	 */
	private const MAX_AGE = 86400;

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private CheckSummary $summary,
		private DatabaseProbe $probe,
		private Advisor $advisor,
		private MysqlRuleSet $mysqlRules,
		private PostgresRuleSet $postgresRules,
	) {
	}

	#[\Override]
	public function getCategory(): string {
		return 'database';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Database configuration');
	}

	#[\Override]
	public function run(): SetupResult {
		$summary = $this->summary->latest(self::MAX_AGE) ?? $this->runNow();

		if ($summary === null) {
			return SetupResult::info(
				$this->l10n->t('The database configuration could not be checked.'),
			);
		}

		if (!$summary['supported']) {
			return SetupResult::success(
				$this->l10n->t('These checks only cover MySQL, MariaDB and PostgreSQL.'),
			);
		}

		if ($summary['failing'] === 0) {
			return SetupResult::success(
				$this->l10n->t('All database checks pass.'),
			);
		}

		// The Overview only renders a link when it comes through as a rich object
		// in the parameters; linkToDoc alone is not shown, so {link} it is.
		return SetupResult::warning(
			$this->l10n->n(
				'%n database check is failing. {link}',
				'%n database checks are failing. {link}',
				$summary['failing'],
			),
			null,
			[
				'link' => [
					'type' => 'highlight',
					'id' => 'serverinfo-database-checks',
					'name' => $this->l10n->t('Show the findings…'),
					'link' => $this->databasePageUrl(),
				],
			],
		);
	}

	/**
	 * Runs the checks and records the outcome, so the next page load is cheap.
	 *
	 * @return array{ranAt: int, supported: bool, failing: int, alerts: int, warnings: int, notices: int}|null
	 */
	private function runNow(): ?array {
		try {
			$snapshot = $this->probe->snapshot();
			if (!$snapshot->isSupported()) {
				$this->summary->record(false, []);

				return $this->summary->latest(self::MAX_AGE);
			}

			$ruleSet = $snapshot->flavour === Snapshot::FLAVOUR_PGSQL ? $this->postgresRules : $this->mysqlRules;
			$results = array_map(
				static fn ($result) => $result->jsonSerialize(),
				$this->advisor->run($ruleSet, $snapshot),
			);
			$this->summary->record(true, $results);

			return $this->summary->latest(self::MAX_AGE);
		} catch (\Throwable) {
			// A database that cannot be probed is a problem the other setup
			// checks will report far better than this one can.
			return null;
		}
	}

	private function databasePageUrl(): string {
		return $this->urlGenerator->linkToRoute(
			'settings.AdminSettings.index',
			['section' => Application::APP_ID],
		) . '#database';
	}
}
