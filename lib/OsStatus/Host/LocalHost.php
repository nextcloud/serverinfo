<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Host;

/**
 * The machine Nextcloud runs on, as the web server user sees it.
 *
 * Every filesystem call is silenced because open_basedir turns a path outside
 * its list into a warning, and a warning is not what a missing answer should
 * cost.
 *
 * @psalm-api
 */
class LocalHost implements IHost {
	public function __construct(
		private CommandRunner $runner,
	) {
	}

	#[\Override]
	public function readFile(string $path, int $maxBytes = 1048576): ?string {
		if (!@is_file($path) || !@is_readable($path)) {
			return null;
		}

		$content = @file_get_contents($path, false, null, 0, max(1, $maxBytes));

		return $content === false ? null : $content;
	}

	#[\Override]
	public function readFileTail(string $path, int $maxBytes = 262144): ?string {
		if (!@is_file($path) || !@is_readable($path)) {
			return null;
		}

		$size = @filesize($path);
		if ($size === false) {
			return null;
		}

		$offset = max(0, $size - max(1, $maxBytes));
		$content = @file_get_contents($path, false, null, $offset);
		if ($content === false) {
			return null;
		}

		// Drop the partial first line when reading from the middle of the file.
		if ($offset > 0 && ($newline = strpos($content, "\n")) !== false) {
			$content = substr($content, $newline + 1);
		}

		return $content;
	}

	#[\Override]
	public function fileExists(string $path): bool {
		clearstatcache(true, $path);

		return @file_exists($path);
	}

	#[\Override]
	public function modifiedAt(string $path): ?int {
		clearstatcache(true, $path);
		$time = @filemtime($path);

		return $time === false ? null : $time;
	}

	#[\Override]
	public function changedAt(string $path): ?int {
		clearstatcache(true, $path);
		$time = @filectime($path);

		return $time === false ? null : $time;
	}

	#[\Override]
	public function readLink(string $path): ?string {
		$target = @readlink($path);

		return $target === false ? null : $target;
	}

	#[\Override]
	public function glob(string $pattern): array {
		$matches = @glob($pattern);

		return is_array($matches) ? array_values($matches) : [];
	}

	#[\Override]
	public function run(string $binary, array $arguments = [], int $timeout = 10): ?CommandResult {
		return $this->runner->run($binary, $arguments, $timeout);
	}

	#[\Override]
	public function hasBinary(string $binary): bool {
		return $this->runner->isAvailable() && $this->runner->resolve($binary) !== null;
	}

	#[\Override]
	public function binaryState(string $binary): ?bool {
		return $this->runner->isAvailable() ? $this->runner->state($binary) : false;
	}

	#[\Override]
	public function canRunCommands(): bool {
		return $this->runner->isAvailable();
	}

	#[\Override]
	public function environment(string $name): ?string {
		$value = getenv($name);

		return is_string($value) && $value !== '' ? $value : null;
	}

	#[\Override]
	public function uname(string $mode): string {
		return php_uname($mode);
	}

	#[\Override]
	public function osFamily(): string {
		return PHP_OS_FAMILY;
	}

	#[\Override]
	public function openBasedir(): array {
		$setting = trim((string)ini_get('open_basedir'));
		if ($setting === '') {
			return [];
		}

		return array_values(array_filter(explode(PATH_SEPARATOR, $setting), static fn (string $dir): bool => $dir !== ''));
	}

	#[\Override]
	public function sapi(): string {
		return PHP_SAPI;
	}
}
