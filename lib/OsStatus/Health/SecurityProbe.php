<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Health;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Mandatory access control and live kernel patching, read from /sys, which
 * the web server may read even where /proc is restricted.
 *
 * @psalm-api
 */
class SecurityProbe {
	public function __construct(
		private IHost $host,
	) {
	}

	/**
	 * @return 'enforcing'|'permissive'|'disabled'|null
	 */
	public function getSelinux(): ?string {
		$enforce = $this->host->readFile('/sys/fs/selinux/enforce', 16);
		if ($enforce !== null) {
			return trim($enforce) === '1' ? 'enforcing' : 'permissive';
		}

		return $this->host->fileExists('/sys/fs/selinux') ? null : 'disabled';
	}

	/**
	 * @return 'enabled'|'disabled'|null
	 */
	public function getApparmor(): ?string {
		$enabled = $this->host->readFile('/sys/module/apparmor/parameters/enabled', 16);
		if ($enabled === null) {
			return $this->host->fileExists('/sys/module') ? 'disabled' : null;
		}

		return strtoupper(trim($enabled)) === 'Y' ? 'enabled' : 'disabled';
	}

	/**
	 * Live patches loaded into the running kernel, whatever tool put them
	 * there: Canonical Livepatch, kpatch and SUSE's klp all use the kernel's
	 * livepatch interface.
	 *
	 * @return list<string>|null patch names, null when /sys cannot be read
	 */
	public function getLivePatches(): ?array {
		if (!$this->host->fileExists('/sys/kernel')) {
			return null;
		}

		$patches = [];
		foreach ($this->host->glob('/sys/kernel/livepatch/*/enabled') as $file) {
			if (trim((string)$this->host->readFile($file, 16)) === '1') {
				$name = basename(dirname($file));
				if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name) === 1) {
					$patches[] = $name;
				}
			}
		}

		return $patches;
	}
}
