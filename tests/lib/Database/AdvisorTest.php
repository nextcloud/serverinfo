<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Database;

use OCA\ServerInfo\Database\Advisor;
use OCA\ServerInfo\Database\ExpressionEvaluator;
use OCA\ServerInfo\Database\RuleResult;
use OCA\ServerInfo\Database\RuleSet\Mysql;
use OCA\ServerInfo\Database\Snapshot;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class AdvisorTest extends \Test\TestCase {
	private LoggerInterface&MockObject $logger;
	private Advisor $advisor;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->advisor = new Advisor(new ExpressionEvaluator(), $this->logger);
	}

	/**
	 * @param array<string, scalar|null> $status
	 * @param array<string, scalar|null> $derived
	 */
	private function resultFor(string $ruleId, array $status, array $derived = []): RuleResult {
		// Well past Advisor::MIN_UPTIME_HOURS so the uptime gate never
		// masks what a given test is actually asserting.
		$status += ['Uptime' => 864000];
		$derived += [
			'value' => 0.0,
			'Uptime_hours' => (float)$status['Uptime'] / 3600.0,
			'Uptime_days' => (float)$status['Uptime'] / 86400.0,
		];

		$snapshot = new Snapshot(Snapshot::FLAVOUR_MARIADB, '11.8.8-MariaDB', $status, [], $derived);

		foreach ($this->advisor->run(new Mysql(), $snapshot) as $result) {
			if ($result->rule->id === $ruleId) {
				return $result;
			}
		}

		$this->fail("Rule '$ruleId' was not evaluated");
	}

	/**
	 * A standalone server reports Slave_running = OFF simply because no
	 * replication was ever set up.  Without a replica the rule has nothing
	 * to say, so it must be skipped rather than raising an alert.
	 */
	public function testReplicaLagSkippedOnStandaloneServer(): void {
		$result = $this->resultFor('Slave_lag', ['Slave_running' => 'OFF']);

		$this->assertSame(RuleResult::STATUS_SKIPPED, $result->status);
	}

	/**
	 * Enabling the binary log does not make a server a replica; the rule
	 * must stay silent for a primary that simply writes binlogs.
	 */
	public function testReplicaLagSkippedOnPrimaryWithBinlogEnabled(): void {
		$result = $this->resultFor('Slave_lag', [
			'Slave_running' => 'OFF',
			'Binlog_commits' => 4242,
		]);

		$this->assertSame(RuleResult::STATUS_SKIPPED, $result->status);
	}

	/**
	 * On an actual replica whose threads have stopped, the alert is correct
	 * and must still fire.
	 */
	public function testReplicaLagFailsOnStoppedReplica(): void {
		$result = $this->resultFor(
			'Slave_lag',
			['Slave_running' => 'OFF'],
			['Replica_configured' => 1.0],
		);

		$this->assertSame(RuleResult::STATUS_FAIL, $result->status);
	}

	/**
	 * A healthy replica must not be flagged.
	 */
	public function testReplicaLagPassesOnRunningReplica(): void {
		$result = $this->resultFor(
			'Slave_lag',
			['Slave_running' => 'ON'],
			['Replica_configured' => 1.0],
		);

		$this->assertSame(RuleResult::STATUS_OK, $result->status);
	}
}
