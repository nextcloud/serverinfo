<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Notifications\OsNotificationSender;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\OsStatusStore;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\Verdict;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class NotificationsTest extends \Test\TestCase {
	private OsStatusService&MockObject $service;
	private IManager&MockObject $manager;
	private bool $enabled = true;
	/** @var array<string, string> */
	private array $notified = [];
	/** @var list<string> "notify:id:level:user" and "withdraw:id" */
	private array $events = [];
	private OsNotificationSender $sender;

	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(OsStatusService::class);
		$store = $this->createMock(OsStatusStore::class);
		$store->method('notifiedLevels')->willReturnCallback(fn (): array => $this->notified);
		$store->method('setNotifiedLevels')->willReturnCallback(function (array $levels): void {
			$this->notified = $levels;
		});

		$this->manager = $this->createMock(IManager::class);
		$this->manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$this->manager->method('notify')->willReturnCallback(function (INotification $n): void {
			$this->events[] = 'notify:' . $n->getObjectId() . ':' . $n->getSubjectParameters()['level'] . ':' . $n->getUser();
		});
		$this->manager->method('markProcessed')->willReturnCallback(function (INotification $n): void {
			$this->events[] = 'withdraw:' . $n->getObjectId();
		});

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$admin->method('isEnabled')->willReturn(true);
		$disabled = $this->createMock(IUser::class);
		$disabled->method('getUID')->willReturn('former');
		$disabled->method('isEnabled')->willReturn(false);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$admin, $disabled]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getAppValueBool')->willReturnCallback(fn (): bool => $this->enabled);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('@1792411200'));

		$this->sender = new OsNotificationSender($this->service, $store, $this->manager, $groups, $config, $time, $this->createMock(LoggerInterface::class));
	}

	/**
	 * A notification that remembers what it was given.
	 */
	private function notification(): INotification {
		$values = [];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnCallback(function (...$args) use (&$values, $setter, $notification) {
				$values[$setter] = $args;

				return $notification;
			});
		}
		// Closures by reference: arrow functions would see $values as it was here.
		$notification->method('getObjectId')->willReturnCallback(static function () use (&$values): string {
			return $values['setObject'][1] ?? '';
		});
		$notification->method('getUser')->willReturnCallback(static function () use (&$values): string {
			return $values['setUser'][0] ?? '';
		});
		$notification->method('getSubjectParameters')->willReturnCallback(static function () use (&$values): array {
			return $values['setSubject'][1] ?? [];
		});

		return $notification;
	}

	private function verdict(string $updates, string $reboot = Concern::LEVEL_OK): void {
		$this->service->method('evaluate')->willReturn(new Verdict([
			new Concern(Concern::UPDATES, $updates),
			new Concern(Concern::REBOOT, $reboot),
			new Concern(Concern::TIME_SYNC, Concern::LEVEL_WARNING),
		]));
	}

	public function testAWarningNotifiesEnabledAdminsOnce(): void {
		$this->verdict(Concern::LEVEL_WARNING);

		$this->sender->sync();
		$this->sender->sync();

		$this->assertSame(['withdraw:updates', 'notify:updates:warning:admin'], $this->events);
		$this->assertSame(['updates' => 'warning'], $this->notified);
	}

	public function testOnlyNotifiableConcernsNotify(): void {
		$this->verdict(Concern::LEVEL_OK);

		$this->sender->sync();

		$this->assertSame([], $this->events, 'Time sync is shown, but not worth a notification');
	}

	public function testEscalationNotifiesAgain(): void {
		$this->notified = ['updates' => 'warning'];
		$this->verdict(Concern::LEVEL_ERROR);

		$this->sender->sync();

		$this->assertSame(['withdraw:updates', 'notify:updates:error:admin'], $this->events);
	}

	public function testDeEscalationIsRememberedSilently(): void {
		$this->notified = ['updates' => 'error'];
		$this->verdict(Concern::LEVEL_WARNING);

		$this->sender->sync();

		$this->assertSame([], $this->events);
		$this->assertSame(['updates' => 'warning'], $this->notified);
	}

	public function testResolvedConcernsAreWithdrawn(): void {
		$this->notified = ['updates' => 'warning', 'reboot' => 'error'];
		$this->verdict(Concern::LEVEL_INFO, Concern::LEVEL_OK);

		$this->sender->sync();

		$this->assertSame(['withdraw:updates', 'withdraw:reboot'], $this->events);
		$this->assertSame([], $this->notified);
	}

	public function testUnknownIsNotResolved(): void {
		$this->notified = ['updates' => 'warning'];
		$this->verdict(Concern::LEVEL_UNKNOWN);

		$this->sender->sync();

		$this->assertSame([], $this->events);
		$this->assertSame(['updates' => 'warning'], $this->notified);
	}

	public function testTurningNotificationsOffWithdrawsThem(): void {
		$this->notified = ['updates' => 'warning'];
		$this->enabled = false;

		$this->sender->sync();

		$this->assertSame(['withdraw:updates'], $this->events);
	}
}
