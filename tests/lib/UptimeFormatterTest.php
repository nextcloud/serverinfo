<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests;

use OCA\ServerInfo\UptimeFormatter;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;

class UptimeFormatterTest extends \Test\TestCase {
	private IL10N&MockObject $l10n;
	private UptimeFormatter $formatter;

	protected function setUp(): void {
		parent::setUp();

		$this->l10n = $this->createMock(IL10N::class);
		// Return the (untranslated) template string so assertions are independent
		// of translations and of the exact day/hour values computed from "now".
		$this->l10n->method('t')->willReturnArgument(0);

		$this->formatter = new UptimeFormatter($this->l10n);
	}

	public function testUnknownUptime(): void {
		$this->assertSame('Unknown', $this->formatter->format(-1));
	}

	public function testUptimeWithDays(): void {
		// 90000s = 1 day, 1 hour → days branch.
		$this->assertSame(
			'%1$d days, %2$d hours, %3$d minutes, %4$d seconds',
			$this->formatter->format(90000),
		);
	}

	public function testUptimeWithoutDays(): void {
		// 3600s = 1 hour → hours branch.
		$this->assertSame(
			'%1$d hours, %2$d minutes, %3$d seconds',
			$this->formatter->format(3600),
		);
	}
}
