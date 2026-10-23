<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\SetupChecks;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\ConcernTexts;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Shows operating system concerns on Settings → Overview.
 *
 * Reads the stored status only: the Overview is loaded far too often to run
 * the package manager, and the background job keeps the status current.
 *
 * Core runs the setup checks for sub-admins too, so a request by anyone but an
 * admin gets a result that says nothing about the system. occ and cron have no
 * session user and get the full result.
 *
 * @psalm-api
 */
abstract class AbstractOsCheck implements ISetupCheck {
	public function __construct(
		protected IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private OsStatusService $service,
		private ConcernTexts $texts,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
	}

	/**
	 * @return list<string> concern ids this check reports, most important first
	 */
	abstract protected function concernIds(): array;

	#[\Override]
	public function getCategory(): string {
		return 'security';
	}

	#[\Override]
	public function run(): SetupResult {
		$user = $this->userSession->getUser();
		if ($user !== null && !$this->groupManager->isAdmin($user->getUID())) {
			return SetupResult::info($this->l10n->t('Only administrators can see the operating system status.'));
		}

		$this->service->samplePhpProcess();

		$status = $this->service->getStatus();
		if ($status === null) {
			return SetupResult::info(
				$this->l10n->t('The operating system has not been checked yet. It is checked by the background jobs when they run from system cron, or with occ serverinfo:os-status --refresh.'),
			);
		}
		$unavailable = $this->texts->unavailable($status, $this->l10n);
		if ($unavailable !== null) {
			return SetupResult::success($unavailable);
		}

		$verdict = $this->service->evaluate($status);
		$concerns = array_values(array_filter(
			array_map(static fn (string $id): ?Concern => $verdict?->get($id), $this->concernIds()),
			static fn (?Concern $concern): bool => $concern !== null && $concern->level !== Concern::LEVEL_UNKNOWN,
		));
		if ($concerns === []) {
			return SetupResult::info($this->l10n->t('This could not be checked on this system. {link}'), null, $this->linkParameter());
		}

		usort($concerns, static fn (Concern $a, Concern $b): int => Concern::rank($b->level) <=> Concern::rank($a->level));
		$worst = $concerns[0];

		$text = trim($this->texts->title($worst, $status, $this->l10n) . '. ' . $this->texts->detail($worst, $this->l10n));
		$level = $worst->level;

		// Every check judges the stored status, so an old one makes each of them doubtful.
		$freshness = $verdict?->get(Concern::FRESHNESS);
		if ($freshness !== null && $freshness->level === Concern::LEVEL_WARNING) {
			$text .= ' ' . $this->texts->title($freshness, $status, $this->l10n) . '. ' . $this->texts->detail($freshness, $this->l10n);
			if (Concern::rank($level) < Concern::rank(Concern::LEVEL_WARNING)) {
				$level = Concern::LEVEL_WARNING;
			}
		}
		$description = $text . ' {link}';

		return match ($level) {
			Concern::LEVEL_ERROR => SetupResult::error($description, null, $this->linkParameter()),
			Concern::LEVEL_WARNING => SetupResult::warning($description, null, $this->linkParameter()),
			Concern::LEVEL_INFO => SetupResult::info($description, null, $this->linkParameter()),
			default => SetupResult::success($text),
		};
	}

	/**
	 * The Overview only renders a link when it comes as a rich object parameter.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function linkParameter(): array {
		return [
			'link' => [
				'type' => 'highlight',
				'id' => 'serverinfo-os',
				'name' => $this->l10n->t('Show the operating system status…'),
				'link' => $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]) . '#os',
			],
		];
	}
}
