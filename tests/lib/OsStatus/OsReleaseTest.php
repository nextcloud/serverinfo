<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\OsRelease;

class OsReleaseTest extends \Test\TestCase {
	public function testParsesUbuntu(): void {
		$release = OsRelease::parse(FakeHost::fixture('ubuntu-26.04-os-release'));

		$this->assertNotNull($release);
		$this->assertSame('ubuntu', $release->id);
		$this->assertSame(['debian'], $release->idLike);
		$this->assertSame('Ubuntu 26.04.1 LTS', $release->prettyName);
		$this->assertSame('26.04', $release->versionId);
		$this->assertTrue($release->isLike('debian'));
		$this->assertFalse($release->isLike('rhel'));
	}

	public function testUnquotesAndUnescapes(): void {
		$release = OsRelease::parse(implode("\n", [
			'ID=fedora',
			'ID_LIKE="rhel centos"',
			'NAME=\'Fedora Linux\'',
			'PRETTY_NAME="Fedora \"Server\" 43"',
			'VERSION_ID=43',
			'# a comment',
			'garbage line',
		]));

		$this->assertNotNull($release);
		$this->assertSame(['rhel', 'centos'], $release->idLike);
		$this->assertSame('Fedora Linux', $release->name);
		$this->assertSame('Fedora "Server" 43', $release->prettyName);
		$this->assertNull($release->codename);
	}

	public function testRejectsAMissingOrUnsafeId(): void {
		$this->assertNull(OsRelease::parse('NAME=Something'));
		$this->assertNull(OsRelease::parse('ID="../../etc"'));
	}

	public function testBuildsAPrettyNameWhenMissing(): void {
		$release = OsRelease::parse("ID=alpine\nNAME=\"Alpine Linux\"\nVERSION=3.20");

		$this->assertSame('Alpine Linux 3.20', $release?->prettyName);
	}

	public function testRoundTrip(): void {
		$release = OsRelease::parse(FakeHost::fixture('ubuntu-26.04-os-release'));

		$this->assertEquals($release, OsRelease::fromArray($release?->toArray()));
		$this->assertNull(OsRelease::fromArray(['name' => 'no id']));
	}
}
