<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\Controller\ApiController;
use OCA\ServerInfo\FpmStatistics;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\StatisticsCache;
use OCA\ServerInfo\SystemStatistics;
use OCA\ServerInfo\UptimeFormatter;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;

class ApiControllerTest extends \Test\TestCase {
	private Os&MockObject $os;
	private IRequest&MockObject $request;
	private IConfig&MockObject $config;
	private IGroupManager&MockObject $groupManager;
	private SystemStatistics&MockObject $systemStatistics;
	private StatisticsCache&MockObject $statisticsCache;
	private PhpStatistics&MockObject $phpStatistics;
	private FpmStatistics&MockObject $fpmStatistics;
	private UptimeFormatter&MockObject $uptimeFormatter;
	private OsStatusService&MockObject $osStatus;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->os = $this->createMock(Os::class);
		$this->systemStatistics = $this->createMock(SystemStatistics::class);
		$this->statisticsCache = $this->createMock(StatisticsCache::class);
		$this->statisticsCache->method('get')->willReturn([
			'storage' => [],
			'shares' => [],
			'database' => [],
			'activeUsers' => [],
		]);
		$this->phpStatistics = $this->createMock(PhpStatistics::class);
		$this->fpmStatistics = $this->createMock(FpmStatistics::class);
		$this->uptimeFormatter = $this->createMock(UptimeFormatter::class);
		$this->osStatus = $this->createMock(OsStatusService::class);
	}

	private function getController($userSession) {
		return new ApiController(
			'serverinfo',
			$this->request,
			$this->config,
			$this->groupManager,
			$userSession,
			$this->os,
			$this->systemStatistics,
			$this->statisticsCache,
			$this->phpStatistics,
			$this->fpmStatistics,
			$this->uptimeFormatter,
			$this->osStatus,
		);
	}

	public function testAuthFailureNoSession() {
		$this->statisticsCache->expects($this->never())->method('get');

		$response = $this->getController(null)->info();

		$this->assertEquals(['message' => 'Unauthorized'], $response->getData());
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testAuthFailureNoUser() {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$response = $this->getController($userSession)->info();

		$this->assertEquals(['message' => 'Unauthorized'], $response->getData());
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testAuthFailureNoAdmin() {
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('nonadmin');
		$userSession->method('getUser')->willReturn($user);
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('nonadmin')
			->willReturn(false);

		$response = $this->getController($userSession)->info();

		$this->assertEquals(['message' => 'Unauthorized'], $response->getData());
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testAuthSuccessWithAdmin() {
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession->method('getUser')->willReturn($user);
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('admin')
			->willReturn(true);

		$response = $this->getController($userSession)->info();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testAuthFailureWithToken() {
		$this->request->expects($this->once())
			->method('getHeader')
			->with('NC-Token')
			->willReturn('invalidtoken');

		$this->config->expects($this->once())
			->method('getAppValue')
			->with('serverinfo', 'token', null)
			->willReturn('megatoken');
		$response = $this->getController(null)->info();

		$this->assertEquals(['message' => 'Unauthorized'], $response->getData());
		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testAuthSuccessWithToken() {
		$this->request->expects($this->once())
			->method('getHeader')
			->with('NC-Token')
			->willReturn('megatoken');

		$this->config->expects($this->once())
			->method('getAppValue')
			->with('serverinfo', 'token', null)
			->willReturn('megatoken');
		$response = $this->getController(null)->info();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testIn() {
		$this->request->expects($this->once())
			->method('getHeader')
			->with('NC-Token')
			->willReturn('megatoken');

		$this->config->expects($this->once())
			->method('getAppValue')
			->with('serverinfo', 'token', null)
			->willReturn('megatoken');
		$response = $this->getController(null)->info();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testInfo() {
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession->method('getUser')->willReturn($user);
		$this->groupManager->expects($this->once())
			->method('isAdmin')
			->with('admin')
			->willReturn(true);

		$this->systemStatistics->expects($this->once())
			->method('getSystemStatistics')
			->willReturn(['some_system' => 'some_value']);
		$this->phpStatistics->expects($this->once())
			->method('getPhpStatistics')
			->willReturn(['some_php' => 'some_value']);
		$this->fpmStatistics->expects($this->once())
			->method('getFpmStatistics')
			->willReturn(['some_fpm' => 'some_value']);
		$this->statisticsCache = $this->createMock(StatisticsCache::class);
		$this->statisticsCache->expects($this->once())
			->method('get')
			->willReturn([
				'storage' => ['some_storage' => 'some_value'],
				'shares' => ['some_shares' => 'some_value'],
				'database' => ['some_database' => 'some_value'],
				'activeUsers' => ['some_user' => 'some_value'],
			]);
		$this->osStatus->expects($this->never())->method('monitoringSummary');

		$response = $this->getController($userSession)->info();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());

		$this->assertEquals([
			'nextcloud' => [
				'system' => ['some_system' => 'some_value'],
				'storage' => ['some_storage' => 'some_value'],
				'shares' => ['some_shares' => 'some_value'],
			],
			'server' => [
				'webserver' => 'unknown',
				'php' => ['some_php' => 'some_value'],
				'fpm' => ['some_fpm' => 'some_value'],
				'database' => ['some_database' => 'some_value'],
			],
			'activeUsers' => ['some_user' => 'some_value'],
		], $response->getData());
	}

	public function testInfoWithTheOperatingSystemOnRequest(): void {
		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->osStatus->expects($this->once())
			->method('monitoringSummary')
			->willReturn(['state' => 'ok', 'level' => 'warning']);

		$response = $this->getController($userSession)->info(skipOs: false);

		$this->assertSame(['state' => 'ok', 'level' => 'warning'], $response->getData()['server']['os']);
	}
}
