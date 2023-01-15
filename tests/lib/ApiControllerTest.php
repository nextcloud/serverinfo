<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2021 Vincent Petry <vincent@nextcloud.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\Controller\ApiController;
use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\StorageStatistics;
use OCA\ServerInfo\SystemStatistics;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

class ApiControllerTest extends \Test\TestCase {
	/** @var Os|\PHPUnit\Framework\MockObject\MockObject */
	private $os;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject  */
	private $request;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject  */
	private $config;

	/** @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject */
	private $groupManager;

	/** @var SystemStatistics|\PHPUnit\Framework\MockObject\MockObject */
	private $systemStatistics;

	/** @var StorageStatistics|\PHPUnit\Framework\MockObject\MockObject */
	private $storageStatistics;

	/** @var PhpStatistics|\PHPUnit\Framework\MockObject\MockObject */
	private $phpStatistics;

	/** @var DatabaseStatistics|\PHPUnit\Framework\MockObject\MockObject */
	private $databaseStatistics;

	/** @var ShareStatistics|\PHPUnit\Framework\MockObject\MockObject */
	private $shareStatistics;

	/** @var SessionStatistics|\PHPUnit\Framework\MockObject\MockObject */
	private $sessionStatistics;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->os = $this->createMock(Os::class);
		$this->systemStatistics = $this->createMock(SystemStatistics::class);
		$this->storageStatistics = $this->createMock(StorageStatistics::class);
		$this->phpStatistics = $this->createMock(PhpStatistics::class);
		$this->databaseStatistics = $this->createMock(DatabaseStatistics::class);
		$this->shareStatistics = $this->createMock(ShareStatistics::class);
		$this->sessionStatistics = $this->createMock(SessionStatistics::class);
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
			$this->databaseStatistics,
			$this->shareStatistics,
			$this->sessionStatistics
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
				'database' => ['some_database' => 'some_value'],
			],
			'activeUsers' => ['some_user' => 'some_value'],
		], $response->getData());
	}
}
