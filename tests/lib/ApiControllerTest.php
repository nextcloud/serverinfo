<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\Controller\ApiController;
use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\FpmStatistics;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\StorageStatistics;
use OCA\ServerInfo\SystemStatistics;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
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
	private StorageStatistics&MockObject $storageStatistics;
	private PhpStatistics&MockObject $phpStatistics;
	private FpmStatistics&MockObject $fpmStatistics;
	private DatabaseStatistics&MockObject $databaseStatistics;
	private ShareStatistics&MockObject $shareStatistics;
	private SessionStatistics&MockObject $sessionStatistics;
	private IL10N&MockObject $l10n;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->os = $this->createMock(Os::class);
		$this->systemStatistics = $this->createMock(SystemStatistics::class);
		$this->storageStatistics = $this->createMock(StorageStatistics::class);
		$this->phpStatistics = $this->createMock(PhpStatistics::class);
		$this->fpmStatistics = $this->createMock(FpmStatistics::class);
		$this->databaseStatistics = $this->createMock(DatabaseStatistics::class);
		$this->shareStatistics = $this->createMock(ShareStatistics::class);
		$this->sessionStatistics = $this->createMock(SessionStatistics::class);
		$this->l10n = $this->createMock(IL10N::class);
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
			$this->storageStatistics,
			$this->phpStatistics,
			$this->fpmStatistics,
			$this->databaseStatistics,
			$this->shareStatistics,
			$this->sessionStatistics,
			$this->l10n
		);
	}

	public function testAuthFailureNoSession() {
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
		$this->storageStatistics->expects($this->once())
			->method('getStorageStatistics')
			->willReturn(['some_storage' => 'some_value']);
		$this->shareStatistics->expects($this->once())
			->method('getShareStatistics')
			->willReturn(['some_shares' => 'some_value']);
		$this->phpStatistics->expects($this->once())
			->method('getPhpStatistics')
			->willReturn(['some_php' => 'some_value']);
		$this->fpmStatistics->expects($this->once())
			->method('getFpmStatistics')
			->willReturn(['some_fpm' => 'some_value']);
		$this->databaseStatistics->expects($this->once())
			->method('getDatabaseStatistics')
			->willReturn(['some_database' => 'some_value']);
		$this->sessionStatistics->expects($this->once())
			->method('getSessionStatistics')
			->willReturn(['some_user' => 'some_value']);

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
}
