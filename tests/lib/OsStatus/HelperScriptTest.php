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
	/** @var list<string> */
	private array $temporary = [];

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
	 * Runs shell code with the script's functions loaded; the arguments are
	 * $1, $2, ... to the code.
	 *
	 * @return array{0: int, 1: string, 2: string} exit code, stdout and stderr
	 */
	private function functions(string $code, string ...$arguments): array {
		$script = realpath(self::SCRIPT) ?: self::SCRIPT;
		$result = $this->runner->run('sh', [
			'-c',
			'SERVERINFO_OS_REPORT_FUNCTIONS_ONLY=1; . "$0"; ' . $code,
			$script, ...$arguments,
		], 30);
		$this->assertNotNull($result);

		return [$result->exitCode, $result->stdout, $result->stderr];
	}

	/**
	 * A directory for one test, removed afterwards.
	 */
	private function temporaryDirectory(): string {
		$directory = sys_get_temp_dir() . '/serverinfo-helper-' . bin2hex(random_bytes(6));
		mkdir($directory, 0o700);
		$this->temporary[] = $directory;

		return realpath($directory) ?: $directory;
	}

	protected function tearDown(): void {
		foreach ($this->temporary as $directory) {
			$this->runner->run('rm', ['-rf', $directory], 10);
		}
		parent::tearDown();
	}

	/**
	 * Runs dnf_updates_json from the script on captured output.
	 */
	private function dnfUpdates(string $checkUpdate, string $advisories, string $installed): array {
		[$exit, $stdout, $stderr] = $this->functions('dnf_updates_json "$1" "$2" "$3"', $checkUpdate, $advisories, $installed);
		$this->assertSame(0, $exit, $stderr);

		$report = HelperReport::fromArray([
			'generatedAt' => 1, 'helperVersion' => '1.0.0', 'hostname' => 'h',
			'pendingUpdates' => json_decode($stdout, true, 16, JSON_THROW_ON_ERROR),
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

	public function testInstalledVersionsThatAreNotPlainAreDropped(): void {
		[$exit, $stdout, $stderr] = $this->functions(
			'dnf_updates_json "$1" "" "$2"',
			"ok.noarch 2-1 base\nfine.noarch 3-1 base\n",
			"ok 0:1-1\\\"]\nfine 0:2-1\n",
		);

		$this->assertSame(0, $exit, $stderr);
		$updates = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
		$this->assertSame([null, '2-1'], array_column($updates, 'installed'));
	}

	public function testNeedrestartBatchOutput(): void {
		$output = implode("\n", [
			'NEEDRESTART-VER: 3.6',
			'NEEDRESTART-KCUR: 6.8.0-45-generic',
			'NEEDRESTART-KEXP: 6.8.0-48-generic',
			'NEEDRESTART-KSTA: 3',
			'NEEDRESTART-UCSTA: 2',
			'NEEDRESTART-SVC: php8.3-fpm.service',
			'NEEDRESTART-SVC: ssh',
			'NEEDRESTART-SVC: php8.3-fpm.service',
			'NEEDRESTART-SVC: bad;unit',
			'NEEDRESTART-SESS: frank @ session #3',
		]);

		[$exit, $stdout] = $this->functions('parse_needrestart "$1"; printf "%s|%s" "$SERVICES" "$REBOOT_REASONS"', $output);

		$this->assertSame(0, $exit);
		$this->assertSame("php8.3-fpm.service\nssh.service\n|"
			. '{"kind":"kernel","detail":"6.8.0-48-generic"},{"kind":"microcode","detail":null}', $stdout);
	}

	public function testNeedrestartWithoutKernelOrMicrocodeFindings(): void {
		[$exit, $stdout] = $this->functions(
			'parse_needrestart "$1"; printf "%s|%s" "$SERVICES" "$REBOOT_REASONS"',
			"NEEDRESTART-VER: 3.11\nNEEDRESTART-KCUR: 7.0.0-38-generic\nNEEDRESTART-KEXP: 7.0.0-38-generic\nNEEDRESTART-KSTA: 1\n",
		);

		$this->assertSame([0, '|'], [$exit, $stdout]);
	}

	public function testOutputThatIsNotNeedrestartsIsRejected(): void {
		$this->assertSame(1, $this->functions('parse_needrestart "$1"', "Scanning processes...\n")[0]);
	}

	/**
	 * needrestart runs with a configuration that turns off its interpreter
	 * scan, read from a pipe.
	 */
	public function testNeedrestartRunsWithoutInterpreterScan(): void {
		$directory = $this->temporaryDirectory();
		file_put_contents($directory . '/needrestart', implode("\n", [
			'#!/bin/sh',
			'printf "%s\n" "$*" > "$CAPTURE/arguments"',
			'cat "$2" > "$CAPTURE/config"',
			'printf "NEEDRESTART-VER: 3.6\nNEEDRESTART-SVC: nginx.service\n"',
		]) . "\n");
		chmod($directory . '/needrestart', 0o755);

		[$exit, $stdout, $stderr] = $this->functions(
			'CAPTURE=$1; export CAPTURE; PATH="$1:$PATH"; from_needrestart; printf "%s" "$SERVICES"',
			$directory,
		);

		$this->assertSame([0, "nginx.service\n"], [$exit, $stdout], $stderr);
		$this->assertSame("-c /dev/stdin -b -r l\n", file_get_contents($directory . '/arguments'));
		$config = (string)file_get_contents($directory . '/config');
		$this->assertStringContainsString('$nrconf{interpscan} = 0;', $config);

		if ($this->runner->resolve('perl') === null) {
			return;
		}
		// Evaluated the way needrestart evaluates its configuration.
		$result = $this->runner->run('perl', ['-e', implode(' ', [
			'our %nrconf = (interpscan => 1, sendnotify => 1);',
			'eval do { local $/; open my $fh, $ARGV[0] or die "ERROR: $!\n"; my $cfg = <$fh>; close($fh); $cfg; };',
			'die "Error parsing: $@" if $@;',
			'print "$nrconf{interpscan} $nrconf{sendnotify}";',
		]), $directory . '/config'], 10);
		$this->assertSame([0, '0 0'], [$result?->exitCode, $result?->stdout], (string)$result?->stderr);
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
