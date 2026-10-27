<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\Collector\BackgroundJobs;
use OCA\ServerInfo\Controller\JobsController;
use OCA\ServerInfo\JobQueueActions;
use OCA\ServerInfo\JobQueueInfo;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class JobsControllerTest extends \Test\TestCase {
	private const JOB = ['id' => '4711', 'class' => 'OCA\\Files\\BackgroundJob\\ScanFiles', 'reservedAt' => 1000];

	private JobQueueInfo&MockObject $queueInfo;
	private JobQueueActions&MockObject $actions;
	private BackgroundJobs&MockObject $backgroundJobs;
	private ISession&MockObject $session;
	private LoggerInterface&MockObject $logger;
	private JobsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->queueInfo = $this->createMock(JobQueueInfo::class);
		$this->actions = $this->createMock(JobQueueActions::class);
		$this->backgroundJobs = $this->createMock(BackgroundJobs::class);
		$this->session = $this->createMock(ISession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1_800_000_000);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new JobsController(
			'serverinfo',
			$this->createMock(IRequest::class),
			$this->queueInfo,
			$this->actions,
			$this->backgroundJobs,
			$timeFactory,
			$this->session,
			$userSession,
			$this->logger,
		);
	}

	public static function actions(): array {
		return [['release'], ['runAtNextCron']];
	}

	#[DataProvider('actions')]
	public function testActionsAreForFullAdminsWithAConfirmedPassword(string $method): void {
		$reflection = new \ReflectionMethod(JobsController::class, $method);

		$this->assertCount(1, $reflection->getAttributes(PasswordConfirmationRequired::class));
		$this->assertCount(1, $reflection->getAttributes(UserRateLimit::class));
		$this->assertCount(0, $reflection->getAttributes(NoAdminRequired::class));
		$this->assertCount(0, $reflection->getAttributes(AuthorizedAdminSetting::class));
		$this->assertStringNotContainsString('@NoAdminRequired', (string)$reflection->getDocComment());
	}

	public function testQueueIsForFullAdmins(): void {
		$reflection = new \ReflectionMethod(JobsController::class, 'queue');

		$this->assertCount(0, $reflection->getAttributes(NoAdminRequired::class));
		$this->assertCount(0, $reflection->getAttributes(AuthorizedAdminSetting::class));
		$this->assertStringNotContainsString('@NoAdminRequired', (string)$reflection->getDocComment());
	}

	public static function invalidIds(): array {
		return [[''], ['0'], ['abc'], ['12a'], ['-5'], ['1 OR 1=1'], [str_repeat('9', 21)]];
	}

	#[DataProvider('invalidIds')]
	public function testRejectsAnInvalidId(string $id): void {
		$this->actions->expects($this->never())->method('find');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->release($id)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->runAtNextCron($id)->getStatus());
	}

	#[DataProvider('actions')]
	public function testUnknownJobIsNotFound(string $method): void {
		$this->actions->method('find')->with('4711')->willReturn(null);
		$this->actions->expects($this->never())->method('release');
		$this->actions->expects($this->never())->method('runAtNextCron');
		$this->logger->expects($this->never())->method('info');

		$response = $this->controller->$method('4711');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['reason' => 'unknown'], $response->getData());
	}

	public function testReleaseRefusesAJobThatIsNotStuck(): void {
		$this->actions->method('find')->willReturn(self::JOB);
		$this->actions->method('isStuck')->willReturn(false);
		$this->actions->expects($this->never())->method('release');

		$response = $this->controller->release('4711');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['reason' => 'not-stuck'], $response->getData());
	}

	public function testReleaseClearsTheReservationAndLogsIt(): void {
		$this->actions->method('find')->willReturn(self::JOB);
		$this->actions->method('isStuck')->willReturn(true);
		$this->actions->expects($this->once())->method('release')->with(self::JOB)->willReturn(true);
		$this->queueInfo->expects($this->once())->method('invalidate');
		$this->logger->expects($this->once())->method('info')->with(
			$this->stringContains('released the reservation'),
			['app' => 'serverinfo', 'actor' => 'alice', 'jobId' => '4711', 'jobClass' => 'OCA\\Files\\BackgroundJob\\ScanFiles'],
		);

		$response = $this->controller->release('4711');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	#[DataProvider('actions')]
	public function testReportsAJobThatChangedMeanwhile(string $method): void {
		$this->actions->method('find')->willReturn(self::JOB);
		$this->actions->method('isStuck')->willReturn(true);
		$this->actions->method('isReserved')->willReturn(false);
		$this->actions->method($method)->willReturn(false);
		$this->queueInfo->expects($this->never())->method('invalidate');
		$this->logger->expects($this->never())->method('info');

		$response = $this->controller->$method('4711');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['reason' => 'changed'], $response->getData());
	}

	public function testRunAtNextCronRefusesAJobThatMayBeRunning(): void {
		$this->actions->method('find')->willReturn(self::JOB);
		$this->actions->method('isReserved')->willReturn(true);
		$this->actions->expects($this->never())->method('runAtNextCron');

		$response = $this->controller->runAtNextCron('4711');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['reason' => 'running'], $response->getData());
	}

	public function testRunAtNextCronSchedulesTheJobAndLogsIt(): void {
		$this->actions->method('find')->willReturn(self::JOB);
		$this->actions->method('isReserved')->willReturn(false);
		$this->actions->expects($this->once())->method('runAtNextCron')->with(self::JOB)->willReturn(true);
		$this->queueInfo->expects($this->once())->method('invalidate');
		$this->logger->expects($this->once())->method('info')->with(
			$this->stringContains('for the next cron run'),
			['app' => 'serverinfo', 'actor' => 'alice', 'jobId' => '4711', 'jobClass' => 'OCA\\Files\\BackgroundJob\\ScanFiles'],
		);

		$response = $this->controller->runAtNextCron('4711');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => '4711'], $response->getData());
	}

	public function testQueueSendsTheQueueAndTheFailuresWithTheirJob(): void {
		$queue = ['total' => 3, 'reserved' => 1, 'stuck' => 0, 'oldestLastRun' => 5, 'topClasses' => [], 'reservedJobs' => []];
		$this->queueInfo->method('getJobQueueInfo')->willReturn($queue);
		$this->backgroundJobs->method('failuresByClass')->willReturn([
			['className' => 'OCA\\A\\Job', 'failures' => 3, 'lastFailedAt' => 20],
			['className' => 'OCA\\B\\Job', 'failures' => 1, 'lastFailedAt' => 10],
		]);
		$this->queueInfo->method('soleJobIds')->with(['OCA\\A\\Job', 'OCA\\B\\Job'])->willReturn(['OCA\\B\\Job' => '42']);
		$this->session->expects($this->once())->method('close');

		$this->assertSame([
			'now' => 1_800_000_000,
			'stuckAfter' => JobQueueInfo::STUCK_THRESHOLD_SECONDS,
			'queue' => $queue,
			'failures' => [
				['className' => 'OCA\\A\\Job', 'failures' => 3, 'lastFailedAt' => 20, 'jobId' => null],
				['className' => 'OCA\\B\\Job', 'failures' => 1, 'lastFailedAt' => 10, 'jobId' => '42'],
			],
		], $this->controller->queue()->getData());
	}
}
