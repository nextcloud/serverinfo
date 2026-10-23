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
use PHPUnit\Framework\Attributes\DataProvider;

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
		$this->assertStringContainsString('--random-delay', $stdout);
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

	/**
	 * Writes a shell script standing in for a system command.
	 *
	 * @param list<string> $lines
	 */
	private function fakeCommand(string $directory, string $name, array $lines): void {
		file_put_contents($directory . '/' . $name, implode("\n", ['#!/bin/sh', ...$lines]) . "\n");
		chmod($directory . '/' . $name, 0o755);
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

	/**
	 * dnf 4 wraps a row when a name or version is wider than its column;
	 * architectures are not limited to a known list.
	 */
	public function testDnf4WrappedRowsAndAnyArchitecture(): void {
		$checkUpdate = <<<'EOT'
			Last metadata expiration check: 0:01:02 ago on Mon Oct 19 18:12:41 2026.

			glibc.x86_64_v2                     2.39-58.el10_1                  baseos
			rhel-9-a-very-long-package-name-for-testing.x86_64
			                                    1.2.3-4.el9                     rhel-9-for-x86_64-appstream-rpms
			long-version.noarch                 1:20261022.0.1234567890-1.el9.something_long
			                                                                    rhel-9-for-x86_64-baseos-rpms
			a-package-with-both-columns-too-wide.aarch64
			                                    1:20261022.0.1234567890-1.el9.something_long
			                                                                    rhel-9-for-aarch64-appstream-rpms
			python3.11-libs.ppc64               3.11.9-1.el9                    appstream
			zlib.s390                           1.2.11-41.el9                   baseos
			orphan-line.noarch
			tzdata.noarch                       2026e-1.el9                     baseos
			Obsoleting Packages
			obsoleting.noarch                   1-1                             baseos
			EOT;

		$updates = $this->dnfUpdates($checkUpdate, '', '');

		$this->assertSame([
			'glibc' => '2.39-58.el10_1',
			'rhel-9-a-very-long-package-name-for-testing' => '1.2.3-4.el9',
			'long-version' => '1:20261022.0.1234567890-1.el9.something_long',
			'a-package-with-both-columns-too-wide' => '1:20261022.0.1234567890-1.el9.something_long',
			'python3.11-libs' => '3.11.9-1.el9',
			'zlib' => '1.2.11-41.el9',
			'tzdata' => '2026e-1.el9',
		], array_map(static fn (array $update): string => $update[1], $updates));
	}

	public function testDnfNeedsRestartingReadsFromTheCache(): void {
		$directory = $this->temporaryDirectory();
		$this->fakeCommand($directory, 'dnf', [
			'printf "%s\n" "$*" > "$CAPTURE/arguments"',
			'echo nginx.service',
		]);

		[$exit, $stdout, $stderr] = $this->functions('CAPTURE=$1; export CAPTURE; PATH="$1:$PATH"; from_dnf; printf "%s" "$SERVICES"', $directory);

		$this->assertSame([0, "nginx.service\n"], [$exit, $stdout], $stderr);
		$this->assertSame("-q --cacheonly needs-restarting -s\n", file_get_contents($directory . '/arguments'));
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
		$this->fakeCommand($directory, 'needrestart', [
			'printf "%s\n" "$*" > "$CAPTURE/arguments"',
			'cat "$2" > "$CAPTURE/config"',
			'printf "NEEDRESTART-VER: 3.6\nNEEDRESTART-SVC: nginx.service\n"',
		]);

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

	public static function dataCgroups(): array {
		return [
			'cgroup v2' => ["0::/system.slice/php8.3-fpm.service\n", 'php8.3-fpm.service'],
			'nested slice' => ["0::/system.slice/system-getty.slice/getty@tty1.service\n", 'getty@tty1.service'],
			'cgroup v1' => ["12:pids:/system.slice/nginx.service\n1:name=systemd:/system.slice/nginx.service\n", 'nginx.service'],
			'hybrid' => ["1:name=systemd:/system.slice/nginx.service\n0::/system.slice/nginx.service\n", 'nginx.service'],
			'a user unit named like a system one' => ["0::/user.slice/user-1000.slice/user@1000.service/app.slice/php8.3-fpm.service\n", ''],
			'a user unit in cgroup v1' => ["1:name=systemd:/user.slice/user-1000.slice/user@1000.service/php8.3-fpm.service\n", ''],
			'the user manager' => ["0::/user.slice/user-1000.slice/user@1000.service/init.scope\n", ''],
			'a login session' => ["0::/user.slice/user-1000.slice/session-3.scope\n", ''],
			'a delegated subtree' => ["0::/system.slice/docker.service/payload\n", ''],
			'init' => ["0::/init.scope\n", ''],
		];
	}

	#[DataProvider('dataCgroups')]
	public function testOnlySystemServicesAreTakenFromCgroups(string $cgroup, string $unit): void {
		$file = $this->temporaryDirectory() . '/cgroup';
		file_put_contents($file, $cgroup);

		$this->assertSame([0, $unit === '' ? '' : $unit . "\n"], array_slice($this->functions('system_unit "$1"', $file), 0, 2));
	}

	/**
	 * Shell code that replaces stat(1) with the owner and mode given as
	 * $2 and $3 for the directory $1, and as $4 and $5 for anything else.
	 */
	private const FAKE_STAT = 'stat() { if [ "$3" = "$DIR" ]; then o=$DO m=$DM; else o=$PO m=$PM; fi; case "$2" in %u) echo "$o" ;; *) echo "$m" ;; esac; }; DIR=$1 DO=$2 DM=$3 PO=$4 PM=$5; ';

	public static function dataDirectories(): array {
		return [
			'root only' => ['0', '755', '0', '755', ''],
			'setgid, sticky and closed modes' => ['0', '2750', '0', '1755', ''],
			'directory owned by the web server' => ['33', '755', '0', '755', '/reports must be owned by root'],
			'directory group writable' => ['0', '775', '0', '755', '/reports must not be writable by group or others'],
			'directory world writable' => ['0', '757', '0', '755', '/reports must not be writable by group or others'],
			'parent owned by a user' => ['0', '755', '1000', '755', ' must be owned by root'],
			'parent world writable' => ['0', '755', '0', '1777', ' must not be writable by group or others'],
			'parent group writable, setgid' => ['0', '755', '0', '2775', ' must not be writable by group or others'],
		];
	}

	#[DataProvider('dataDirectories')]
	public function testReportDirectoryMustBeRootsAlone(string $owner, string $mode, string $parentOwner, string $parentMode, string $error): void {
		$directory = $this->temporaryDirectory() . '/reports';

		[$exit, $stdout, $stderr] = $this->functions(
			self::FAKE_STAT . 'secure_directory "$1" && echo secured',
			$directory, $owner, $mode, $parentOwner, $parentMode,
		);

		if ($error === '') {
			$this->assertSame([0, "secured\n"], [$exit, $stdout], $stderr);
			$this->assertDirectoryExists($directory);
		} else {
			$this->assertSame(1, $exit);
			$this->assertStringContainsString($error, $stderr);
		}
	}

	public function testASymlinkedReportDirectoryIsRefused(): void {
		$base = $this->temporaryDirectory();
		mkdir($base . '/elsewhere');
		symlink($base . '/elsewhere', $base . '/reports');

		[$exit, , $stderr] = $this->functions(self::FAKE_STAT . 'write_report "{}" "$1/os-report.json"', $base . '/reports', '0', '755', '0', '755');

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('is a symbolic link', $stderr);
		$this->assertSame(['.', '..'], scandir($base . '/elsewhere'));
	}

	public function testTheReportReplacesASymlinkInsteadOfFollowingIt(): void {
		$directory = $this->temporaryDirectory();
		file_put_contents($directory . '/victim', 'keep');
		symlink($directory . '/victim', $directory . '/os-report.json');

		[$exit, , $stderr] = $this->functions(self::FAKE_STAT . 'write_report "{\"schema\": 1}" "$1/os-report.json"', $directory, '0', '755', '0', '755');

		$this->assertSame(0, $exit, $stderr);
		$this->assertSame('keep', file_get_contents($directory . '/victim'));
		$this->assertFalse(is_link($directory . '/os-report.json'));
		$this->assertSame("{\"schema\": 1}\n", file_get_contents($directory . '/os-report.json'));
		$this->assertSame(0o644, fileperms($directory . '/os-report.json') & 0o777);
		$this->assertSame(['.', '..', 'os-report.json', 'victim'], scandir($directory), 'No temporary file is left');
	}

	public function testTheReportIsNeverMovedIntoADirectory(): void {
		$directory = $this->temporaryDirectory();
		mkdir($directory . '/os-report.json');
		mkdir($directory . '/elsewhere');
		symlink($directory . '/elsewhere', $directory . '/linked.json');

		foreach (['os-report.json', 'linked.json'] as $name) {
			[$exit, , $stderr] = $this->functions(self::FAKE_STAT . 'write_report "{}" "$1/' . $name . '"', $directory, '0', '755', '0', '755');

			$this->assertSame(1, $exit, $name);
			$this->assertStringContainsString('is a directory', $stderr, $name);
		}
		$this->assertSame(['.', '..'], scandir($directory . '/os-report.json'));
		$this->assertSame(['.', '..'], scandir($directory . '/elsewhere'));
		$this->assertSame(['.', '..', 'elsewhere', 'linked.json', 'os-report.json'], scandir($directory));
	}

	public function testASignalRemovesTheTemporaryReportAndFails(): void {
		$directory = $this->temporaryDirectory();

		[$exit, $stdout] = $this->functions(
			'set_traps; TEMPORARY=$1/.os-report.test; : > "$TEMPORARY"; kill -TERM $$; echo still running',
			$directory,
		);

		$this->assertSame([1, ''], [$exit, $stdout]);
		$this->assertSame(['.', '..'], scandir($directory));
	}

	public static function dataTimeouts(): array {
		return [
			'coreutils and busybox 1.30 or later' => [':', "-k 30 300 echo run\n"],
			'timeout without -k' => ['[ "$1" = -k ] && exit 125', "300 echo run\n"],
			'busybox before 1.30' => ['exit 1', ''],
		];
	}

	/**
	 * Commands get a kill timeout where timeout(1) supports one, and run
	 * without a limit where it does not understand the arguments at all.
	 */
	#[DataProvider('dataTimeouts')]
	public function testCommandsAreTimeLimitedAsFarAsTimeoutAllows(string $behaviour, string $called): void {
		$directory = $this->temporaryDirectory();
		$this->fakeCommand($directory, 'timeout', [
			$behaviour,
			'case "$*" in *" true") ;; *) printf "%s\n" "$*" >> "$CAPTURE/calls" ;; esac',
			'[ "$1" = -k ] && shift 2',
			'shift',
			'exec "$@"',
		]);

		[$exit, $stdout, $stderr] = $this->functions('CAPTURE=$1; export CAPTURE; PATH="$1:$PATH"; run_limited 300 echo run', $directory);

		$this->assertSame([0, "run\n"], [$exit, $stdout], $stderr);
		$this->assertSame($called, (string)@file_get_contents($directory . '/calls'));
	}

	public function testCommandsDoNotInheritTheLock(): void {
		[$exit] = $this->functions('exec 9>/dev/null; TIMEOUT_MODE=none; run_limited 5 sh -c "echo held >&9"');

		$this->assertNotSame(0, $exit);
	}

	public static function dataLocks(): array {
		return [
			'free' => ['exit 0', [0, "running\n"]],
			'held by another run' => ['exit 1', [0, '']],
		];
	}

	#[DataProvider('dataLocks')]
	public function testOnlyOneRunAtATime(string $flock, array $expected): void {
		$directory = $this->temporaryDirectory();
		$this->fakeCommand($directory, 'flock', ['[ "$*" = "-n 9" ] || exit 2', $flock]);

		$result = $this->functions('LOCK=$1/os-report.lock; PATH="$1:$PATH"; take_lock; echo running', $directory);

		$this->assertSame($expected, array_slice($result, 0, 2), $result[2]);
		$this->assertFileExists($directory . '/os-report.lock');
	}

	public function testRunsUnlockedWithoutFlock(): void {
		$directory = $this->temporaryDirectory();
		$dirname = $this->runner->resolve('dirname');
		$this->assertNotNull($dirname);
		symlink($dirname, $directory . '/dirname');

		[$exit, $stdout] = $this->functions('LOCK=$1/os-report.lock; PATH=$1; take_lock; echo running', $directory);

		$this->assertSame([0, "running\n"], [$exit, $stdout]);
	}

	public function testTheRandomDelayIsAtMostHalfAnHour(): void {
		for ($i = 0; $i < 5; $i++) {
			[$exit, $stdout] = $this->functions('sleep() { echo "$1"; }; random_delay');

			$this->assertSame(0, $exit);
			$this->assertMatchesRegularExpression('/^\d+\n$/', $stdout);
			$this->assertLessThan(1800, (int)$stdout);
		}
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
