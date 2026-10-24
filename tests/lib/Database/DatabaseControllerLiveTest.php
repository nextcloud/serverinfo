<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Database;

use OCA\ServerInfo\Controller\DatabaseController;
use OCA\ServerInfo\Database\Advisor;
use OCA\ServerInfo\Database\CheckSummary;
use OCA\ServerInfo\Database\DatabaseProbe;
use OCA\ServerInfo\Database\LiveMetrics;
use OCA\ServerInfo\Database\RuleSet\Mysql;
use OCA\ServerInfo\Database\RuleSet\Postgres;
use OCA\ServerInfo\Database\Snapshot;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class DatabaseControllerLiveTest extends \Test\TestCase {
	private DatabaseProbe&MockObject $probe;
	private ISession&MockObject $session;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->probe = $this->createMock(DatabaseProbe::class);
		$this->session = $this->createMock(ISession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function controller(): DatabaseController {
		return new DatabaseController(
			'serverinfo',
			$this->createMock(IRequest::class),
			$this->createMock(IConfig::class),
			$this->createMock(ICredentialsManager::class),
			$this->session,
			$this->probe,
			new LiveMetrics($this->probe, $this->createMock(LoggerInterface::class)),
			$this->createMock(Advisor::class),
			$this->createMock(CheckSummary::class),
			new Mysql(),
			new Postgres(),
			$this->logger,
		);
	}

	public function testAnswersFromOneConnection(): void {
		$connection = $this->createMock(IDBConnection::class);
		$this->session->expects($this->once())->method('close');
		$this->probe->expects($this->once())
			->method('withConnection')
			->willReturnCallback(static fn (callable $callback): mixed => $callback($connection, Snapshot::FLAVOUR_MYSQL));
		$this->probe->method('scalar')->willReturn(1);
		$this->probe->method('all')->willReturnCallback(static fn (IDBConnection $conn, string $sql): array => str_starts_with($sql, 'SHOW GLOBAL STATUS')
			? [['Variable_name' => 'Threads_connected', 'Value' => '4'], ['Variable_name' => 'Questions', 'Value' => '10']]
			: [['Variable_name' => 'max_connections', 'Value' => '50']]);

		$data = $this->controller()->live()->getData();

		$this->assertSame(['available', 'latencyMs', 'connections', 'throughput', 'threadsRunning'], array_keys($data));
		$this->assertTrue($data['available']);
		$this->assertSame(['used' => 4, 'max' => 50], $data['connections']);
	}

	public function testReportsUnavailableWhenNoConnectionCanBeOpened(): void {
		$this->probe->method('withConnection')->willThrowException(new \RuntimeException('connection refused'));
		$this->logger->expects($this->once())->method('debug');

		$data = $this->controller()->live()->getData();

		$this->assertSame(['available' => false, 'latencyMs' => null], $data);
	}
}
