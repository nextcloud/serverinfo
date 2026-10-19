<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Packages\Apk;
use OCA\ServerInfo\OsStatus\Packages\Pacman;

class PacmanApkTest extends \Test\TestCase {
	public function testCheckupdatesOutput(): void {
		$updates = Pacman::parseUpdates("linux 6.11.2.arch1-1 -> 6.11.3.arch1-1\nopenssl 3.3.2-1 -> 3.4.0-1\nnginx 1.26.2-1 -> 1.26.2-2 [ignored]\nnot an update\n");

		$this->assertSame(['linux', 'openssl'], array_map(static fn ($u) => $u->name, $updates), 'An ignored package is held, not pending');
		$this->assertSame('6.11.2.arch1-1', $updates[0]->installedVersion);
		$this->assertSame('6.11.3.arch1-1', $updates[0]->candidateVersion);
	}

	public function testArchAuditMarksSecurityUpdates(): void {
		$host = new FakeHost();
		$host->binaries = ['pacman', 'checkupdates', 'arch-audit'];
		$host->withCommand('checkupdates', [], "openssl 3.3.2-1 -> 3.4.0-1\nnano 8.1-1 -> 8.2-1\n")
			->withCommand('arch-audit', ['--upgradable', '--quiet'], "openssl\n");

		$pacman = new Pacman($host);
		$updates = $pacman->getPendingUpdates();

		$this->assertTrue($pacman->classifiesSecurity());
		$this->assertTrue($updates[0]->security);
		$this->assertFalse($updates[1]->security);
	}

	public function testCheckupdatesWithNothingToDo(): void {
		$host = new FakeHost();
		$host->binaries = ['pacman', 'checkupdates'];
		$host->withCommand('checkupdates', [], '', 2);

		$pacman = new Pacman($host);

		$this->assertFalse($pacman->classifiesSecurity());
		$this->assertSame([], $pacman->getPendingUpdates());
	}

	public function testIgnoredPackages(): void {
		$held = Pacman::parseIgnored("[options]\nIgnorePkg = linux nginx # pinned\n#IgnorePkg = commented\nIgnorePkg=php\n");

		$this->assertSame(['linux', 'nginx', 'php'], array_map(static fn ($h) => $h->name, $held));
	}

	public function testPacmanLog(): void {
		$host = new FakeHost();
		$host->withFile('/var/log/pacman.log', "[2026-10-12T06:41:02+0200] [ALPM] upgraded openssl (3.3.2-1 -> 3.4.0-1)\n[2026-10-12T07:00:00+0200] [ALPM] removed foo (1-1)\n");

		$this->assertSame((int)strtotime('2026-10-12 04:41:02 UTC'), (new Pacman($host))->getLastUpgradeAt());
	}

	public function testApkUpgradable(): void {
		$updates = Apk::parseUpgradable(implode("\n", [
			'busybox-1.37.0-r13 x86_64 {busybox} (GPL-2.0-only) [upgradable from: busybox-1.37.0-r12]',
			'py3-cryptography-44.0.0-r0 x86_64 {py3-cryptography} (Apache-2.0 OR BSD-3-Clause) [upgradable from: py3-cryptography-43.0.3-r0]',
			'nonsense',
		]));

		$this->assertSame('busybox', $updates[0]->name);
		$this->assertSame('1.37.0-r12', $updates[0]->installedVersion);
		$this->assertSame('py3-cryptography', $updates[1]->name);
		$this->assertSame('44.0.0-r0', $updates[1]->candidateVersion);
	}

	public function testApkWorldPins(): void {
		$host = new FakeHost();
		$host->withFile('/etc/apk/world', "alpine-base\nnginx=1.26.2-r0\nphp83<8.4\ncurl@edge\n");

		$held = (new Apk($host))->getHeldPackages();

		$this->assertSame(['nginx', 'php83', 'curl'], array_map(static fn ($h) => $h->name, $held ?? []));
		$this->assertSame('=1.26.2-r0', $held[0]->constraint);
	}
}
