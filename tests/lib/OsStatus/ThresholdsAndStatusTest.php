<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Environment;
use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\Packages\AutomaticUpdates;
use OCA\ServerInfo\OsStatus\Packages\HeldPackage;
use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;

class ThresholdsAndStatusTest extends \Test\TestCase {
	public function testStatusRoundTrip(): void {
		$status = new OsStatus(
			OsStatus::STATE_OK,
			1792411200,
			OsRelease::parse(FakeHost::fixture('ubuntu-26.04-os-release')),
			'7.0.0-38-generic',
			'aarch64',
			new Environment(Environment::KIND_HOST, null, 'vmware'),
			new SupportStatus(false, '2031-05-29', '2036-04-23', 'ubuntu-pro', false, null, '2026-10-19'),
			'apt',
			[new PendingUpdate('openssl', '3.0.13-1', '3.0.13-2', true, 'noble-security', ['USN-1'], null, 1792000000)],
			OsStatus::SOURCE_SYSTEM,
			true,
			3,
			[new HeldPackage('mariadb-server', 'apt-mark hold')],
			1792400000,
			1792300000,
			new AutomaticUpdates(AutomaticUpdates::STATE_ENABLED, 'unattended-upgrades', AutomaticUpdates::SCOPE_SECURITY),
			[new RebootReason(RebootReason::KIND_KERNEL, '7.0.0-40-generic', [], 1792100000)],
			[],
			['logrotate.service'],
			true,
			'timedatectl',
			'disabled',
			'enabled',
			['all' => 'sudo apt update && sudo apt full-upgrade', 'security' => null],
			'/var/lib/dpkg/status',
			1792000000,
			['diagnostic'],
		);

		$copy = OsStatus::fromArray(json_decode((string)json_encode($status->toArray()), true));

		$this->assertEquals($status, $copy);
	}

	public function testDamagedStatusReadsAsUnknown(): void {
		$this->assertNull(OsStatus::fromArray('garbage'));
		$this->assertNull(OsStatus::fromArray(['schema' => 99, 'collectedAt' => 1]));

		$status = OsStatus::fromArray(['schema' => 1, 'collectedAt' => 5, 'updates' => 'lots', 'rebootReasons' => [['kind' => 'nope']], 'environment' => ['kind' => 'moon']]);

		$this->assertNotNull($status);
		$this->assertNull($status->updates);
		$this->assertSame([], $status->rebootReasons);
		$this->assertSame(Environment::KIND_HOST, $status->environment->kind);
	}

	public function testStackPackagesAreMarked(): void {
		$this->assertTrue((new PendingUpdate('php8.3-fpm', null, '1', false))->toArray()['stack']);
		$this->assertTrue((new PendingUpdate('libapache2-mod-php8.3', null, '1', false))->toArray()['stack']);
		$this->assertTrue((new PendingUpdate('openssl-libs', null, '1', false))->toArray()['stack']);
		$this->assertFalse((new PendingUpdate('vim', null, '1', false))->toArray()['stack']);
	}
}
