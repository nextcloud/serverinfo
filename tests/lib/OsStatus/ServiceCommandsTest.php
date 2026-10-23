<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Restart\ServiceCommands;

class ServiceCommandsTest extends \Test\TestCase {
	private function initSystem(FakeHost $host): ?string {
		return (new ServiceCommands(new SystemdProbe($host), $host))->initSystem();
	}

	public function testDetectsTheInitSystem(): void {
		$this->assertSame(ServiceCommands::SYSTEMD, $this->initSystem((new FakeHost())->withFile('/run/systemd/system', '')));
		$this->assertSame(ServiceCommands::OPENRC, $this->initSystem((new FakeHost())->withFile('/run/openrc', '')));
		$this->assertNull($this->initSystem(new FakeHost()));
	}

	public function testRestartCommands(): void {
		$this->assertSame('sudo systemctl restart php8.3-fpm', ServiceCommands::restart(ServiceCommands::SYSTEMD, 'php8.3-fpm.service'));
		$this->assertSame('sudo systemctl restart nextcloud-notify_push.socket', ServiceCommands::restart(ServiceCommands::SYSTEMD, 'nextcloud-notify_push.socket'));
		$this->assertSame("sudo systemctl restart 'foo\\x2dbar'", ServiceCommands::restart(ServiceCommands::SYSTEMD, 'foo\\x2dbar.service'));
		$this->assertSame('sudo rc-service php-fpm83 restart', ServiceCommands::restart(ServiceCommands::OPENRC, 'php-fpm83'));
		$this->assertNull(ServiceCommands::restart(null, 'apache2'));
	}

	public function testRebootCommands(): void {
		$this->assertSame('sudo systemctl reboot', ServiceCommands::reboot(ServiceCommands::SYSTEMD));
		$this->assertSame('sudo reboot', ServiceCommands::reboot(ServiceCommands::OPENRC));
		$this->assertNull(ServiceCommands::reboot(null));
	}

	public function testQuotes(): void {
		$this->assertSame('/var/www/x', ServiceCommands::quote('/var/www/x'));
		$this->assertSame("'my unit'", ServiceCommands::quote('my unit'));
		$this->assertSame("'it'\\''s'", ServiceCommands::quote("it's"));
	}
}
