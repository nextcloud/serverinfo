<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Notifications;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Verdict\ConcernTexts;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders operating system notifications from the current status, so a
 * notification never shows numbers older than the page does.
 *
 * @psalm-api
 */
class OsNotifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
		private OsStatusService $service,
		private OsNotificationSender $sender,
		private ConcernTexts $texts,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID . '-os';
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Operating system');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getObjectType() !== OsNotificationSender::OBJECT_TYPE) {
			throw new UnknownNotificationException();
		}

		if (!$this->sender->isCurrent($notification)) {
			throw new AlreadyProcessedException();
		}

		$status = $this->service->getStatus();
		$concern = $status !== null ? $this->service->evaluate($status)?->get($notification->getObjectId()) : null;
		if ($status === null || $concern === null || !$concern->isProblem()) {
			throw new AlreadyProcessedException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$link = $this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]) . '#os';

		$notification
			->setParsedSubject($this->texts->title($concern, $status, $l))
			->setLink($link)
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));

		$detail = $this->texts->detail($concern, $l);
		if ($detail !== '') {
			$notification->setParsedMessage($detail);
		}

		$action = $notification->createAction();
		$action->setParsedLabel($l->t('View'))
			->setLink($link, 'WEB')
			->setPrimary(true);
		$notification->addParsedAction($action);

		return $notification;
	}
}
