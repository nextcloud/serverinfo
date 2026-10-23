<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\OsRelease;

/**
 * Picks the package manager of a distribution from its os-release identity,
 * and only then checks that the tool is not known to be missing.
 *
 * @psalm-api
 */
class PackageManagerFactory {
	public function __construct(
		private Apt $apt,
		private Dnf $dnf,
		private Zypper $zypper,
		private Pacman $pacman,
		private Apk $apk,
	) {
	}

	public function forRelease(OsRelease $release): ?IPackageManager {
		$candidate = match (true) {
			$release->isLike('debian') || $release->isLike('ubuntu') => $this->apt->withRelease($release),
			$release->isLike('suse') || str_starts_with($release->id, 'opensuse') || $release->id === 'sles' || $release->id === 'sled' => $this->zypper,
			$release->isLike('fedora') || $release->isLike('rhel') || $release->isLike('centos') || in_array($release->id, ['amzn', 'ol', 'almalinux', 'rocky'], true) => $this->dnf->withRelease($release),
			$release->isLike('arch') => $this->pacman,
			$release->id === 'alpine' => $this->apk,
			default => null,
		};

		return $candidate !== null && $candidate->isAvailable() ? $candidate : null;
	}
}
