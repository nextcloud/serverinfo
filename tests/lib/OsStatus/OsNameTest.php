<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\Os;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class OsNameTest extends \Test\TestCase {
	private function osName(FakeHost $host, bool $restricted = false): string {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn($restricted ? 'yes' : 'no');

		return (new Os($config, $this->createMock(IAppConfig::class), $host, $this->createMock(LoggerInterface::class)))->getOSName();
	}

	public function testNamesTheDistributionThroughTheHost(): void {
		$host = (new FakeHost())->withFile('/etc/os-release', FakeHost::fixture('ubuntu-24.04-os-release'));

		$this->assertStringStartsWith('Ubuntu 24.04.3 LTS (', $this->osName($host));
	}

	public function testFallsBackToTheVendorFile(): void {
		$host = (new FakeHost())->withFile('/usr/lib/os-release', FakeHost::fixture('tumbleweed-os-release'));

		$this->assertStringStartsWith('openSUSE Tumbleweed (', $this->osName($host));
	}

	public function testOnlyTheKernelWithoutADistribution(): void {
		$kernel = PHP_OS . ' ' . php_uname('r') . ' ' . php_uname('m');
		$withRelease = (new FakeHost())->withFile('/etc/os-release', FakeHost::fixture('ubuntu-24.04-os-release'));

		$this->assertSame($kernel, $this->osName(new FakeHost()));
		$this->assertSame($kernel, $this->osName($withRelease, restricted: true));
		$withRelease->family = 'BSD';
		$this->assertSame($kernel, $this->osName($withRelease));
	}
}
