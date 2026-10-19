<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Restart;

use OCA\ServerInfo\OsStatus\Host\IHost;
use OCA\ServerInfo\OsStatus\OsRelease;

/**
 * Looks at the PHP process serving this request for libraries that were
 * replaced on disk since it started.
 *
 * A process always may read its own /proc/self/maps, even where the web
 * server hides the rest of /proc, so this works without any privilege. It
 * only means something inside a web request: the CLI process running cron
 * started a moment ago.
 *
 * @psalm-api
 */
class PhpProcessProbe {
	/** Mappings that are deleted by design rather than by an upgrade. */
	private const IGNORED_PREFIXES = ['/dev/', '/memfd:', '/tmp/', '/var/tmp/', '/run/', '/dev/shm/', '/SYSV', '/proc/', '/var/lib/php', '/var/cache/'];

	/** Report at most this many libraries; the restart fixes all of them. */
	private const MAX_LIBRARIES = 20;

	public function __construct(
		private IHost $host,
	) {
	}

	public function canSample(): bool {
		return $this->host->sapi() !== 'cli' && $this->host->sapi() !== 'phpdbg';
	}

	/**
	 * @param int $now unix timestamp stored with the sample
	 */
	public function sample(?OsRelease $release, int $now): ?PhpProcessSample {
		if (!$this->canSample()) {
			return null;
		}

		$maps = $this->host->readFile('/proc/self/maps', 8 * 1024 * 1024);
		if ($maps === null) {
			return null;
		}

		$libraries = self::deletedLibraries($maps);
		$executable = $this->host->readLink('/proc/self/exe');
		if ($executable !== null && str_ends_with($executable, ' (deleted)')) {
			array_unshift($libraries, basename(substr($executable, 0, -strlen(' (deleted)'))));
		}
		$libraries = array_slice(array_values(array_unique($libraries)), 0, self::MAX_LIBRARIES);

		return new PhpProcessSample(
			$now,
			$this->host->sapi(),
			$libraries,
			$this->serviceName($release),
			$this->confinement(),
		);
	}

	/**
	 * Basenames of the deleted libraries mapped into a process.
	 *
	 * @return list<string>
	 */
	public static function deletedLibraries(string $maps): array {
		$libraries = [];
		foreach (preg_split('/\R/', $maps) ?: [] as $line) {
			// address perms offset dev inode pathname
			if (preg_match('/^\S+\s+\S+\s+\S+\s+\S+\s+\d+\s+(\/.+) \(deleted\)$/', $line, $matches) !== 1) {
				continue;
			}

			$path = $matches[1];
			foreach (self::IGNORED_PREFIXES as $prefix) {
				if (str_starts_with($path, $prefix)) {
					continue 2;
				}
			}

			if (preg_match('/\.so(\.[\d.]+)?$|\.so\./', basename($path)) !== 1) {
				continue;
			}

			$libraries[basename($path)] = true;
		}

		return array_keys($libraries);
	}

	/**
	 * The service that has to be restarted to load the new libraries, named
	 * the way the distribution packages it.
	 */
	private function serviceName(?OsRelease $release): ?string {
		$isDebian = $release !== null && $release->isLike('debian');
		$isAlpine = $release !== null && $release->id === 'alpine';
		$isSuse = $release !== null && ($release->isLike('suse') || str_starts_with($release->id, 'opensuse') || $release->id === 'sles');

		return match ($this->host->sapi()) {
			'fpm-fcgi' => match (true) {
				$isDebian => 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm',
				$isAlpine => 'php-fpm' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION,
				default => 'php-fpm',
			},
			'apache2handler' => ($isDebian || $isAlpine || $isSuse) ? 'apache2' : 'httpd',
			'litespeed' => 'lsws',
			default => null,
		};
	}

	/**
	 * The mandatory access control label the web server runs under, e.g. an
	 * AppArmor profile or an SELinux context.
	 */
	private function confinement(): ?string {
		foreach (['/proc/self/attr/apparmor/current', '/proc/self/attr/current'] as $path) {
			$label = $this->host->readFile($path, 512);
			if ($label === null) {
				continue;
			}

			$label = trim(str_replace("\0", '', $label));
			if ($label !== '' && preg_match('/^[\x20-\x7e]{1,200}$/', $label) === 1) {
				return $label;
			}
		}

		return null;
	}
}
