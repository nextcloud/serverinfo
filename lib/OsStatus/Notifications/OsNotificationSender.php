<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Notifications;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\OsStatusStore;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Tells admins when an operating system concern gets worse.
 *
 * Each concern notifies once per escalation (warning, then error), not on
 * every run, and its notification is withdrawn once it is resolved.
 *
 * @psalm-api
 */
class OsNotificationSender {
	public const OBJECT_TYPE = 'os';

	public function __construct(
		private OsStatusService $service,
		private OsStatusStore $store,
		private IManager $notificationManager,
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	public function sync(): void {
		$enabled = $this->appConfig->getAppValueBool(ConfigLexicon::OS_NOTIFICATIONS, true);
		$verdict = $enabled ? $this->service->evaluate() : null;
		$notified = $this->store->notifiedLevels();
		$changed = false;

		foreach (Concern::NOTIFIABLE as $id) {
			$concern = $verdict?->get($id);
			$previous = $notified[$id] ?? null;

			if ($concern === null || !$concern->isProblem()) {
				// Unknown is not resolved: a probe failing once must not
				// withdraw a standing warning.
				if ($previous !== null && ($verdict === null || $concern === null || $concern->level !== Concern::LEVEL_UNKNOWN)) {
					$this->withdraw($id);
					unset($notified[$id]);
					$changed = true;
				}
				continue;
			}

			$level = $concern->level;
			if ($previous === null || Concern::rank($level) > Concern::rank($previous)) {
				$this->withdraw($id);
				$this->notifyAdmins($concern);
				$notified[$id] = $level;
				$changed = true;
			} elseif (Concern::rank($level) < Concern::rank($previous)) {
				// Remember the lower level so a later escalation is announced again.
				$notified[$id] = $level;
				$changed = true;
			}
		}

		if ($changed) {
			$this->store->setNotifiedLevels($notified);
		}
	}

	private function notifyAdmins(Concern $concern): void {
		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return;
		}

		$time = $this->timeFactory->getDateTime();
		foreach ($admins->getUsers() as $user) {
			if (!$user->isEnabled()) {
				continue;
			}

			try {
				$notification = $this->notificationManager->createNotification()
					->setApp(Application::APP_ID)
					->setUser($user->getUID())
					->setDateTime($time)
					->setObject(self::OBJECT_TYPE, $concern->id)
					->setSubject($concern->id, ['level' => $concern->level]);
				$this->notificationManager->notify($notification);
			} catch (\Throwable $e) {
				$this->logger->warning('Could not notify an admin about the operating system status', ['exception' => $e]);
			}
		}
	}

	private function withdraw(string $id): void {
		$notification = $this->notificationManager->createNotification()
			->setApp(Application::APP_ID)
			->setObject(self::OBJECT_TYPE, $id);
		$this->notificationManager->markProcessed($notification);
	}

	/**
	 * For the notifier: whether a notification still describes a live concern.
	 */
	public function isCurrent(INotification $notification): bool {
		return isset($this->store->notifiedLevels()[$notification->getObjectId()]);
	}
}
