<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Host;

/**
 * Runs system tools without a shell.
 *
 * The command is handed to proc_open() as an argument vector, so nothing in
 * it is ever interpreted by a shell. Binaries are only looked up in the
 * system directories, the locale is forced to C because the tools translate
 * their output, and every run has a time limit and an output cap.
 */
class CommandRunner {
	/** Where binaries are looked up, system directories first. */
	public const SEARCH_PATH = ['/usr/bin', '/usr/sbin', '/bin', '/sbin', '/usr/local/bin', '/usr/local/sbin'];

	/** Output beyond this is dropped and the process killed. */
	private const MAX_OUTPUT = 8 * 1024 * 1024;

	/** How long a process that closed its output is given to exit before it is killed. */
	private const EXIT_GRACE_SECONDS = 2.0;

	public function isAvailable(): bool {
		return function_exists('proc_open')
			&& function_exists('proc_get_status')
			&& function_exists('proc_terminate')
			&& function_exists('proc_close');
	}

	public function resolve(string $binary): ?string {
		foreach ($this->candidates($binary) as [$path, $verified]) {
			if ($verified) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * @param list<string> $arguments
	 */
	public function run(string $binary, array $arguments, int $timeout): ?CommandResult {
		if (!$this->isAvailable()) {
			return null;
		}

		foreach ($this->candidates($binary) as [$path, $verified]) {
			$result = $this->execute($path, $arguments, max(1, $timeout));
			if ($result === null) {
				continue;
			}

			// An unverified guess that does not exist fails exec in the child,
			// which exits with 127 before printing anything.
			if (!$verified && $result->exitCode === 127 && $result->stdout === '') {
				continue;
			}

			return $result;
		}

		return null;
	}

	/**
	 * Paths to try for a binary, each with whether it was seen to exist.
	 *
	 * Under open_basedir the system directories cannot be inspected, though
	 * processes can still be started from them, so the candidates are then
	 * tried blind.
	 *
	 * @return list<array{0: string, 1: bool}>
	 */
	private function candidates(string $binary): array {
		if (str_starts_with($binary, '/')) {
			if (preg_match('#^(/[A-Za-z0-9._+-]+)+$#', $binary) !== 1 || str_contains($binary, '/..')) {
				return [];
			}

			return $this->isExecutable($binary)
				? [[$binary, true]]
				: ($this->hasOpenBasedir() ? [[$binary, false]] : []);
		}

		if (preg_match('/^[A-Za-z0-9._+-]+$/', $binary) !== 1) {
			return [];
		}

		foreach (self::SEARCH_PATH as $directory) {
			$path = $directory . '/' . $binary;
			if ($this->isExecutable($path)) {
				return [[$path, true]];
			}
		}

		if (!$this->hasOpenBasedir()) {
			return [];
		}

		return array_map(static fn (string $directory): array => [$directory . '/' . $binary, false], self::SEARCH_PATH);
	}

	private function isExecutable(string $path): bool {
		return @is_file($path) && @is_executable($path);
	}

	private function hasOpenBasedir(): bool {
		return trim((string)ini_get('open_basedir')) !== '';
	}

	/**
	 * @param list<string> $arguments
	 */
	private function execute(string $path, array $arguments, int $timeout): ?CommandResult {
		$descriptors = [
			0 => ['file', '/dev/null', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$pipes = [];
		$process = @proc_open([$path, ...$arguments], $descriptors, $pipes, null, $this->environment());
		if (!is_resource($process)) {
			return null;
		}

		$open = [1 => $pipes[1], 2 => $pipes[2]];
		foreach ($open as $stream) {
			stream_set_blocking($stream, false);
		}

		$output = [1 => '', 2 => ''];
		$deadline = microtime(true) + (float)$timeout;
		$timedOut = false;
		$truncated = false;

		while ($open !== []) {
			$remaining = $deadline - microtime(true);
			if ($remaining <= 0) {
				$timedOut = true;
				break;
			}

			$read = array_values($open);
			$write = null;
			$except = null;
			$seconds = (int)$remaining;
			$ready = @stream_select($read, $write, $except, $seconds, (int)(($remaining - (float)$seconds) * 1000000.0));
			if ($ready === false) {
				break;
			}

			foreach ($read as $stream) {
				$fd = array_search($stream, $open, true);
				if ($fd === false) {
					continue;
				}

				$chunk = fread($stream, 65536);
				if ($chunk === false || ($chunk === '' && feof($stream))) {
					fclose($stream);
					unset($open[$fd]);
					continue;
				}

				if (strlen($output[$fd]) + strlen($chunk) > self::MAX_OUTPUT) {
					$output[$fd] .= substr($chunk, 0, self::MAX_OUTPUT - strlen($output[$fd]));
					$truncated = true;
					break 2;
				}

				$output[$fd] .= $chunk;
			}
		}

		foreach ($open as $stream) {
			fclose($stream);
		}

		$exitCode = $this->finish($process, $timedOut || $truncated);

		return new CommandResult($exitCode, $output[1], $output[2], $timedOut, $truncated);
	}

	/**
	 * Waits for the process to exit, killing it when asked to or when it
	 * outstays the grace period, and returns its exit code.
	 *
	 * The exit code has to be taken from proc_get_status(): once that has seen
	 * the process exit, proc_close() can only answer -1.
	 *
	 * @param resource $process
	 */
	private function finish($process, bool $kill): int {
		if ($kill) {
			proc_terminate($process, 9);
		}

		$deadline = microtime(true) + self::EXIT_GRACE_SECONDS;
		$exitCode = null;
		do {
			$status = proc_get_status($process);
			if (!$status['running']) {
				$exitCode = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
				break;
			}
			usleep(10000);
		} while (microtime(true) < $deadline);

		if ($exitCode === null) {
			proc_terminate($process, 9);
		}

		$closed = proc_close($process);

		return $exitCode ?? ($closed >= 0 ? $closed : 137);
	}

	/**
	 * @return array<string, string>
	 */
	private function environment(): array {
		$temp = sys_get_temp_dir();
		$home = getenv('HOME');

		return [
			'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
			'LC_ALL' => 'C',
			'LANG' => 'C',
			'HOME' => is_string($home) && $home !== '' ? $home : $temp,
			'TMPDIR' => $temp,
			// Wide enough that table output is never cut to a terminal width.
			'COLUMNS' => '512',
			'TERM' => 'dumb',
			'NO_COLOR' => '1',
		];
	}
}
