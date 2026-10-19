<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\Controller\OsController;
use OCA\ServerInfo\Dashboard\ServerHealthWidget;
use OCA\ServerInfo\OsStatus\Notifications\OsNotificationSender;
use OCA\ServerInfo\OsStatus\Notifications\OsNotifier;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\ConcernTexts;
use OCA\ServerInfo\OsStatus\Verdict\Thresholds;
use OCA\ServerInfo\OsStatus\Verdict\Verdict;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeFormatter;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

class EntryPointsTest extends \Test\TestCase {
	private OsStatusService&MockObject $service;
	private IL10N $l10n;
	private IURLGenerator&MockObject $urlGenerator;
	private bool $recipientIsAdmin = true;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(OsStatusService::class);
		$this->service->method('getThresholds')->willReturn(new Thresholds());
		$this->l10n = new FakeL10N();
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.org/settings/admin/serverinfo');
		$this->urlGenerator->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => "/apps/$app/img/$image");
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.org' . $url);
	}

	private function texts(): ConcernTexts {
		return new ConcernTexts($this->createMock(IDateTimeFormatter::class));
	}

	private function controller(IAppConfig&MockObject $config, OsNotificationSender&MockObject $sender): OsController {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn('/var/www/nextcloud/apps/serverinfo');

		return new OsController(
			'serverinfo',
			$this->createMock(IRequest::class),
			$this->service,
			$this->createMock(OsStatusCollector::class),
			$sender,
			$config,
			$appManager,
			$this->createMock(ITimeFactory::class),
		);
	}

	public function testSettingsAreValidatedBeforeAnythingIsStored(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('setAppValueInt');
		$config->expects($this->never())->method('setAppValueBool');
		$sender = $this->createMock(OsNotificationSender::class);
		$sender->expects($this->never())->method('sync');

		$response = $this->controller($config, $sender)->updateSettings(['securityWarning' => 20, 'securityError' => 10], true);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['field' => 'rebootWarning'], $response->getData(), 'The first missing field is named');
	}

	public function testValidSettingsAreStoredAndApplied(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->exactly(8))->method('setAppValueInt');
		$config->expects($this->once())->method('setAppValueBool')->with('os_notifications', false);
		$config->method('getAppValueString')->willReturnArgument(1);
		$sender = $this->createMock(OsNotificationSender::class);
		$sender->expects($this->once())->method('sync');

		$response = $this->controller($config, $sender)->updateSettings((new Thresholds(rebootWarning: 2))->toArray(), false);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('/var/www/nextcloud/apps/serverinfo/bin/serverinfo-os-report', $response->getData()['helper']['scriptPath']);
	}

	public function testStatusSamplesThePhpProcess(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getAppValueString')->willReturnArgument(1);
		$this->service->expects($this->once())->method('samplePhpProcess');

		$data = $this->controller($config, $this->createMock(OsNotificationSender::class))->status()->getData();

		$this->assertNull($data['status']);
		$this->assertSame('/var/lib/serverinfo/os-report.json', $data['helper']['reportPath']);
	}

	private function notifier(OsNotificationSender&MockObject $sender): OsNotifier {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($this->l10n);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => $uid === 'admin' && $this->recipientIsAdmin);

		return new OsNotifier($factory, $this->urlGenerator, $this->service, $sender, $this->texts(), $groups);
	}

	private function notification(string $app, string $objectType, string $objectId): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getObjectType')->willReturn($objectType);
		$notification->method('getObjectId')->willReturn($objectId);
		$notification->method('getUser')->willReturn('admin');

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

	/**
	 * A notification of a live concern that remembers its parsed subject.
	 */
	private function liveNotification(string $objectId, ?string &$subject): INotification&MockObject {
		$notification = $this->notification('serverinfo', 'os', $objectId);
		foreach (['setLink', 'setIcon', 'setParsedMessage', 'addParsedAction'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		$notification->method('setParsedSubject')->willReturnCallback(function (string $text) use (&$subject, $notification): INotification {
			$subject = $text;

			return $notification;
		});
		$action = $this->createMock(IAction::class);
		foreach (['setParsedLabel', 'setLink', 'setPrimary'] as $setter) {
			$action->method($setter)->willReturnSelf();
		}
		$notification->method('createAction')->willReturn($action);

		return $notification;
	}

	private function currentSender(): OsNotificationSender&MockObject {
		$sender = $this->createMock(OsNotificationSender::class);
		$sender->method('isCurrent')->willReturn(true);

		return $sender;
	}

	public function testNotifierRendersALiveConcern(): void {
		$this->service->method('getStatus')->willReturn(new OsStatus(OsStatus::STATE_OK, 1));
		$this->service->method('evaluate')->willReturn(new Verdict([
			new Concern(Concern::UPDATES, Concern::LEVEL_WARNING, ['classified' => true, 'security' => 4, 'other' => 0]),
		]));

		$this->notifier($this->currentSender())->prepare($this->liveNotification('updates', $subject), 'en');

		$this->assertSame('4 security updates pending', $subject);
	}

	public static function dataNotJudgeable(): array {
		return [
			'unknown' => [new OsStatus(OsStatus::STATE_OK, 1), [new Concern(Concern::UPDATES, Concern::LEVEL_UNKNOWN)]],
			'missing from the verdict' => [new OsStatus(OsStatus::STATE_OK, 1), []],
			'no readable status' => [null, []],
		];
	}

	#[DataProvider('dataNotJudgeable')]
	public function testNotifierKeepsConcernsItCannotJudge(?OsStatus $status, array $concerns): void {
		$this->service->method('getStatus')->willReturn($status);
		$this->service->method('evaluate')->willReturn($status === null ? null : new Verdict($concerns));

		$this->notifier($this->currentSender())->prepare($this->liveNotification('updates', $subject), 'en');

		$this->assertSame('The operating system needs attention', $subject);
	}

	public function testNotifierDropsNotificationsTheSenderWithdrew(): void {
		$sender = $this->createMock(OsNotificationSender::class);
		$sender->method('isCurrent')->willReturn(false);

		$this->expectException(AlreadyProcessedException::class);
		$this->notifier($sender)->prepare($this->notification('serverinfo', 'os', 'updates'), 'en');
	}

	public function testNotifierDropsNotificationsOfFormerAdmins(): void {
		$this->recipientIsAdmin = false;
		$this->service->expects($this->never())->method('getStatus');

		$this->expectException(AlreadyProcessedException::class);
		$this->notifier($this->currentSender())->prepare($this->liveNotification('updates', $subject), 'en');
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

	public function testWidgetIsHiddenWhereNothingIsChecked(): void {
		$this->service->method('isApplicable')->willReturn(false);
		$this->service->expects($this->never())->method('getStatus');

		$this->assertFalse($this->widget(true)->isEnabled());
	}

	public function testWidgetIsShownToAdminsOfCheckedSystems(): void {
		$this->service->method('isApplicable')->willReturn(true);

		$this->assertTrue($this->widget(true)->isEnabled());
	}

	public function testWidgetSaysWhyNothingWasChecked(): void {
		$this->service->method('getStatus')->willReturn(OsStatus::restricted(1));

		$items = $this->widget(true)->getItemsV2('alice');

		$this->assertSame([], $items->getItems());
		$this->assertSame('Not checked, because the Monitoring app runs in restricted mode.', $items->getEmptyContentMessage());
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
