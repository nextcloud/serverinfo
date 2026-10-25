<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\Php;

use OCA\ServerInfo\Controller\PhpController;
use OCA\ServerInfo\Database\Advisor;
use OCA\ServerInfo\Database\ExpressionEvaluator;
use OCA\ServerInfo\Database\Snapshot;
use OCA\ServerInfo\Php\PhpProbe;
use OCA\ServerInfo\Php\PhpRuleSet;
use OCA\ServerInfo\Tests\OsStatus\FakeL10N;
use OCP\IRequest;
use OCP\ISession;
use Psr\Log\LoggerInterface;

class PhpControllerTest extends \Test\TestCase {
	public function testCheckReleasesTheSessionAndReturnsEveryRule(): void {
		$session = $this->createMock(ISession::class);
		$session->expects($this->once())->method('close');
		$probe = $this->createMock(PhpProbe::class);
		$probe->method('snapshot')->willReturn(new Snapshot(
			Snapshot::FLAVOUR_PHP,
			'8.4.0',
			[],
			[],
			['php_memory_limit_mb' => 256.0],
			['fpm_pool' => ['www']],
		));

		$controller = new PhpController(
			'serverinfo',
			$this->createMock(IRequest::class),
			$session,
			$probe,
			new PhpRuleSet(new FakeL10N()),
			new Advisor(new ExpressionEvaluator(), $this->createMock(LoggerInterface::class)),
		);

		$data = $controller->check()->getData();

		$this->assertSame('www', $data['pool']);
		$this->assertSame(PHP_SAPI, $data['sapi']);
		$this->assertCount(9, $data['results']);
		$byId = array_column($data['results'], 'status', 'id');
		$this->assertSame('fail', $byId['php.memory_limit']);
		$this->assertSame('skipped', $byId['php.fpm_max_children']);
	}
}
