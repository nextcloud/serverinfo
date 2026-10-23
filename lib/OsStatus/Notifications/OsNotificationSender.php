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
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

/**
 * Tells admins when an operating system concern gets worse.
 *
 * Each concern notifies once per escalation (warning, then error), not on
 * every run, and its notification is withdrawn once it is resolved. Admins
 * who join while a concern is live get it on the next run; admins who
 * dismissed it do not get it again until it escalates.
 *
 * @psalm-api
 */
class OsNotificationSender {
	public const OBJECT_TYPE = 'os';
	/** Held while syncing, so the job and occ serverinfo:os-status --refresh never notify twice */
	private const LOCK = 'serverinfo/os-notifications';

	public function __construct(
		private OsStatusService $service,
		private OsStatusStore $store,
		private IManager $notificationManager,
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
	) {
	}

	public function sync(): void {
		try {
			$this->lockingProvider->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			$this->logger->debug('Operating system notifications are being synced by another process');
			return;
		}

		try {
			$this->syncLocked();
		} finally {
			$this->lockingProvider->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	private function syncLocked(): void {
		$enabled = $this->appConfig->getAppValueBool(ConfigLexicon::OS_NOTIFICATIONS, true);
		$verdict = $enabled ? $this->service->evaluate() : null;
		if ($enabled && $verdict === null) {
			// Without a readable status nothing is known about the notified concerns.
			return;
		}

		$notified = $levelsBefore = $this->store->notifiedLevels();
		$recipients = $usersBefore = $this->store->notifiedUsers();

		foreach (Concern::NOTIFIABLE as $id) {
			$concern = $verdict?->get($id);
			$previous = $notified[$id] ?? null;

			if ($concern === null || !$concern->isProblem()) {
				// Unknown is not resolved: a probe failing once must not
				// withdraw a standing warning.
				if ($previous !== null && ($concern === null || $concern->isResolved()) && $this->withdraw($id)) {
					unset($notified[$id], $recipients[$id]);
				}
				continue;
			}

			$level = $concern->level;
			$escalated = $previous === null || Concern::rank($level) > Concern::rank($previous);
			if ($escalated) {
				foreach ([Concern::LEVEL_WARNING, Concern::LEVEL_ERROR] as $other) {
					if ($other !== $level) {
						$this->withdraw($id, $other);
					}
				}
			}
			$recipients[$id] = $this->notifyAdmins($concern, $escalated ? [] : ($recipients[$id] ?? []));
			// A lower level is remembered too, so a later escalation reaches
			// admins who dismissed the notification in between.
			$notified[$id] = $level;
		}

		if ($notified !== $levelsBefore) {
			$this->store->setNotifiedLevels($notified);
		}
		if ($recipients !== $usersBefore) {
			$this->store->setNotifiedUsers($recipients);
		}
	}

	/**
	 * Notifies every enabled admin who has not been notified of this concern
	 * yet and has no notification of it.
	 *
	 * @param list<string> $notified admins already notified
	 * @return list<string> admins notified now or before
	 */
	private function notifyAdmins(Concern $concern, array $notified): array {
		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return $notified;
		}

		$recipients = [];
		$time = $this->timeFactory->getDateTime();
		foreach ($admins->getUsers() as $user) {
			if (!$user->isEnabled()) {
				continue;
			}

			$uid = $user->getUID();
			try {
				if (!in_array($uid, $notified, true) && !$this->hasNotification($uid, $concern->id)) {
					$notification = $this->notificationManager->createNotification()
						->setApp(Application::APP_ID)
						->setUser($uid)
						->setDateTime($time)
						->setObject(self::OBJECT_TYPE, $concern->id)
						->setSubject($concern->level);
					$this->notificationManager->notify($notification);
				}
				$recipients[] = $uid;
			} catch (\Throwable $e) {
				$this->logger->warning('Could not notify an admin about the operating system status', ['exception' => $e]);
			}
		}

		return $recipients;
	}

	private function hasNotification(string $uid, string $id): bool {
		$notification = $this->notificationManager->createNotification()
			->setApp(Application::APP_ID)
			->setUser($uid)
			->setObject(self::OBJECT_TYPE, $id);

		return $this->notificationManager->getCount($notification) > 0;
	}

	/**
	 * Removes the notifications of a concern, or only those of one level.
	 *
	 * @return bool false when they could not be removed
	 */
	private function withdraw(string $id, ?string $level = null): bool {
		try {
			$notification = $this->notificationManager->createNotification()
				->setApp(Application::APP_ID)
				->setObject(self::OBJECT_TYPE, $id);
			if ($level !== null) {
				$notification->setSubject($level);
			}
			$this->notificationManager->markProcessed($notification);

			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Could not withdraw an operating system notification', ['exception' => $e]);

			return false;
		}
	}

	/**
	 * For the notifier: whether a notification still describes a live concern.
	 */
	public function isCurrent(INotification $notification): bool {
		return isset($this->store->notifiedLevels()[$notification->getObjectId()]);
	}
}
