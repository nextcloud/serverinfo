<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\LoginStats;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * @group DB
 */
class LoginStatsTest extends TestCase {
	private IDBConnection $db;
	private IConfig&MockObject $config;
	private LoginStats $instance;

	private const IP_A = '10.0.0.1';
	private const IP_B = '10.0.0.2';
	private const IP_C = '10.0.0.3';

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueBool')->willReturn(false);
		$this->config->method('getSystemValueString')->willReturn('');
		$this->instance = new LoginStats($this->config, $this->db);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	private function cleanUp(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('bruteforce_attempts')
			->where($qb->expr()->in('ip', $qb->createNamedParameter(
				[self::IP_A, self::IP_B, self::IP_C],
				IQueryBuilder::PARAM_STR_ARRAY
			)));
		$qb->executeStatement();
	}

	private function insertAttempt(string $ip, int $occurred): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('bruteforce_attempts')
			->values([
				'action' => $qb->createNamedParameter('login'),
				'occurred' => $qb->createNamedParameter($occurred),
				'ip' => $qb->createNamedParameter($ip),
				'subnet' => $qb->createNamedParameter($ip . '/32'),
				'metadata' => $qb->createNamedParameter('{}'),
			]);
		$qb->executeStatement();
	}

	public function testRedisBackendReturnsUnavailable(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$config->method('getSystemValueString')->willReturn('OC\Memcache\Redis');
		$instance = new LoginStats($config, $this->db);

		$result = $instance->getStats();

		$this->assertFalse($result['available']);
		$this->assertSame('redis_backend', $result['reason']);
	}

	public function testForceDatabaseOverridesRedis(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);
		$config->method('getSystemValueString')->willReturn('OC\Memcache\Redis');
		$instance = new LoginStats($config, $this->db);

		$result = $instance->getStats();

		$this->assertTrue($result['available']);
	}

	public function testReturnShape(): void {
		$result = $this->instance->getStats();

		$this->assertArrayHasKey('bruteforceAttempts24h', $result);
		$this->assertArrayHasKey('bruteforceAttempts1h', $result);
		$this->assertArrayHasKey('bruteforceTotal', $result);
		$this->assertArrayHasKey('topIps', $result);
		$this->assertArrayHasKey('available', $result);
		$this->assertTrue($result['available']);
		$this->assertIsInt($result['bruteforceAttempts24h']);
		$this->assertIsInt($result['bruteforceAttempts1h']);
		$this->assertIsInt($result['bruteforceTotal']);
		$this->assertIsArray($result['topIps']);
	}

	public function testTotalCountIncreases(): void {
		$baseline = $this->instance->getStats()['bruteforceTotal'];

		$this->insertAttempt(self::IP_A, time() - 7200);
		$this->insertAttempt(self::IP_B, time() - 3000);

		$result = $this->instance->getStats();

		$this->assertSame($baseline + 2, $result['bruteforceTotal']);
	}

	public function test24hCountFiltersOldAttempts(): void {
		$baseline = $this->instance->getStats()['bruteforceAttempts24h'];

		$this->insertAttempt(self::IP_A, time() - 100);
		$this->insertAttempt(self::IP_B, time() - (25 * 3600));

		$result = $this->instance->getStats();

		$this->assertSame($baseline + 1, $result['bruteforceAttempts24h']);
	}

	public function test1hCountFiltersOlderAttempts(): void {
		$baseline = $this->instance->getStats()['bruteforceAttempts1h'];

		$this->insertAttempt(self::IP_A, time() - 60);
		$this->insertAttempt(self::IP_B, time() - 7200);

		$result = $this->instance->getStats();

		$this->assertSame($baseline + 1, $result['bruteforceAttempts1h']);
	}

	public function testTopIpsShape(): void {
		$this->insertAttempt(self::IP_A, time() - 60);

		$result = $this->instance->getStats();

		$this->assertIsArray($result['topIps']);
		foreach ($result['topIps'] as $entry) {
			$this->assertArrayHasKey('ip', $entry);
			$this->assertArrayHasKey('count', $entry);
			$this->assertIsString($entry['ip']);
			$this->assertIsInt($entry['count']);
		}
	}

	public function testTopIpsOrderedByCountDescending(): void {
		$now = time();
		$this->insertAttempt(self::IP_A, $now - 60);
		$this->insertAttempt(self::IP_B, $now - 120);
		$this->insertAttempt(self::IP_B, $now - 180);
		$this->insertAttempt(self::IP_B, $now - 240);

		$result = $this->instance->getStats();

		$topIps = $result['topIps'];
		$this->assertNotEmpty($topIps);
		$ipAddresses = array_column($topIps, 'ip');
		$posA = array_search(self::IP_A, $ipAddresses);
		$posB = array_search(self::IP_B, $ipAddresses);
		$this->assertNotFalse($posA);
		$this->assertNotFalse($posB);
		$this->assertLessThan($posA, $posB);
	}

	public function testTopIpsLimitedToFive(): void {
		$ips = ['192.168.1.1', '192.168.1.2', '192.168.1.3', '192.168.1.4', '192.168.1.5', '192.168.1.6'];
		$now = time();
		foreach ($ips as $ip) {
			$this->insertAttempt($ip, $now - 60);
		}

		$result = $this->instance->getStats();

		$this->assertLessThanOrEqual(5, count($result['topIps']));

		$qb = $this->db->getQueryBuilder();
		$qb->delete('bruteforce_attempts')
			->where($qb->expr()->in('ip', $qb->createNamedParameter($ips, IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
	}
}
