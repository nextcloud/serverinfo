<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\ConcernTexts;
use OCP\IDateTimeFormatter;
use OCP\IL10N;

class ConcernTextsTest extends \Test\TestCase {
	private ConcernTexts $texts;
	private IL10N $l;
	private OsStatus $status;

	protected function setUp(): void {
		parent::setUp();
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDate')->willReturnCallback(static fn (int $time): string => gmdate('Y-m-d', $time));
		$this->texts = new ConcernTexts($formatter);

		$this->l = $this->createMock(IL10N::class);
		$replace = static function (string $text, array $parameters): string {
			foreach ($parameters as $key => $value) {
				$text = str_replace('{' . $key . '}', (string)$value, $text);
			}

			return $text;
		};
		$this->l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => $replace($text, $parameters));
		$this->l->method('n')->willReturnCallback(static fn (string $one, string $many, int $count, array $parameters = []): string => str_replace('%n', (string)$count, $replace($count === 1 ? $one : $many, $parameters)));

		$this->status = new OsStatus(OsStatus::STATE_OK, 1, new OsRelease('ubuntu', ['debian'], 'Ubuntu', 'Ubuntu 22.04.5 LTS', '22.04', 'jammy'));
	}

	public function testUpdates(): void {
		$concern = new Concern(Concern::UPDATES, Concern::LEVEL_WARNING, ['classified' => true, 'security' => 7, 'other' => 2, 'stack' => 3, 'days' => 5]);

		$this->assertSame('7 security updates pending', $this->texts->title($concern, $this->status, $this->l));
		$this->assertSame(
			'3 of them update software Nextcloud runs on, such as PHP or the database. The oldest has been waiting for 5 days.',
			$this->texts->detail($concern, $this->l),
		);
	}

	public function testUpdatesFromOutdatedPackageLists(): void {
		$concern = new Concern(Concern::UPDATES, Concern::LEVEL_OK, ['classified' => true, 'security' => 0, 'other' => 0, 'listsStale' => true]);

		$this->assertSame('No security updates pending', $this->texts->title($concern, $this->status, $this->l));
		$this->assertSame('The package lists are outdated, so newer updates may be missing from this count.', $this->texts->detail($concern, $this->l));
	}

	public function testUnclassifiedUpdates(): void {
		$concern = new Concern(Concern::UPDATES, Concern::LEVEL_INFO, ['classified' => false, 'security' => 0, 'other' => 1, 'days' => 0]);

		$this->assertSame('1 update pending', $this->texts->title($concern, $this->status, $this->l));
		$this->assertSame('', $this->texts->detail($concern, $this->l));
	}

	public function testReboot(): void {
		$concern = new Concern(Concern::REBOOT, Concern::LEVEL_INFO, ['days' => 2, 'kinds' => ['kernel'], 'livePatched' => true]);

		$this->assertSame('Reboot required', $this->texts->title($concern, $this->status, $this->l));
		$this->assertSame(
			'A newer kernel is installed. Pending for 2 days. A live kernel patch is active, which covers the kernel until the reboot.',
			$this->texts->detail($concern, $this->l),
		);
	}

	public function testSupport(): void {
		$extended = new Concern(Concern::SUPPORT, Concern::LEVEL_WARNING, [
			'state' => SupportStatus::STATE_EXTENDED_AVAILABLE, 'ends' => '2027-05-31', 'extended' => 'ubuntu-pro', 'extendedEnds' => '2032-04-09',
		]);
		$this->assertSame(
			'Standard support for Ubuntu 22.04.5 LTS has ended. Security updates continue only with Ubuntu Pro until 2032-04-09',
			$this->texts->title($extended, $this->status, $this->l),
		);

		$ended = new Concern(Concern::SUPPORT, Concern::LEVEL_ERROR, ['state' => SupportStatus::STATE_ENDED, 'ends' => '2024-06-30']);
		$this->assertSame('Ubuntu 22.04.5 LTS no longer receives security updates since 2024-06-30', $this->texts->title($ended, $this->status, $this->l));
	}

	public function testRestartPrefersThePhpMessage(): void {
		$concern = new Concern(Concern::RESTART, Concern::LEVEL_WARNING, ['php' => true, 'services' => 2, 'days' => 4]);

		$this->assertSame('PHP is running outdated libraries', $this->texts->title($concern, $this->status, $this->l));
		$this->assertSame('Another 2 services need a restart too. Pending for 4 days.', $this->texts->detail($concern, $this->l));
	}
}
