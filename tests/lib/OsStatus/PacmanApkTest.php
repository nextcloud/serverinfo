<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Packages\Apk;
use OCA\ServerInfo\OsStatus\Packages\Pacman;
use OCA\ServerInfo\OsStatus\Packages\ProbeException;
use OCP\AppFramework\Utility\ITimeFactory;

class PacmanApkTest extends \Test\TestCase {
	private const NOW = 1792411200;

	private function pacman(FakeHost $host): Pacman {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new Pacman($host, $time);
	}

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

		$pacman = $this->pacman($host);
		$updates = $pacman->getPendingUpdates();

		$this->assertTrue($pacman->classifiesSecurity());
		$this->assertTrue($updates[0]->security);
		$this->assertFalse($updates[1]->security);
	}

	public function testCheckupdatesWithNothingToDo(): void {
		$host = new FakeHost();
		$host->binaries = ['pacman', 'checkupdates'];
		$host->withCommand('checkupdates', [], '', 2);

		$pacman = $this->pacman($host);

		$this->assertFalse($pacman->classifiesSecurity());
		$this->assertSame([], $pacman->getPendingUpdates());
	}

	public function testCheckupdatesSyncIsTheListsAge(): void {
		$host = new FakeHost();
		$host->binaries = ['pacman', 'checkupdates'];
		$host->withCommand('checkupdates', [], '', 2);
		$host->modified['/var/lib/pacman/sync'] = self::NOW - 90 * 86400;

		$pacman = $this->pacman($host);
		$pacman->getPendingUpdates();

		$this->assertSame(self::NOW, $pacman->getListsRefreshedAt());
		$this->assertSame([], $pacman->getDiagnostics());
	}

	public function testFallbackReportsTheAgeOfRootsDatabases(): void {
		$host = new FakeHost();
		$host->binaries = ['pacman', 'checkupdates'];
		$host->withCommand('checkupdates', [], '', 1, "==> ERROR: Cannot fetch updates\n")
			->withCommand('pacman', ['-Qu'], "openssl 3.3.2-1 -> 3.4.0-1\n");
		$host->globs['/var/lib/pacman/sync/*.db'] = ['/var/lib/pacman/sync/core.db', '/var/lib/pacman/sync/extra.db'];
		$host->modified['/var/lib/pacman/sync/core.db'] = self::NOW - 40 * 86400;
		$host->modified['/var/lib/pacman/sync/extra.db'] = self::NOW - 35 * 86400;
		$host->modified['/var/lib/pacman/sync'] = self::NOW - 34 * 86400;

		$pacman = $this->pacman($host);

		$this->assertCount(1, $pacman->getPendingUpdates());
		$this->assertSame(self::NOW - 34 * 86400, $pacman->getListsRefreshedAt());
		$this->assertSame(['checkupdates could not sync the package databases, so pending updates come from the databases root last synced: ==> ERROR: Cannot fetch updates'], $pacman->getDiagnostics());
	}

	public function testPacmanQuWithNothingToDo(): void {
		$host = new FakeHost();
		$host->binaries = ['pacman'];
		$host->withCommand('pacman', ['-Qu'], '', 1);

		$this->assertSame([], $this->pacman($host)->getPendingUpdates());
	}

	/**
	 * @return array<string, array{int, string, string}>
	 */
	public static function failedQueries(): array {
		return [
			'error on stderr only' => [1, '', "error: failed to initialize alpm library:\n(root: /, dbpath: /var/lib/pacman/)\ncould not find or read directory\n"],
			'database lock' => [1, '', "error: could not lock database: File exists\n"],
			'other exit code' => [2, '', ''],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('failedQueries')]
	public function testPacmanQuErrorsAreNotUpToDate(int $exitCode, string $stdout, string $stderr): void {
		$host = new FakeHost();
		$host->binaries = ['pacman'];
		$host->withCommand('pacman', ['-Qu'], $stdout, $exitCode, $stderr);

		$this->expectException(ProbeException::class);
		$this->expectExceptionMessage('pacman -Qu failed');
		$this->pacman($host)->getPendingUpdates();
	}

	public function testArchAuditFailureKeepsTheUpdatesUnclassified(): void {
		$host = new FakeHost();
		$host->binaries = ['pacman', 'checkupdates', 'arch-audit'];
		$host->withCommand('checkupdates', [], "openssl 3.3.2-1 -> 3.4.0-1\nnano 8.1-1 -> 8.2-1\n")
			->withCommand('arch-audit', ['--upgradable', '--quiet'], '', 1, "Error: Failed to fetch https://security.archlinux.org/issues/all.json\n");

		$pacman = $this->pacman($host);
		$updates = $pacman->getPendingUpdates();

		$this->assertSame(['openssl', 'nano'], array_map(static fn ($u) => $u->name, $updates));
		$this->assertFalse($updates[0]->security);
		$this->assertFalse($pacman->classifiesSecurity());
		$this->assertSame(['arch-audit could not be run, so pending updates are not told apart into security fixes and others: Error: Failed to fetch https://security.archlinux.org/issues/all.json'], $pacman->getDiagnostics());
	}

	public function testIgnoredPackages(): void {
		$held = Pacman::parseIgnored("[options]\nIgnorePkg = linux nginx # pinned\n#IgnorePkg = commented\nIgnorePkg=php\n");

		$this->assertSame(['linux', 'nginx', 'php'], array_map(static fn ($h) => $h->name, $held));
	}

	public function testPacmanLog(): void {
		$host = new FakeHost();
		$host->withFile('/var/log/pacman.log', "[2026-10-12T06:41:02+0200] [ALPM] upgraded openssl (3.3.2-1 -> 3.4.0-1)\n[2026-10-12T07:00:00+0200] [ALPM] removed foo (1-1)\n");

		$this->assertSame((int)strtotime('2026-10-12 04:41:02 UTC'), ($this->pacman($host))->getLastUpgradeAt());
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
