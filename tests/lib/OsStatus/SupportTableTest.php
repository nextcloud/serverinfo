<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;
use OCA\ServerInfo\OsStatus\Support\SupportTable;
use PHPUnit\Framework\Attributes\DataProvider;

class SupportTableTest extends \Test\TestCase {
	private SupportTable $table;

	protected function setUp(): void {
		parent::setUp();
		$this->table = new SupportTable();
	}

	private function release(string $id, ?string $version, string $name = 'X', array $like = []): OsRelease {
		return new OsRelease($id, $like, $name, $name . ' ' . $version, $version, null);
	}

	public static function dataKnownReleases(): array {
		return [
			'Ubuntu LTS with Pro' => ['ubuntu', '24.04', '2029-05-31', 'ubuntu-pro'],
			'Debian by major version' => ['debian', '12.7', null, 'debian-elts'],
			'RHEL by major version' => ['rhel', '9.4', null, 'rhel-els'],
			'Alma has no paid extension' => ['almalinux', '9.4', null, null],
			'Alpine by minor version' => ['alpine', '3.20.3', null, null],
			'Leap by minor version' => ['opensuse-leap', '15.6', null, null],
		];
	}

	#[DataProvider('dataKnownReleases')]
	public function testKnownReleases(string $id, string $version, ?string $securityEnds, ?string $programme): void {
		$support = $this->table->lookup($this->release($id, $version));

		$this->assertFalse($support->rolling);
		$this->assertNotNull($support->securityEnds);
		if ($securityEnds !== null) {
			$this->assertSame($securityEnds, $support->securityEnds);
		}
		$this->assertSame($programme, $support->extended);
		$this->assertNotNull($support->dataDate);
	}

	public function testCentosStreamIsToldApartFromCentosLinux(): void {
		$stream = $this->table->lookup($this->release('centos', '9', 'CentOS Stream'));
		$linux = $this->table->lookup($this->release('centos', '7', 'CentOS Linux'));

		$this->assertSame('2027-05-31', $stream->securityEnds);
		$this->assertSame('2024-06-30', $linux->securityEnds);
	}

	public function testRollingReleases(): void {
		$this->assertTrue($this->table->lookup($this->release('arch', null))->rolling);
		$this->assertTrue($this->table->lookup($this->release('opensuse-tumbleweed', '20261019'))->rolling);
		$this->assertTrue($this->table->lookup($this->release('manjaro', null, 'Manjaro', ['arch']))->rolling);
	}

	public function testUnknownReleases(): void {
		$this->assertSame(SupportStatus::STATE_UNKNOWN, $this->table->lookup($this->release('debian', null))->state(time()));
		$this->assertSame(SupportStatus::STATE_UNKNOWN, $this->table->lookup($this->release('linuxmint', '22', 'Mint', ['ubuntu']))->state(time()));
		$this->assertSame(SupportStatus::STATE_UNKNOWN, $this->table->lookup($this->release('ubuntu', '99.04'))->state(time()));
		$this->assertSame(SupportStatus::STATE_UNKNOWN, $this->table->lookup($this->release('raspbian', '12', 'Raspbian', ['debian']))->state(time()), 'Raspbian has its own archive');
		$this->assertSame(SupportStatus::STATE_UNKNOWN, $this->table->lookup($this->release('nixos', '25.05', 'NixOS'))->state(time()));
	}

	public function testAMissingTableKnowsNothing(): void {
		$table = new SupportTable('/nonexistent/os-support.json');

		$this->assertSame(SupportStatus::STATE_UNKNOWN, $table->lookup($this->release('ubuntu', '24.04'))->state(time()));
	}

	public function testStates(): void {
		$at = static fn (string $day): int => (int)strtotime($day . ' 12:00:00 UTC');
		$support = new SupportStatus(false, '2029-05-31', '2034-04-25', 'ubuntu-pro');

		$this->assertSame(SupportStatus::STATE_SUPPORTED, $support->state($at('2029-05-31')));
		$this->assertSame(SupportStatus::STATE_EXTENDED_AVAILABLE, $support->state($at('2029-06-01')));
		$this->assertSame(SupportStatus::STATE_EXTENDED, $support->withExtendedActive(true)->state($at('2029-06-01')));
		$this->assertSame(SupportStatus::STATE_ENDED, $support->withExtendedActive(true)->state($at('2034-04-26')));
		$this->assertSame(SupportStatus::STATE_ROLLING, (new SupportStatus(true, null))->state($at('2029-01-01')));
	}

	public function testDaysLeftCountToTheEffectiveEnd(): void {
		$now = (int)strtotime('2029-05-21 12:00:00 UTC');
		$support = new SupportStatus(false, '2029-05-31', '2034-04-25', 'ubuntu-pro');

		$this->assertSame(10, $support->daysLeft($now));
		$this->assertSame('2034-04-25', $support->withExtendedActive(true)->effectiveEnd());
		$this->assertSame(-1, $support->daysLeft((int)strtotime('2029-06-01 12:00:00 UTC')));
	}

	public function testFromArrayIgnoresGarbage(): void {
		$support = SupportStatus::fromArray(['securityEnds' => 'soon', 'extended' => 'free-forever', 'extendedActive' => 'yes']);

		$this->assertNull($support->securityEnds);
		$this->assertNull($support->extended);
		$this->assertNull($support->extendedActive);
	}
}
