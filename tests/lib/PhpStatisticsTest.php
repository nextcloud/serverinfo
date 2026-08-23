<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use bantu\IniGetWrapper\IniGetWrapper;
use OCA\ServerInfo\PhpStatistics;

/**
 * getPhpStatistics() is the OCS `/ocs/v2.php/apps/serverinfo/api/v1/info`
 * payload, parsed by external monitoring. This guards its shape from being
 * "cleaned up" into the dashboard's collector-shaped payload.
 */
class PhpStatisticsTest extends \Test\TestCase {
	public function testKeepsTheOcsContractKeys(): void {
		$iniGetWrapper = $this->createMock(IniGetWrapper::class);
		$instance = new PhpStatistics($iniGetWrapper);

		$stats = $instance->getPhpStatistics();

		$this->assertArrayHasKey('opcache', $stats);
		$this->assertArrayHasKey('apcu', $stats);
		$this->assertArrayHasKey('extensions', $stats);
		$this->assertArrayHasKey('opcache_revalidate_freq', $stats);
	}
}
