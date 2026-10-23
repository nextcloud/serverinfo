<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Dashboard;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\ConcernTexts;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Pending updates, reboots and other operating system concerns on the
 * dashboard of admins.
 *
 * @psalm-api
 */
class ServerHealthWidget implements IAPIWidgetV2, IConditionalWidget, IIconWidget, IButtonWidget, IReloadableWidget {
	/** Concerns that are about the system, not about the optional helper */
	private const SHOWN = [
		Concern::UPDATES, Concern::REBOOT, Concern::RESTART, Concern::SUPPORT,
		Concern::PACKAGE_LISTS, Concern::FAILED_UNITS, Concern::TIME_SYNC, Concern::AUTOMATIC_UPDATES,
	];

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private OsStatusService $service,
		private ConcernTexts $texts,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'serverinfo-os';
	}

	#[\Override]
	public function getTitle(): string {
		return $this->l10n->t('Server health');
	}

	#[\Override]
	public function getOrder(): int {
		return 50;
	}

	#[\Override]
	public function getIconClass(): string {
		return '';
	}

	#[\Override]
	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	#[\Override]
	public function getUrl(): ?string {
		return $this->pageUrl();
	}

	#[\Override]
	public function load(): void {
	}

	#[\Override]
	public function isEnabled(): bool {
		$user = $this->userSession->getUser();

		return $user !== null && $this->groupManager->isAdmin($user->getUID()) && $this->service->isApplicable();
	}

	#[\Override]
	public function getReloadInterval(): int {
		return 600;
	}

	#[\Override]
	public function getWidgetButtons(string $userId): array {
		return [new WidgetButton(WidgetButton::TYPE_MORE, $this->pageUrl(), $this->l10n->t('Open Monitoring'))];
	}

	#[\Override]
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		if (!$this->groupManager->isAdmin($userId)) {
			return new WidgetItems();
		}

		$status = $this->service->getStatus();
		if ($status === null) {
			return new WidgetItems([], $this->l10n->t('The operating system has not been checked yet.'));
		}
		if ($status->state !== OsStatus::STATE_OK) {
			return new WidgetItems([], $this->texts->unavailable($status, $this->l10n) ?? '');
		}

		$verdict = $this->service->evaluate($status);
		$items = [];
		foreach ($verdict?->atLeast(Concern::LEVEL_INFO) ?? [] as $concern) {
			if (!in_array($concern->id, self::SHOWN, true)) {
				continue;
			}
			$items[] = $this->item($concern, $status);
		}

		if ($items === []) {
			$support = $verdict?->get(Concern::SUPPORT);
			$items[] = new WidgetItem(
				$this->l10n->t('The system is up to date'),
				$support !== null && $support->level !== Concern::LEVEL_UNKNOWN ? $this->texts->title($support, $status, $this->l10n) : '',
				$this->pageUrl(),
				$this->icon(Concern::LEVEL_OK),
			);
		}

		return new WidgetItems(array_slice($items, 0, max(1, $limit)));
	}

	private function item(Concern $concern, OsStatus $status): WidgetItem {
		return new WidgetItem(
			$this->texts->title($concern, $status, $this->l10n),
			$this->texts->detail($concern, $this->l10n),
			$this->pageUrl(),
			$this->icon($concern->level),
			$concern->id,
		);
	}

	private function icon(string $level): string {
		$name = match ($level) {
			Concern::LEVEL_ERROR => 'status-error.svg',
			Concern::LEVEL_WARNING => 'status-warning.svg',
			Concern::LEVEL_INFO => 'status-info.svg',
			default => 'status-ok.svg',
		};

		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, $name));
	}

	private function pageUrl(): string {
		return $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]) . '#os';
	}
}
