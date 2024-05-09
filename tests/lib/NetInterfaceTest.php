<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\Resources\NetInterface;
use Test\TestCase;

class NetInterfaceTest extends TestCase {
	public function testIsLoopback4(): void {
		$net = new NetInterface('eth0', true);
		$net->addIPv4('127.0.0.1');
		$this->assertTrue($net->isLoopback());
	}

	public function testIsLoopback6(): void {
		$net = new NetInterface('eth0', true);
		$net->addIPv6('::1');
		$this->assertTrue($net->isLoopback());
	}
}
