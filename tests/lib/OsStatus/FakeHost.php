<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Tests\OsStatus;

use OCA\ServerInfo\OsStatus\Host\CommandResult;
use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * A machine made of fixtures: files, timestamps and command results.
 *
 * Anything not configured behaves the way a locked-down web server sees it:
 * missing files, unknown times and commands that cannot run.
 */
class FakeHost implements IHost {
	/** @var array<string, string> */
	public array $files = [];
	/** @var array<string, int> */
	public array $modified = [];
	/** @var array<string, int> */
	public array $changed = [];
	/** @var array<string, array{owner: int, mode: int}> */
	public array $owners = [];
	/** @var array<string, string> */
	public array $links = [];
	/** @var array<string, list<string>> */
	public array $globs = [];
	/** @var array<string, CommandResult> keyed by "binary arg1 arg2" */
	public array $commands = [];
	/** @var array<string, string> */
	public array $env = [];
	/** @var list<string> */
	public array $binaries = [];
	/** @var list<string> binaries that may be installed, but open_basedir hides them */
	public array $hiddenBinaries = [];
	/** @var list<string> */
	public array $basedir = [];
	/** @var list<string> commands that were run, for assertions */
	public array $ran = [];
	public bool $commandsAllowed = true;
	public string $release = '6.8.0-45-generic';
	public string $machine = 'x86_64';
	public string $host = 'cloud.example.org';
	public string $phpSapi = 'fpm-fcgi';
	public string $family = 'Linux';

	public static function fixture(string $name): string {
		$path = __DIR__ . '/../../data/os/' . $name;
		$content = file_get_contents($path);
		if ($content === false) {
			throw new \RuntimeException('Missing fixture ' . $name);
		}

		return $content;
	}

	public function withFile(string $path, string $content, ?int $modified = null): self {
		$this->files[$path] = $content;
		if ($modified !== null) {
			$this->modified[$path] = $modified;
		}

		return $this;
	}

	/**
	 * @param list<string> $arguments
	 */
	public function withCommand(string $binary, array $arguments, string $stdout, int $exitCode = 0, string $stderr = ''): self {
		$this->commands[self::key($binary, $arguments)] = new CommandResult($exitCode, $stdout, $stderr);
		if (!in_array($binary, $this->binaries, true)) {
			$this->binaries[] = $binary;
		}

		return $this;
	}

	#[\Override]
	public function readFile(string $path, int $maxBytes = 1048576): ?string {
		return isset($this->files[$path]) ? substr($this->files[$path], 0, $maxBytes) : null;
	}

	#[\Override]
	public function readFileTail(string $path, int $maxBytes = 262144): ?string {
		return isset($this->files[$path]) ? substr($this->files[$path], -$maxBytes) : null;
	}

	#[\Override]
	public function fileExists(string $path): bool {
		return isset($this->files[$path]) || isset($this->modified[$path]) || isset($this->changed[$path]) || isset($this->owners[$path]);
	}

	#[\Override]
	public function modifiedAt(string $path): ?int {
		return $this->modified[$path] ?? null;
	}

	#[\Override]
	public function changedAt(string $path): ?int {
		return $this->changed[$path] ?? null;
	}

	#[\Override]
	public function readLink(string $path): ?string {
		return $this->links[$path] ?? null;
	}

	#[\Override]
	public function glob(string $pattern): array {
		return $this->globs[$pattern] ?? [];
	}

	#[\Override]
	public function run(string $binary, array $arguments = [], int $timeout = 10): ?CommandResult {
		if (!$this->commandsAllowed) {
			return null;
		}

		$key = self::key($binary, $arguments);
		$this->ran[] = $key;

		return $this->commands[$key] ?? null;
	}

	#[\Override]
	public function hasBinary(string $binary): bool {
		return $this->commandsAllowed && in_array($binary, $this->binaries, true);
	}

	#[\Override]
	public function binaryState(string $binary): ?bool {
		return match (true) {
			!$this->commandsAllowed => false,
			in_array($binary, $this->binaries, true) => true,
			in_array($binary, $this->hiddenBinaries, true) => null,
			default => false,
		};
	}

	#[\Override]
	public function canRunCommands(): bool {
		return $this->commandsAllowed;
	}

	#[\Override]
	public function environment(string $name): ?string {
		return $this->env[$name] ?? null;
	}

	#[\Override]
	public function uname(string $mode): string {
		return match ($mode) {
			'r' => $this->release,
			'm' => $this->machine,
			default => $this->host,
		};
	}

	#[\Override]
	public function osFamily(): string {
		return $this->family;
	}

	#[\Override]
	public function openBasedir(): array {
		return $this->basedir;
	}

	#[\Override]
	public function sapi(): string {
		return $this->phpSapi;
	}

	/**
	 * @param list<string> $arguments
	 */
	private static function key(string $binary, array $arguments): string {
		return trim($binary . ' ' . implode(' ', $arguments));
	}
}
