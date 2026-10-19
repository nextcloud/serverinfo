<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Packages\Apt;
use OCA\ServerInfo\OsStatus\Packages\AutomaticUpdates;
use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;

/**
 * Output captured from real systems (arm64 containers, October 2026), run as
 * an unprivileged user unless named otherwise.
 */
class RealOutputTest extends \Test\TestCase {
	/**
	 * @param list<PendingUpdate> $updates
	 * @return array<string, bool> name => security
	 */
	private static function names(array $updates): array {
		$names = [];
		foreach ($updates as $update) {
			$names[$update->name] = $update->security;
		}

		return $names;
	}

	public function testDebian12(): void {
		$updates = Apt::parseSimulation(FakeHost::fixture('debian-12-apt-get-s-pending'));

		// tzdata is held, so the simulation leaves it out.
		$this->assertSame(['libssl3' => true, 'openssl' => true], self::names($updates));
		$this->assertSame('3.0.20-1~deb12u2', $updates[0]->installedVersion);
		$this->assertSame('3.0.22-1~deb12u1', $updates[0]->candidateVersion);
		$this->assertSame(AutomaticUpdates::SCOPE_ALL, Apt::unattendedScope(FakeHost::fixture('debian-12-apt-config-dump')));
	}

	public function testUbuntu2404(): void {
		$updates = Apt::parseSimulation(FakeHost::fixture('ubuntu-24.04-apt-get-s-pending'));

		$this->assertSame([
			'libc6' => true, 'libc-bin' => true, 'libssl3t64' => true, 'openssl' => true,
			'tzdata' => false, 'curl' => true, 'libcurl4t64' => true,
		], self::names($updates));
	}
}
