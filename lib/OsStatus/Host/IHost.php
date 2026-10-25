<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Host;

/**
 * Everything the operating-system status reads from the machine.
 *
 * Every method answers "unknown" (null, false or an empty list) instead of
 * throwing, because a web server is routinely denied parts of the system:
 * open_basedir, ProcSubset=pid, disabled functions, missing binaries.
 *
 * @psalm-api
 */
interface IHost {
	/**
	 * @param int $maxBytes files longer than this are cut off
	 * @return string|null null when the file cannot be read
	 */
	public function readFile(string $path, int $maxBytes = 1048576): ?string;

	/**
	 * Read the end of a file, e.g. the latest entries of a log.
	 *
	 * @return string|null null when the file cannot be read
	 */
	public function readFileTail(string $path, int $maxBytes = 262144): ?string;

	public function fileExists(string $path): bool;

	/**
	 * @return int|null modification time, null when unavailable
	 */
	public function modifiedAt(string $path): ?int;

	/**
	 * @return int|null inode change time, which an install or upgrade sets
	 *                  even where the package manager keeps the build mtime
	 */
	public function changedAt(string $path): ?int;

	/**
	 * @return array{owner: int, mode: int}|null
	 */
	public function ownership(string $path): ?array;

	public function readLink(string $path): ?string;

	/**
	 * @return list<string>
	 */
	public function glob(string $pattern): array;

	/**
	 * Run a binary without a shell.
	 *
	 * @param string $binary a bare name looked up in the system directories, or an absolute path
	 * @param list<string> $arguments
	 * @param int $timeout seconds before the process is killed
	 * @return CommandResult|null null when the binary is missing or processes cannot be started
	 */
	public function run(string $binary, array $arguments = [], int $timeout = 10): ?CommandResult;

	/**
	 * Whether a binary is known to be installed.
	 */
	public function hasBinary(string $binary): bool;

	/**
	 * Whether a binary is installed: true or false when that is known, null
	 * when only running it can tell, because open_basedir hides the system
	 * directories.
	 */
	public function binaryState(string $binary): ?bool;

	public function canRunCommands(): bool;

	public function environment(string $name): ?string;

	/**
	 * @param 'r'|'m'|'n' $mode as for php_uname()
	 */
	public function uname(string $mode): string;

	public function hostname(): string;

	/**
	 * As PHP_OS_FAMILY, e.g. "Linux" or "BSD".
	 */
	public function osFamily(): string;

	/**
	 * Directories open_basedir confines PHP to, empty when it is not set.
	 *
	 * @return list<string>
	 */
	public function openBasedir(): array;

	/**
	 * The PHP SAPI serving the current request, e.g. "fpm-fcgi", "apache2handler" or "cli".
	 */
	public function sapi(): string;
}
