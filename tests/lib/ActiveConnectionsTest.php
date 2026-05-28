<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\ActiveConnections;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use Test\TestCase;

/**
 * @group DB
 */
class ActiveConnectionsTest extends TestCase {
	private IDBConnection $db;
	private ActiveConnections $instance;

	private array $insertedTokens = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->instance = new ActiveConnections($this->db);
	}

	protected function tearDown(): void {
		$this->cleanUp();
		parent::tearDown();
	}

	private function cleanUp(): void {
		if ($this->insertedTokens === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('authtoken')
			->where($qb->expr()->in('token', $qb->createNamedParameter($this->insertedTokens, IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
		$this->insertedTokens = [];
	}

	private function insertToken(int $lastActivity, int $type = 0): void {
		static $uid = 0;
		$uid++;
		$token = bin2hex(random_bytes(32));
		$this->insertedTokens[] = $token;
		$qb = $this->db->getQueryBuilder();
		$qb->insert('authtoken')
			->values([
				'uid' => $qb->createNamedParameter('testuser' . $uid),
				'login_name' => $qb->createNamedParameter('testuser' . $uid),
				'password' => $qb->createNamedParameter(''),
				'name' => $qb->createNamedParameter('Test token ' . $uid),
				'token' => $qb->createNamedParameter($token),
				'type' => $qb->createNamedParameter($type),
				'last_activity' => $qb->createNamedParameter($lastActivity),
				'last_check' => $qb->createNamedParameter(time()),
			]);
		$qb->executeStatement();
	}

	public function testReturnShape(): void {
		$result = $this->instance->getActiveConnections();

		$this->assertArrayHasKey('last5min', $result);
		$this->assertArrayHasKey('last1h', $result);
		$this->assertArrayHasKey('totalTokens', $result);
		$this->assertArrayHasKey('byType', $result);
		$this->assertIsInt($result['last5min']);
		$this->assertIsInt($result['last1h']);
		$this->assertIsInt($result['totalTokens']);
		$this->assertIsArray($result['byType']);
	}

	public function testLast5minCountIncreases(): void {
		$baseline = $this->instance->getActiveConnections()['last5min'];

		$this->insertToken(time() - 60);

		$result = $this->instance->getActiveConnections();

		$this->assertSame($baseline + 1, $result['last5min']);
	}

	public function testLast5minExcludesOldTokens(): void {
		$baseline = $this->instance->getActiveConnections()['last5min'];

		$this->insertToken(time() - 600);

		$result = $this->instance->getActiveConnections();

		$this->assertSame($baseline, $result['last5min']);
	}

	public function testLast1hCountIncreases(): void {
		$baseline = $this->instance->getActiveConnections()['last1h'];

		$this->insertToken(time() - 1800);

		$result = $this->instance->getActiveConnections();

		$this->assertSame($baseline + 1, $result['last1h']);
	}

	public function testLast1hExcludesOldTokens(): void {
		$baseline = $this->instance->getActiveConnections()['last1h'];

		$this->insertToken(time() - 7200);

		$result = $this->instance->getActiveConnections();

		$this->assertSame($baseline, $result['last1h']);
	}

	public function testTotalTokensCountIncreases(): void {
		$baseline = $this->instance->getActiveConnections()['totalTokens'];

		$this->insertToken(time() - 99999);

		$result = $this->instance->getActiveConnections();

		$this->assertSame($baseline + 1, $result['totalTokens']);
	}

	public function testByTypeContainsSessionAndPermanent(): void {
		$result = $this->instance->getActiveConnections();

		$this->assertArrayHasKey('session', $result['byType']);
		$this->assertArrayHasKey('permanent', $result['byType']);
	}

	public function testByTypeCountsSessionTokens(): void {
		$baseline = $this->instance->getActiveConnections()['byType']['session'] ?? 0;

		$this->insertToken(time() - 60, type: 0);

		$result = $this->instance->getActiveConnections();

		$this->assertSame($baseline + 1, $result['byType']['session']);
	}

	public function testByTypeCountsPermanentTokens(): void {
		$baseline = $this->instance->getActiveConnections()['byType']['permanent'] ?? 0;

		$this->insertToken(time() - 60, type: 1);

		$result = $this->instance->getActiveConnections();

		$this->assertSame($baseline + 1, $result['byType']['permanent']);
	}

	public function testLast5minIsSubsetOfLast1h(): void {
		$result = $this->instance->getActiveConnections();

		$this->assertLessThanOrEqual($result['last1h'], $result['last5min']);
	}

	public function testLast1hIsSubsetOfTotalTokens(): void {
		$result = $this->instance->getActiveConnections();

		$this->assertLessThanOrEqual($result['totalTokens'], $result['last1h']);
	}
}
