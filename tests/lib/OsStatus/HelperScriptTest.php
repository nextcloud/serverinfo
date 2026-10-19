<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Helper\HelperReport;
use OCA\ServerInfo\OsStatus\Helper\HelperReportReader;
use OCA\ServerInfo\OsStatus\Host\CommandRunner;

/**
 * The parts of bin/serverinfo-os-report that can run without root.
 */
class HelperScriptTest extends \Test\TestCase {
	private const SCRIPT = __DIR__ . '/../../../bin/serverinfo-os-report';

	private CommandRunner $runner;

	protected function setUp(): void {
		parent::setUp();
		$this->runner = new CommandRunner();
		if (!$this->runner->isAvailable() || $this->runner->resolve('sh') === null) {
			$this->markTestSkipped('No shell to run');
		}
	}

	private function runScript(string ...$arguments): array {
		$result = $this->runner->run('sh', [realpath(self::SCRIPT) ?: self::SCRIPT, ...$arguments], 30);
		$this->assertNotNull($result);

		return [$result->exitCode, $result->stdout, $result->stderr];
	}

	public function testIsValidPosixShell(): void {
		$this->assertSame(0, $this->runner->run('sh', ['-n', realpath(self::SCRIPT) ?: self::SCRIPT], 10)?->exitCode);
	}

	public function testVersion(): void {
		$this->assertSame([0, HelperReportReader::BUNDLED_VERSION . "\n", ''], $this->runScript('--version'));
	}

	public function testHelp(): void {
		[$exit, $stdout] = $this->runScript('--help');

		$this->assertSame(0, $exit);
		$this->assertStringContainsString('--refresh', $stdout);
	}

	public function testUnknownOptions(): void {
		$this->assertSame(1, $this->runScript('--install-everything')[0]);
	}

	public function testUnsafeOutputPathsAreRefusedBeforeAnythingElse(): void {
		foreach (['relative.json', '/etc/shadow', '/var/lib/../../etc/x.json', '/var/lib/a b.json', '/var/lib/x.json;id'] as $path) {
			[$exit, , $stderr] = $this->runScript('--output', $path);
			$this->assertSame(1, $exit, $path);
			$this->assertStringNotContainsString('must run as root', $stderr, $path);
		}
	}

	/**
	 * Runs dnf_updates_json from the script on captured output.
	 */
	private function dnfUpdates(string $checkUpdate, string $advisories, string $installed): array {
		$script = realpath(self::SCRIPT) ?: self::SCRIPT;
		$result = $this->runner->run('sh', [
			'-c',
			'SERVERINFO_OS_REPORT_FUNCTIONS_ONLY=1; . "$1"; dnf_updates_json "$2" "$3" "$4"',
			'sh', $script, $checkUpdate, $advisories, $installed,
		], 30);
		$this->assertSame(0, $result?->exitCode, (string)$result?->stderr);

		$report = HelperReport::fromArray([
			'generatedAt' => 1, 'helperVersion' => '1.0.0', 'hostname' => 'h',
			'pendingUpdates' => json_decode($result->stdout, true, 16, JSON_THROW_ON_ERROR),
		]);

		$updates = [];
		foreach ($report->pendingUpdates ?? [] as $update) {
			$updates[$update->name] = [$update->installedVersion, $update->candidateVersion, $update->security, $update->advisories, $update->severity];
		}

		return $updates;
	}

	public function testDnf4PendingUpdatesForTheReport(): void {
		$updates = $this->dnfUpdates(
			FakeHost::fixture('alma-9-dnf-check-update-pending'),
			FakeHost::fixture('alma-9-dnf-updateinfo-security'),
			"glibc 0:2.34-275.el9_8\nopenssl-libs 1:3.5.8-1.el9_8\n",
		);

		$this->assertCount(9, $updates);
		$this->assertSame(['2.34-275.el9_8', '2.34-283.el9_8', true, ['ALSA-2026:79281'], 'Moderate'], $updates['glibc']);
		$this->assertSame(['1:3.5.8-1.el9_8', '1:3.5.8-2.el9_8', true, ['ALSA-2026:77396'], 'Important'], $updates['openssl-libs']);
		$this->assertSame([null, '2026e-1.el9_8', false, [], null], $updates['tzdata']);
		$this->assertArrayNotHasKey('sudo', $updates, 'An advisory alone does not make an update pending');
	}

	public function testDnf5PendingUpdatesForTheReport(): void {
		$updates = $this->dnfUpdates(
			FakeHost::fixture('fedora-44-dnf-check-update-pending'),
			FakeHost::fixture('fedora-44-dnf-advisory-security'),
			'',
		);

		$this->assertSame(['curl', 'glibc', 'glibc-common', 'glibc-minimal-langpack', 'libcurl', 'openssl-libs', 'tzdata'], array_keys($updates));
		$this->assertSame([null, '1:3.5.9-1.fc44', true, ['FEDORA-2026-12f3f3d569'], 'Important'], $updates['openssl-libs']);
		$this->assertFalse($updates['glibc'][2]);
	}

	public function testDnfOutputThatIsNotPlainIsDropped(): void {
		$updates = $this->dnfUpdates(
			"evil\",\"x.x86_64  1-1  repo\nfine.noarch  1-2  base\"os\nok.noarch 2-1 base\n",
			'RHSA-1"]} Important/Sec. ok-2-1.noarch',
			'',
		);

		$this->assertSame(['ok'], array_keys($updates));
		$this->assertSame([], $updates['ok'][3]);
	}

	public function testRequiresRoot(): void {
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$this->markTestSkipped('Running as root');
		}

		[$exit, , $stderr] = $this->runScript('--dry-run');

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('must run as root', $stderr);
	}
}
