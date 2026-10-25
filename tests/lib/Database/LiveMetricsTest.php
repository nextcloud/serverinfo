<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Database;

use OCA\ServerInfo\Database\DatabaseProbe;
use OCA\ServerInfo\Database\LiveMetrics;
use OCA\ServerInfo\Database\NextcloudSchema;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ICredentialsManager;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Runs the live metrics against a scripted connection, so the MySQL, MariaDB
 * and PostgreSQL paths are covered whatever database the tests run on, and
 * once against the real one.
 *
 * @group DB
 */
class LiveMetricsTest extends \Test\TestCase {
	private IDBConnection&MockObject $connection;
	private LoggerInterface&MockObject $logger;
	/** @var list<string> every statement sent, whitespace collapsed */
	private array $queries = [];
	/** @var array<string, list<array<string, mixed>>|\Throwable> answers by statement prefix */
	private array $answers = [];

	protected function setUp(): void {
		parent::setUp();

		$this->connection = $this->createMock(IDBConnection::class);
		$this->connection->method('prepare')->willReturnCallback(fn (string $sql): IPreparedStatement => $this->statement($sql));
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function statement(string $sql): IPreparedStatement {
		$sql = trim((string)preg_replace('/\s+/', ' ', $sql));
		$this->queries[] = $sql;

		$answer = null;
		foreach ($this->answers as $prefix => $candidate) {
			if (str_starts_with($sql, $prefix)) {
				$answer = $candidate;
				break;
			}
		}
		if ($answer === null) {
			$this->fail('Unexpected query: ' . $sql);
		}

		$statement = $this->createMock(IPreparedStatement::class);
		if ($answer instanceof \Throwable) {
			$statement->method('execute')->willThrowException($answer);
			return $statement;
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$answer, false]);
		$statement->method('execute')->willReturn($result);
		return $statement;
	}

	private function metrics(): LiveMetrics {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnArgument(2);
		$probe = new DatabaseProbe(
			$this->connection,
			$config,
			$this->createMock(ICredentialsManager::class),
			Server::get(NextcloudSchema::class),
			$this->createMock(LoggerInterface::class),
		);

		return new LiveMetrics($probe, $this->logger);
	}

	/**
	 * @return list<string>
	 */
	private function queriesStartingWith(string $prefix): array {
		return array_values(array_filter($this->queries, static fn (string $sql): bool => str_starts_with($sql, $prefix)));
	}

	private function mysqlAnswers(string $version): void {
		$this->answers = [
			'SELECT VERSION()' => [['v' => $version]],
			'SELECT 1' => [['1' => 1]],
			'SHOW GLOBAL STATUS' => [
				['Variable_name' => 'Questions', 'Value' => '123456'],
				['Variable_name' => 'Threads_connected', 'Value' => '7'],
				['Variable_name' => 'Threads_running', 'Value' => '2'],
			],
			'SHOW VARIABLES' => [['Variable_name' => 'max_connections', 'Value' => '151']],
		];
	}

	public function testMysqlDetectsTheFlavourOnceAndTimesSelectOne(): void {
		$this->mysqlAnswers('8.0.39');

		$live = $this->metrics()->collect();

		$this->assertTrue($live['available']);
		$this->assertIsFloat($live['latencyMs']);
		$this->assertGreaterThanOrEqual(0.0, $live['latencyMs']);
		$this->assertSame(['used' => 7, 'max' => 151], $live['connections'] ?? null);
		$this->assertSame(['counter' => 123456.0, 'label' => 'Queries/s'], $live['throughput'] ?? null);
		$this->assertSame(2, $live['threadsRunning'] ?? null);
		$this->assertSame(['available', 'latencyMs', 'connections', 'throughput', 'threadsRunning'], array_keys($live));

		$this->assertCount(1, $this->queriesStartingWith('SELECT VERSION()'));
		$this->assertSame(['SELECT 1'], $this->queriesStartingWith('SELECT 1'));
		$this->assertCount(4, $this->queries);
	}

	public function testMariaDbTakesTheMysqlPath(): void {
		$this->mysqlAnswers('10.11.8-MariaDB-0ubuntu0.24.04.1');

		$live = $this->metrics()->collect();

		$this->assertTrue($live['available']);
		$this->assertSame('Queries/s', $live['throughput']['label'] ?? null);
		$this->assertCount(1, $this->queriesStartingWith('SELECT VERSION()'));
	}

	public function testPostgresCountsConnectionsInOneQuery(): void {
		$this->answers = [
			'SELECT VERSION()' => [['version' => 'PostgreSQL 16.4 on x86_64-pc-linux-gnu']],
			'SELECT 1' => [['?column?' => 1]],
			'SELECT count(*) AS used' => [['used' => 12, 'active' => 3, 'max_connections' => '100']],
			'SELECT COALESCE(SUM(xact_commit + xact_rollback), 0)' => [['coalesce' => '98765']],
		];

		$live = $this->metrics()->collect();

		$this->assertSame([
			'available' => true,
			'latencyMs' => $live['latencyMs'],
			'connections' => ['used' => 12, 'max' => 100],
			'throughput' => ['counter' => 98765.0, 'label' => 'Transactions/s'],
			'threadsRunning' => 3,
		], $live);
		$this->assertIsFloat($live['latencyMs']);

		$activity = array_values(array_filter($this->queries, static fn (string $sql): bool => str_contains($sql, 'pg_stat_activity')));
		$this->assertCount(1, $activity);
		$this->assertStringContainsString("FILTER (WHERE state = 'active')", $activity[0]);
		$this->assertCount(1, $this->queriesStartingWith('SELECT VERSION()'));
		$this->assertCount(4, $this->queries);
	}

	public function testUnsupportedDatabaseIsNotQueriedFurther(): void {
		$this->answers = [
			'SELECT VERSION()' => new \RuntimeException('no such function'),
			'SHOW server_version' => new \RuntimeException('syntax error'),
		];

		$this->assertSame(['available' => false, 'latencyMs' => null], $this->metrics()->collect());
		$this->assertSame(['SELECT VERSION()', 'SHOW server_version'], $this->queries);
	}

	public function testKeepsTheLatencyWhenTheMetricsFail(): void {
		$this->mysqlAnswers('8.4.2');
		$this->answers = ['SHOW GLOBAL STATUS' => new \RuntimeException('Access denied')] + $this->answers;
		$this->logger->expects($this->once())->method('debug');

		$live = $this->metrics()->collect();

		$this->assertFalse($live['available']);
		$this->assertIsFloat($live['latencyMs']);
		$this->assertSame(['available', 'latencyMs'], array_keys($live));
	}

	public function testReportsNoLatencyWhenTheRoundTripFails(): void {
		$this->mysqlAnswers('8.4.2');
		$this->answers = ['SELECT 1' => new \RuntimeException('gone away')] + $this->answers;

		$live = $this->metrics()->collect();

		$this->assertTrue($live['available']);
		$this->assertNull($live['latencyMs']);
	}

	public function testRunsAgainstTheRealDatabase(): void {
		$live = Server::get(LiveMetrics::class)->collect();

		if (Server::get(IDBConnection::class)->getDatabaseProvider() === IDBConnection::PLATFORM_SQLITE) {
			$this->assertSame(['available' => false, 'latencyMs' => null], $live);
			return;
		}

		$this->assertArrayHasKey('available', $live);
		$this->assertArrayHasKey('latencyMs', $live);
	}
}
