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
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class NotificationsTest extends \Test\TestCase {
	private OsStatusService&MockObject $service;
	private IManager&MockObject $manager;
	private ILockingProvider&MockObject $locking;
	private LoggerInterface&MockObject $logger;
	private bool $enabled = true;
	private bool $withdrawFails = false;
	/** @var array<string, string> */
	private array $notified = [];
	/** @var array<string, list<string>> */
	private array $notifiedUsers = [];
	/** @var list<array{user: string, id: string, level: string}> what admins have in their notifications */
	private array $inbox = [];
	/** @var list<string> "notify:id:level:user", "withdraw:id" and "withdraw:id:level" */
	private array $events = [];
	/** @var list<IUser> */
	private array $admins = [];
	private OsNotificationSender $sender;

	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(OsStatusService::class);
		$store = $this->createMock(OsStatusStore::class);
		$store->method('notifiedLevels')->willReturnCallback(fn (): array => $this->notified);
		$store->method('setNotifiedLevels')->willReturnCallback(function (array $levels): void {
			$this->notified = $levels;
		});
		$store->method('notifiedUsers')->willReturnCallback(fn (): array => $this->notifiedUsers);
		$store->method('setNotifiedUsers')->willReturnCallback(function (array $users): void {
			$this->notifiedUsers = $users;
		});

		$this->manager = $this->createMock(IManager::class);
		$this->manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$this->manager->method('notify')->willReturnCallback(function (INotification $n): void {
			$this->events[] = 'notify:' . $n->getObjectId() . ':' . $n->getSubject() . ':' . $n->getUser();
			$this->inbox[] = ['user' => $n->getUser(), 'id' => $n->getObjectId(), 'level' => $n->getSubject()];
		});
		$this->manager->method('markProcessed')->willReturnCallback(function (INotification $n): void {
			if ($this->withdrawFails) {
				throw new \RuntimeException('The database is gone');
			}
			$this->events[] = 'withdraw:' . $n->getObjectId() . ($n->getSubject() !== '' ? ':' . $n->getSubject() : '');
			$this->inbox = array_values(array_filter($this->inbox, static fn (array $held): bool => !self::filterMatches($held, $n)));
		});
		$this->manager->method('getCount')->willReturnCallback(fn (INotification $n): int => count(array_filter($this->inbox, static fn (array $held): bool => self::filterMatches($held, $n))));

		$this->admins = [$this->user('admin', true), $this->user('former', false)];
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturnCallback(fn (): array => $this->admins);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getAppValueBool')->willReturnCallback(fn (): bool => $this->enabled);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('@1792411200'));

		$this->locking = $this->createMock(ILockingProvider::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->sender = new OsNotificationSender($this->service, $store, $this->manager, $groups, $config, $time, $this->locking, $this->logger);
	}

	private function user(string $uid, bool $enabled): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);

		return $user;
	}

	/**
	 * Whether a held notification matches a filter, the way the notifications
	 * app matches: fields left empty match anything.
	 *
	 * @param array{user: string, id: string, level: string} $held
	 */
	private static function filterMatches(array $held, INotification $filter): bool {
		return ($filter->getUser() === '' || $filter->getUser() === $held['user'])
			&& $filter->getObjectId() === $held['id']
			&& ($filter->getSubject() === '' || $filter->getSubject() === $held['level']);
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
		$notification->method('getSubject')->willReturnCallback(static function () use (&$values): string {
			return $values['setSubject'][0] ?? '';
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

		$this->assertSame(['withdraw:updates:error', 'notify:updates:warning:admin'], $this->events);
		$this->assertSame(['updates' => 'warning'], $this->notified);
		$this->assertSame(['updates' => ['admin']], $this->notifiedUsers);
	}

	public function testOnlyNotifiableConcernsNotify(): void {
		$this->verdict(Concern::LEVEL_OK);

		$this->sender->sync();

		$this->assertSame([], $this->events, 'Time sync is shown, but not worth a notification');
	}

	public function testEscalationNotifiesAgain(): void {
		$this->notified = ['updates' => 'warning'];
		$this->notifiedUsers = ['updates' => ['admin']];
		$this->inbox = [['user' => 'admin', 'id' => 'updates', 'level' => 'warning']];
		$this->verdict(Concern::LEVEL_ERROR);

		$this->sender->sync();

		$this->assertSame(['withdraw:updates:warning', 'notify:updates:error:admin'], $this->events);
		$this->assertSame([['user' => 'admin', 'id' => 'updates', 'level' => 'error']], $this->inbox);
	}

	public function testDeEscalationIsRememberedSilently(): void {
		$this->notified = ['updates' => 'error'];
		$this->inbox = [['user' => 'admin', 'id' => 'updates', 'level' => 'error']];
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

	public function testAdminsWhoJoinLaterAreNotified(): void {
		$this->notified = ['updates' => 'warning'];
		$this->notifiedUsers = ['updates' => ['admin']];
		$this->inbox = [['user' => 'admin', 'id' => 'updates', 'level' => 'warning']];
		$this->admins[] = $this->user('newcomer', true);
		$this->verdict(Concern::LEVEL_WARNING);

		$this->sender->sync();

		$this->assertSame(['notify:updates:warning:newcomer'], $this->events);
		$this->assertSame(['updates' => ['admin', 'newcomer']], $this->notifiedUsers);
	}

	public function testADismissedNotificationIsNotSentAgain(): void {
		$this->notified = ['updates' => 'warning'];
		$this->notifiedUsers = ['updates' => ['admin']];
		$this->verdict(Concern::LEVEL_WARNING);

		$this->sender->sync();

		$this->assertSame([], $this->events);
	}

	public function testAdminsWhoHaveItAlreadyAreNotNotifiedTwice(): void {
		// Another process notified after this one read the notified levels.
		$this->inbox = [['user' => 'admin', 'id' => 'updates', 'level' => 'warning']];
		$this->verdict(Concern::LEVEL_WARNING);

		$this->sender->sync();

		$this->assertSame(['withdraw:updates:error'], $this->events);
		$this->assertSame(['updates' => ['admin']], $this->notifiedUsers);
	}

	public function testOnlyOneProcessSyncsAtATime(): void {
		$this->locking->method('acquireLock')->willThrowException(new LockedException('serverinfo/os-notifications'));
		$this->locking->expects($this->never())->method('releaseLock');
		$this->verdict(Concern::LEVEL_WARNING);

		$this->sender->sync();

		$this->assertSame([], $this->events);
		$this->assertSame([], $this->notified);
	}

	public function testTheLockIsReleased(): void {
		$this->locking->expects($this->once())->method('acquireLock')->with('serverinfo/os-notifications', ILockingProvider::LOCK_EXCLUSIVE);
		$this->locking->expects($this->once())->method('releaseLock')->with('serverinfo/os-notifications', ILockingProvider::LOCK_EXCLUSIVE);
		$this->verdict(Concern::LEVEL_WARNING);

		$this->sender->sync();
	}

	public function testAFailedWithdrawalIsLoggedAndRetried(): void {
		$this->notified = ['updates' => 'warning'];
		$this->withdrawFails = true;
		$this->logger->expects($this->once())->method('warning');
		$this->verdict(Concern::LEVEL_OK);

		$this->sender->sync();
		$this->assertSame(['updates' => 'warning'], $this->notified, 'Kept, so the next run withdraws it');

		$this->withdrawFails = false;
		$this->sender->sync();
		$this->assertSame(['withdraw:updates'], $this->events);
		$this->assertSame([], $this->notified);
	}

	public function testAnUnreadableStatusWithdrawsNothing(): void {
		$this->notified = ['updates' => 'warning'];
		$this->service->method('evaluate')->willReturn(null);

		$this->sender->sync();

		$this->assertSame([], $this->events);
		$this->assertSame(['updates' => 'warning'], $this->notified);
	}
}
