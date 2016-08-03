<?php
/**
 * @copyright Copyright (c) 2016 Bjoern Schiessle <bjoern@schiessle.org>
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


namespace OCA\ServerInfo\Tests\lib\Controller;


use OCA\ServerInfo\Controller\ApiController;
use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\StorageStatistics;
use OCA\ServerInfo\SystemStatistics;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Test\TestCase;

class ApiControllerTest extends TestCase {

	/** @var  IRequest | \PHPUnit_Framework_MockObject_MockObject */
	private $request;

	/** @var  SystemStatistics | \PHPUnit_Framework_MockObject_MockObject */
	private $systemStatistics;

	/** @var  StorageStatistics | \PHPUnit_Framework_MockObject_MockObject */
	private $storageStatistics;

	/** @var  PhpStatistics | \PHPUnit_Framework_MockObject_MockObject */
	private $phpStatistics;

	/** @var  DatabaseStatistics | \PHPUnit_Framework_MockObject_MockObject */
	private $databaseStatistics;

	/** @var  ShareStatistics | \PHPUnit_Framework_MockObject_MockObject */
	private $shareStatistics;

	/** @var  SessionStatistics | \PHPUnit_Framework_MockObject_MockObject */
	private $sessionStatistics;

	/** @var  ApiController */
	private $instance;

	public function setUp() {
		parent::setUp();

		$this->request = $this->getMockBuilder('OCP\IRequest')
			->disableOriginalConstructor()
			->getMock();

		$this->systemStatistics = $this->getMockBuilder('OCA\ServerInfo\SystemStatistics')
			->disableOriginalConstructor()
			->getMock();

		$this->storageStatistics = $this->getMockBuilder('OCA\ServerInfo\StorageStatistics')
			->disableOriginalConstructor()
			->getMock();

		$this->phpStatistics = $this->getMockBuilder('OCA\ServerInfo\PhpStatistics')
			->disableOriginalConstructor()
			->getMock();

		$this->databaseStatistics = $this->getMockBuilder('OCA\ServerInfo\DatabaseStatistics')
			->disableOriginalConstructor()
			->getMock();

		$this->shareStatistics = $this->getMockBuilder('OCA\ServerInfo\ShareStatistics')
			->disableOriginalConstructor()
			->getMock();

		$this->sessionStatistics = $this->getMockBuilder('OCA\ServerInfo\SessionStatistics')
			->disableOriginalConstructor()
			->getMock();


		$this->instance = new ApiController(
			'ServerInfoTest',
			$this->request,
			$this->systemStatistics,
			$this->storageStatistics,
			$this->phpStatistics,
			$this->databaseStatistics,
			$this->shareStatistics,
			$this->sessionStatistics
		);
	}

	public function testInfo() {

		$this->systemStatistics->expects($this->once())->method('getSystemStatistics')
			->willReturn('systemStatistics');
		$this->storageStatistics->expects($this->once())->method('getStorageStatistics')
			->willReturn('storageStatistics');
		$this->phpStatistics->expects($this->once())->method('getPhpStatistics')
			->willReturn('phpStatistics');
		$this->databaseStatistics->expects($this->once())->method('getDatabaseStatistics')
			->willReturn('databaseStatistics');
		$this->shareStatistics->expects($this->once())->method('getShareStatistics')
			->willReturn('shareStatistics');
		$this->sessionStatistics->expects($this->once())->method('getSessionStatistics')
			->willReturn('sessionStatistics');

		$result = $this->instance->info();
		$this->assertTrue($result instanceof DataResponse);
		$data = $result->getData();
		$this->assertTrue(isset($data['data']['nextcloud']));
		$this->assertTrue(isset($data['data']['server']));

		$this->assertSame('systemStatistics', $data['data']['nextcloud']['system']);
		$this->assertSame('storageStatistics', $data['data']['nextcloud']['storage']);
		$this->assertSame('shareStatistics', $data['data']['nextcloud']['shares']);
		$this->assertSame('unknown', $data['data']['server']['webserver']);
		$this->assertSame('databaseStatistics', $data['data']['server']['database']);
		$this->assertSame('phpStatistics', $data['data']['server']['php']);
		$this->assertSame('sessionStatistics', $data['data']['activeUsers']);
	}

}
