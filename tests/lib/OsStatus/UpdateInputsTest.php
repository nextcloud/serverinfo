<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Packages\Apk;
use OCA\ServerInfo\OsStatus\Packages\Apt;
use OCA\ServerInfo\OsStatus\Packages\Dnf;
use OCA\ServerInfo\OsStatus\Packages\Pacman;
use OCA\ServerInfo\OsStatus\Packages\ProbeHelper;
use OCA\ServerInfo\OsStatus\Packages\Rpm;
use OCA\ServerInfo\OsStatus\Packages\Zypper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;

class UpdateInputsTest extends \Test\TestCase {
	private const NOW = 1792411200;

	public function testFingerprintHoldsEveryModificationTime(): void {
		$host = new FakeHost();
		$host->modified['/var/lib/dpkg/status'] = self::NOW - 60;
		$host->modified['/etc/apt/sources.list.d'] = self::NOW - 600;
		$host->modified['/etc/apt/sources.list.d/a.sources'] = self::NOW - 700;
		$host->modified['/etc/apt/sources.list.d/b.sources'] = self::NOW - 800;
		$host->globs['/etc/apt/sources.list.d/*'] = ['/etc/apt/sources.list.d/a.sources', '/etc/apt/sources.list.d/b.sources'];

		$this->assertSame([
			'/var/lib/dpkg/status' => self::NOW - 60,
			'/etc/apt/sources.list.d' => self::NOW - 600,
			'/etc/apt/sources.list.d/a.sources' => self::NOW - 700,
			'/etc/apt/sources.list.d/b.sources' => self::NOW - 800,
		], ProbeHelper::fingerprint($host, ['/var/lib/dpkg/status', '/etc/apt/sources.list.d', '/etc/apt/sources.list.d/*']));
	}

	public function testAMissingFileInAVisibleDirectoryCountsAsAbsent(): void {
		$host = new FakeHost();
		$host->modified['/etc/apt'] = self::NOW - 600;

		$this->assertSame(['/etc/apt/preferences' => 0], ProbeHelper::fingerprint($host, ['/etc/apt/preferences']));
	}

	public function testAPathThatCannotBeCheckedLeavesNoFingerprint(): void {
		$host = new FakeHost();
		$host->modified['/var/lib/dpkg/status'] = self::NOW - 60;

		$this->assertNull(ProbeHelper::fingerprint($host, ['/var/lib/dpkg/status', '/var/lib/apt/lists']), 'Its directory is hidden too');

		$host->files['/var/lib/apt/lists'] = '';
		$host->modified['/var/lib'] = self::NOW;
		$this->assertNull(ProbeHelper::fingerprint($host, ['/var/lib/dpkg/status', '/var/lib/apt/lists']), 'It exists, but its time is unknown');
	}

	public function testTooManyMatchesLeaveNoFingerprint(): void {
		$host = new FakeHost();
		$files = array_map(static fn (int $i): string => '/var/cache/dnf/repo' . $i . '/repodata/repomd.xml', range(1, 513));
		$host->globs['/var/cache/dnf/*/repodata/repomd.xml'] = $files;
		foreach ($files as $file) {
			$host->modified[$file] = self::NOW;
		}

		$this->assertNull(ProbeHelper::fingerprint($host, ['/var/cache/dnf/*/repodata/repomd.xml']));
	}

	public function testAptWatchesTheDpkgDatabaseTheListsAndItsConfiguration(): void {
		$inputs = (new Apt(new FakeHost(), new SystemdProbe(new FakeHost())))->getUpdateInputs() ?? [];

		foreach (['/var/lib/dpkg/status', '/var/lib/apt/lists', '/etc/apt/sources.list.d', '/etc/apt/preferences.d', '/etc/apt/apt.conf.d'] as $path) {
			$this->assertContains($path, $inputs);
		}
	}

	public function testRpmBasedManagersWatchTheRpmDatabaseAndTheirCache(): void {
		$host = new FakeHost();
		$host->modified['/var/lib/rpm/rpmdb.sqlite'] = self::NOW;
		$rpm = new Rpm($host);
		$systemd = new SystemdProbe($host);

		$dnf = (new Dnf($host, $rpm, $systemd))->getUpdateInputs() ?? [];
		$zypper = (new Zypper($host, $rpm, $systemd))->getUpdateInputs() ?? [];

		foreach (['/var/lib/rpm/rpmdb.sqlite', '/var/lib/rpm', '/var/cache/dnf', '/var/cache/dnf/*/repodata/repomd.xml', '/etc/yum.repos.d', '/etc/dnf/plugins/versionlock.list'] as $path) {
			$this->assertContains($path, $dnf);
		}
		foreach (['/var/lib/rpm/rpmdb.sqlite', '/var/lib/rpm', '/var/cache/zypp/solv', '/etc/zypp/repos.d', '/etc/zypp/locks'] as $path) {
			$this->assertContains($path, $zypper);
		}
	}

	public function testWithoutAnRpmDatabaseNothingIsWatched(): void {
		$host = new FakeHost();
		$rpm = new Rpm($host);
		$systemd = new SystemdProbe($host);

		$this->assertNull((new Dnf($host, $rpm, $systemd))->getUpdateInputs());
		$this->assertNull((new Zypper($host, $rpm, $systemd))->getUpdateInputs());
	}

	public function testApkWatchesTheInstalledDatabaseTheIndexesAndTheWorld(): void {
		$inputs = (new Apk(new FakeHost()))->getUpdateInputs() ?? [];

		foreach (['/lib/apk/db/installed', '/var/cache/apk', '/var/cache/apk/APKINDEX.*.tar.gz', '/etc/apk/repositories', '/etc/apk/world'] as $path) {
			$this->assertContains($path, $inputs);
		}
	}

	public static function dataPacman(): array {
		return [
			'checkupdates online' => [true, ['checkupdates'], false],
			'arch-audit online' => [true, ['arch-audit'], false],
			'neither installed' => [true, [], true],
			'offline' => [false, ['checkupdates', 'arch-audit'], true],
		];
	}

	/**
	 * @param list<string> $binaries
	 */
	#[DataProvider('dataPacman')]
	public function testPacmanIsWatchedOnlyWhenItAnswersFromLocalFiles(bool $network, array $binaries, bool $watched): void {
		$host = new FakeHost();
		$host->binaries = ['pacman', ...$binaries];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueBool')->willReturnMap([[ConfigLexicon::OS_ALLOW_NETWORK, true, true, $network]]);

		$inputs = (new Pacman($host, $this->createMock(ITimeFactory::class), $appConfig))->getUpdateInputs();

		if ($watched) {
			$this->assertSame(['/var/lib/pacman/local', '/var/lib/pacman/sync', '/var/lib/pacman/sync/*.db', '/etc/pacman.conf'], $inputs);
		} else {
			$this->assertNull($inputs);
		}
	}
}
