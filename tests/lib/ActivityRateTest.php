<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\ActivityRate;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * @group DB
 */
class ActivityRateTest extends TestCase {
	private IDBConnection $db;
	private IAppManager&MockObject $appManager;
	private ActivityRate $instance;
	private bool $tableAvailable;
	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->instance = new ActivityRate($this->appManager, $this->db);
		$this->tableAvailable = $this->checkTableAvailable();
	}

	protected function tearDown(): void {
		if ($this->tableAvailable) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('activity')
				->where($qb->expr()->eq('app', $qb->createNamedParameter('serverinfo_test')));
			$qb->executeStatement();
		}
		parent::tearDown();
	}

	private function checkTableAvailable(): bool {
		try {
			$this->db->getQueryBuilder()->select('activity_id')->from('activity')->setMaxResults(1)->executeQuery()->closeCursor();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function insertActivity(string $type, int $timestamp): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('activity')
			->values([
				'timestamp' => $qb->createNamedParameter($timestamp),
				'priority' => $qb->createNamedParameter(30),
				'type' => $qb->createNamedParameter($type),
				'user' => $qb->createNamedParameter('testuser'),
				'affecteduser' => $qb->createNamedParameter('testuser'),
				'app' => $qb->createNamedParameter('serverinfo_test'),
				'subject' => $qb->createNamedParameter('test_subject'),
				'subjectparams' => $qb->createNamedParameter('[]'),
				'message' => $qb->createNamedParameter(''),
				'messageparams' => $qb->createNamedParameter('[]'),
				'file' => $qb->createNamedParameter(''),
				'link' => $qb->createNamedParameter(''),
				'object_type' => $qb->createNamedParameter(''),
				'object_id' => $qb->createNamedParameter(0),
			]);
		$qb->executeStatement();
	}

	public function testNotInstalledReturnsInstalledFalse(): void {
		$this->appManager->method('isInstalled')->with('activity')->willReturn(false);

		$result = $this->instance->getActivityRate();

		$this->assertFalse($result['installed']);
		$this->assertSame(0, $result['last1h']);
		$this->assertSame(0, $result['last24h']);
		$this->assertSame(0, $result['last7d']);
		$this->assertSame([], $result['topActions']);
	}

	public function testReturnShape(): void {
		$this->appManager->method('isInstalled')->with('activity')->willReturn(true);

		$result = $this->instance->getActivityRate();

		$this->assertArrayHasKey('installed', $result);
		$this->assertArrayHasKey('last1h', $result);
		$this->assertArrayHasKey('last24h', $result);
		$this->assertArrayHasKey('last7d', $result);
		$this->assertArrayHasKey('topActions', $result);
		$this->assertTrue($result['installed']);
		$this->assertIsInt($result['last1h']);
		$this->assertIsInt($result['last24h']);
		$this->assertIsInt($result['last7d']);
		$this->assertIsArray($result['topActions']);
	}

	public function testCountsAreNonNegative(): void {
		$this->appManager->method('isInstalled')->with('activity')->willReturn(true);

		$result = $this->instance->getActivityRate();

		$this->assertGreaterThanOrEqual(0, $result['last1h']);
		$this->assertGreaterThanOrEqual(0, $result['last24h']);
		$this->assertGreaterThanOrEqual(0, $result['last7d']);
	}

	public function testHierarchyLast1hLeqlast24hLeqlast7d(): void {
		$this->appManager->method('isInstalled')->with('activity')->willReturn(true);

		$result = $this->instance->getActivityRate();

		$this->assertLessThanOrEqual($result['last24h'], $result['last1h']);
		$this->assertLessThanOrEqual($result['last7d'], $result['last24h']);
	}

	public function testTopActionsShape(): void {
		$this->appManager->method('isInstalled')->with('activity')->willReturn(true);

		$result = $this->instance->getActivityRate();

		$this->assertIsArray($result['topActions']);
		foreach ($result['topActions'] as $entry) {
			$this->assertArrayHasKey('action', $entry);
			$this->assertArrayHasKey('count', $entry);
			$this->assertIsString($entry['action']);
			$this->assertIsInt($entry['count']);
		}
	}

	public function testLast1hCountIncreasesWithRecentActivity(): void {
		if (!$this->tableAvailable) {
			$this->markTestSkipped('activity table not available');
		}
		$this->appManager->method('isInstalled')->with('activity')->willReturn(true);

		$baseline = $this->instance->getActivityRate()['last1h'];
		$this->insertActivity('file_created', time() - 60);

		$result = $this->instance->getActivityRate();

		$this->assertSame($baseline + 1, $result['last1h']);
	}

	public function testLast1hExcludesOldActivity(): void {
		if (!$this->tableAvailable) {
			$this->markTestSkipped('activity table not available');
		}
		$this->appManager->method('isInstalled')->with('activity')->willReturn(true);

		$baseline = $this->instance->getActivityRate()['last1h'];
		$this->insertActivity('file_created', time() - 7200);

		$result = $this->instance->getActivityRate();

		$this->assertSame($baseline, $result['last1h']);
	}

	public function testLast24hCountIncreasesWithRecentActivity(): void {
		if (!$this->tableAvailable) {
			$this->markTestSkipped('activity table not available');
		}
		$this->appManager->method('isInstalled')->with('activity')->willReturn(true);

		$baseline = $this->instance->getActivityRate()['last24h'];
		$this->insertActivity('file_created', time() - 3600);

		$result = $this->instance->getActivityRate();

		$this->assertSame($baseline + 1, $result['last24h']);
	}

	public function testLast7dCountIncreasesWithActivity(): void {
		if (!$this->tableAvailable) {
			$this->markTestSkipped('activity table not available');
		}
		$this->appManager->method('isInstalled')->with('activity')->willReturn(true);

		$baseline = $this->instance->getActivityRate()['last7d'];
		$this->insertActivity('file_created', time() - (3 * 86400));

		$result = $this->instance->getActivityRate();

		$this->assertSame($baseline + 1, $result['last7d']);
	}

	public function testTopActionsReturnsInsertedTypes(): void {
		if (!$this->tableAvailable) {
			$this->markTestSkipped('activity table not available');
		}
		$this->appManager->method('isInstalled')->with('activity')->willReturn(true);

		$this->insertActivity('serverinfo_test_action', time() - 60);
		$this->insertActivity('serverinfo_test_action', time() - 120);

		$result = $this->instance->getActivityRate();

		$actions = array_column($result['topActions'], 'action');
		$this->assertContains('serverinfo_test_action', $actions);
		$entry = current(array_filter($result['topActions'], fn ($r) => $r['action'] === 'serverinfo_test_action'));
		$this->assertNotFalse($entry);
		$this->assertGreaterThanOrEqual(2, $entry['count']);
	}
}
