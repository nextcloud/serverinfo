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

	public function testUnknownBinaries(): void {
		$this->assertNull($this->runner->run('serverinfo-no-such-binary', [], 5));
		$this->assertNull($this->runner->run('../../bin/sh', [], 5));
		$this->assertNull($this->runner->run('sh -c id', [], 5));
		$this->assertNull($this->runner->run('/bin/../bin/sh', [], 5));
	}
}
