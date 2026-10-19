<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\Dashboard\ServerHealthWidget;
use OCA\ServerInfo\OsStatus\Notifications\OsNotificationSender;
use OCA\ServerInfo\OsStatus\Notifications\OsNotifier;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\ConcernTexts;
use OCA\ServerInfo\OsStatus\Verdict\Verdict;
use OCP\IDateTimeFormatter;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;

class EntryPointsTest extends \Test\TestCase {
	private OsStatusService&MockObject $service;
	private IL10N&MockObject $l10n;
	private IURLGenerator&MockObject $urlGenerator;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(OsStatusService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
		$this->l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $one : $many));
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/settings/admin/serverinfo');
		$this->urlGenerator->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => "/apps/$app/img/$image");
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.org' . $url);
	}

	private function texts(): ConcernTexts {
		return new ConcernTexts($this->createMock(IDateTimeFormatter::class));
	}

	private function notifier(OsNotificationSender&MockObject $sender): OsNotifier {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($this->l10n);

		return new OsNotifier($factory, $this->urlGenerator, $this->service, $sender, $this->texts());
	}

	private function notification(string $app, string $objectType, string $objectId): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getObjectType')->willReturn($objectType);
		$notification->method('getObjectId')->willReturn($objectId);

		return $notification;
	}

	public function testNotifierIgnoresOtherApps(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->notifier($this->createMock(OsNotificationSender::class))->prepare($this->notification('files', 'os', 'updates'), 'en');
	}

	public function testNotifierDropsResolvedConcerns(): void {
		$sender = $this->createMock(OsNotificationSender::class);
		$sender->method('isCurrent')->willReturn(true);
		$this->service->method('getStatus')->willReturn(new OsStatus(OsStatus::STATE_OK, 1));
		$this->service->method('evaluate')->willReturn(new Verdict([new Concern(Concern::UPDATES, Concern::LEVEL_OK)]));

		$this->expectException(AlreadyProcessedException::class);
		$this->notifier($sender)->prepare($this->notification('serverinfo', 'os', 'updates'), 'en');
	}

	private function widget(bool $admin): ServerHealthWidget {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new ServerHealthWidget($this->l10n, $this->urlGenerator, $session, $groups, $this->service, $this->texts());
	}

	public function testWidgetIsForAdminsOnly(): void {
		$this->service->expects($this->never())->method('getStatus');

		$widget = $this->widget(false);

		$this->assertFalse($widget->isEnabled());
		$this->assertSame([], $widget->getItemsV2('alice')->getItems());
	}

	public function testWidgetListsProblemsFirst(): void {
		$this->service->method('getStatus')->willReturn(new OsStatus(OsStatus::STATE_OK, 1));
		$this->service->method('evaluate')->willReturn(new Verdict([
			new Concern(Concern::TIME_SYNC, Concern::LEVEL_WARNING),
			new Concern(Concern::UPDATES, Concern::LEVEL_ERROR, ['classified' => true, 'security' => 3, 'other' => 0, 'days' => 20]),
			new Concern(Concern::HELPER, Concern::LEVEL_WARNING, ['state' => 'insecure']),
			new Concern(Concern::REBOOT, Concern::LEVEL_OK),
		]));

		$items = $this->widget(true)->getItemsV2('alice')->getItems();

		$this->assertSame(['3 security updates pending', 'The system clock is not synchronized'], array_map(static fn ($item) => $item->getTitle(), $items));
		$this->assertStringEndsWith('status-error.svg', $items[0]->getIconUrl());
	}

	public function testWidgetWhenAllIsWell(): void {
		$this->service->method('getStatus')->willReturn(new OsStatus(OsStatus::STATE_OK, 1));
		$this->service->method('evaluate')->willReturn(new Verdict([new Concern(Concern::UPDATES, Concern::LEVEL_OK)]));

		$items = $this->widget(true)->getItemsV2('alice')->getItems();

		$this->assertSame('The system is up to date', $items[0]->getTitle());
		$this->assertStringEndsWith('status-ok.svg', $items[0]->getIconUrl());
	}
}
