<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Host\CommandResult;
use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\Packages\Apk;
use OCA\ServerInfo\OsStatus\Packages\Apt;
use OCA\ServerInfo\OsStatus\Packages\Dnf;
use OCA\ServerInfo\OsStatus\Packages\IPackageManager;
use OCA\ServerInfo\OsStatus\Packages\NeedsRootException;
use OCA\ServerInfo\OsStatus\Packages\PackageManagerFactory;
use OCA\ServerInfo\OsStatus\Packages\Pacman;
use OCA\ServerInfo\OsStatus\Packages\Rpm;
use OCA\ServerInfo\OsStatus\Packages\Zypper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;

class PackageManagerFactoryTest extends \Test\TestCase {
	private FakeHost $host;

	protected function setUp(): void {
		parent::setUp();
		$this->host = new FakeHost();
		$this->host->globs['/var/cache/dnf/*-updateinfo.solvx'] = ['/var/cache/dnf/baseos-updateinfo.solvx'];
	}

	/**
	 * @param list<string> $binaries
	 */
	private function managerFor(string $osRelease, array $binaries): ?IPackageManager {
		$this->host->binaries = $binaries;
		$release = OsRelease::parse($osRelease);
		$this->assertNotNull($release);
		$host = $this->host;
		$systemd = new SystemdProbe($host);
		$rpm = new Rpm($host);
		$factory = new PackageManagerFactory(
			new Apt($host, $systemd),
			new Dnf($host, $rpm, $systemd),
			new Zypper($host, $rpm, $systemd),
			new Pacman($host, $this->createMock(ITimeFactory::class), $this->createMock(IAppConfig::class)),
			new Apk($host),
		);

		return $factory->forRelease($release);
	}

	/**
	 * @return array<string, array{string, list<string>, ?string, ?bool}>
	 */
	public static function systems(): array {
		return [
			'Debian 12' => ["ID=debian\nVERSION_ID=\"12\"\n", ['apt-get'], 'apt', true],
			'RHEL 9 with dnf 4' => ["ID=\"rhel\"\nID_LIKE=\"fedora\"\nVERSION_ID=\"9.6\"\n", ['dnf', 'dnf-3', 'yum'], 'dnf', true],
			'Rocky Linux 9' => ["ID=\"rocky\"\nID_LIKE=\"rhel centos fedora\"\nVERSION_ID=\"9.6\"\n", ['dnf', 'dnf-3'], 'dnf', true],
			'Oracle Linux 9' => ["ID=\"ol\"\nID_LIKE=\"fedora\"\nVERSION_ID=\"9.6\"\n", ['dnf', 'dnf-3'], 'dnf', true],
			'Amazon Linux 2023' => ["ID=\"amzn\"\nID_LIKE=\"fedora\"\nVERSION_ID=\"2023\"\n", ['dnf', 'dnf-3'], 'dnf', true],
			'Amazon Linux 2 has only yum' => ["ID=\"amzn\"\nID_LIKE=\"centos rhel fedora\"\nVERSION_ID=\"2\"\n", ['yum'], null, null],
			'CentOS Stream 10' => ["ID=\"centos\"\nID_LIKE=\"rhel fedora\"\nVERSION_ID=\"10\"\n", ['dnf', 'dnf-3'], 'dnf', false],
			'Fedora with dnf 5' => ["ID=fedora\nVERSION_ID=44\n", ['dnf', 'dnf5'], 'dnf', true],
			'openSUSE Leap 16' => ["ID=\"opensuse-leap\"\nID_LIKE=\"suse opensuse\"\nVERSION_ID=\"16.0\"\n", ['zypper'], 'zypper', true],
			'openSUSE Tumbleweed' => ["ID=\"opensuse-tumbleweed\"\nID_LIKE=\"opensuse suse\"\n", ['zypper'], 'zypper', false],
			'SLES 15' => ["ID=\"sles\"\nID_LIKE=\"suse\"\nVERSION_ID=\"15.6\"\n", ['zypper'], 'zypper', true],
			'Arch Linux' => ["ID=arch\n", ['pacman'], 'pacman', false],
			'Manjaro' => ["ID=manjaro\nID_LIKE=arch\n", ['pacman'], 'pacman', false],
			'Alpine' => ["ID=alpine\nVERSION_ID=3.24.2\n", ['apk'], 'apk', false],
			'postmarketOS' => ["ID=\"postmarketos\"\nID_LIKE=\"alpine\"\nVERSION_ID=\"v25.06\"\n", ['apk'], 'apk', false],
			'Gentoo' => ["ID=gentoo\n", ['emerge'], null, null],
			'Debian without apt-get' => ["ID=debian\n", [], null, null],
		];
	}

	/**
	 * @param list<string> $binaries
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('systems')]
	public function testDispatch(string $osRelease, array $binaries, ?string $expected, ?bool $classifies): void {
		$manager = $this->managerFor($osRelease, $binaries);

		$this->assertSame($expected, $manager?->getId());
		$this->assertSame($classifies, $manager?->classifiesSecurity());
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function hiddenByOpenBasedir(): array {
		return [
			'openSUSE Leap 16' => ["ID=\"opensuse-leap\"\nID_LIKE=\"suse opensuse\"\nVERSION_ID=\"16.0\"\n", 'zypper', 'zypper'],
			'Arch Linux' => ["ID=arch\n", 'pacman', 'pacman'],
			'Alpine' => ["ID=alpine\nVERSION_ID=3.24.2\n", 'apk', 'apk'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('hiddenByOpenBasedir')]
	public function testTriesAPackageManagerOpenBasedirHides(string $osRelease, string $binary, string $expected): void {
		$this->host->hiddenBinaries = [$binary];

		$this->assertSame($expected, $this->managerFor($osRelease, [])?->getId());
	}

	public function testDnfNamesATimeout(): void {
		$manager = $this->managerFor("ID=\"rhel\"\nID_LIKE=\"fedora\"\nVERSION_ID=\"9.6\"\n", ['dnf', 'dnf-3']);
		$this->host->commands['dnf-3 -q --cacheonly check-update'] = new CommandResult(-1, "Last metadata expiration check\n", '', true);

		$this->expectExceptionMessage('dnf check-update failed: timed out after 180 s');
		$manager?->getPendingUpdates();
	}

	public function testRhel9RunsDnf4AsDnf3(): void {
		$this->host->withCommand('dnf-3', ['-q', '--cacheonly', 'check-update'], '', 0);
		$manager = $this->managerFor("ID=\"rhel\"\nID_LIKE=\"fedora\"\nVERSION_ID=\"9.6\"\n", ['dnf', 'dnf-3']);

		$this->assertSame([], $manager?->getPendingUpdates());
		$this->assertSame(['dnf-3 -q --cacheonly check-update'], $this->host->ran);
	}

	public function testFedoraWithDnf5RunsNothing(): void {
		$manager = $this->managerFor("ID=fedora\nVERSION_ID=44\n", ['dnf', 'dnf5']);

		try {
			$manager?->getPendingUpdates();
			$this->fail('dnf 5 must not be asked');
		} catch (NeedsRootException) {
		}
		$this->assertSame([], $this->host->ran);
	}
}
