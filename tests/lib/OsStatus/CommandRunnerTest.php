<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Host\CommandRunner;

/**
 * Runs real processes through /bin/sh, which every CI image has.
 */
class CommandRunnerTest extends \Test\TestCase {
	private CommandRunner $runner;

	protected function setUp(): void {
		parent::setUp();
		$this->runner = new CommandRunner();
		if (!$this->runner->isAvailable() || $this->runner->resolve('sh') === null) {
			$this->markTestSkipped('No shell to run');
		}
	}

	public function testOutputAndExitCode(): void {
		$result = $this->runner->run('sh', ['-c', 'echo out; echo err >&2; exit 3'], 10);

		$this->assertNotNull($result);
		$this->assertSame("out\n", $result->stdout);
		$this->assertSame("err\n", $result->stderr);
		$this->assertSame(3, $result->exitCode);
		$this->assertFalse($result->succeeded());
	}

	public function testArgumentsAreNotInterpretedByAShell(): void {
		$result = $this->runner->run('sh', ['-c', 'printf "%s|" "$@"', 'sh', '$(id)', '; echo injected', '*'], 10);

		$this->assertSame('$(id)|; echo injected|*|', $result?->stdout);
	}

	public function testTheLocaleIsForcedToC(): void {
		$this->assertSame("C\n", $this->runner->run('sh', ['-c', 'echo "$LC_ALL"'], 10)?->stdout);
	}

	public function testSlowCommandsAreKilled(): void {
		$start = microtime(true);
		$result = $this->runner->run('sh', ['-c', 'sleep 30'], 1);

		$this->assertTrue($result?->timedOut);
		$this->assertFalse($result->succeeded());
		$this->assertLessThan(10, microtime(true) - $start);
	}

	public function testOutputBeyondTheCapIsCutOffAndFails(): void {
		$result = $this->runner->run('sh', ['-c', 'dd if=/dev/zero bs=1048576 count=9 2>/dev/null'], 30);

		$this->assertTrue($result?->truncated);
		$this->assertSame(8 * 1024 * 1024, strlen($result->stdout));
		$this->assertFalse($result->succeeded());
		$this->assertSame('output too large', $result->failure(30));
	}

	public function testWaitingThatKeepsFailingIsAFailure(): void {
		$runner = new class extends CommandRunner {
			#[\Override]
			protected function select(array &$read, float $timeout): int|false {
				return false;
			}
		};

		$start = microtime(true);
		$result = $runner->run('sh', ['-c', 'echo out; sleep 30'], 20);

		$this->assertTrue($result?->readFailed);
		$this->assertFalse($result->succeeded());
		$this->assertSame('output could not be read', $result->failure(20));
		$this->assertLessThan(10, microtime(true) - $start, 'The process is killed, not waited for');
	}

	public function testAnInterruptedWaitIsRetried(): void {
		$runner = new class extends CommandRunner {
			private bool $interrupted = false;

			#[\Override]
			protected function select(array &$read, float $timeout): int|false {
				if (!$this->interrupted) {
					$this->interrupted = true;

					return false;
				}

				return parent::select($read, $timeout);
			}
		};

		$result = $runner->run('sh', ['-c', 'echo out'], 10);

		$this->assertSame("out\n", $result?->stdout);
		$this->assertTrue($result->succeeded());
	}

	public function testFailureReasons(): void {
		$this->assertSame('timed out after 120 s', $this->runner->run('sh', ['-c', 'sleep 30'], 1)?->failure(120));
		$this->assertSame('E: broken', $this->runner->run('sh', ['-c', 'echo out; printf "E: broken\\nmore\\n" >&2; exit 100'], 10)?->failure(10));
		$this->assertSame('exit code 4', $this->runner->run('sh', ['-c', 'exit 4'], 10)?->failure(10));
	}

	public function testUnknownBinaries(): void {
		$this->assertNull($this->runner->run('serverinfo-no-such-binary', [], 5));
		$this->assertNull($this->runner->run('../../bin/sh', [], 5));
		$this->assertNull($this->runner->run('sh -c id', [], 5));
		$this->assertNull($this->runner->run('/bin/../bin/sh', [], 5));
	}
}
