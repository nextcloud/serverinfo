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
	) {
	}

	public function forRelease(OsRelease $release): ?IPackageManager {
		$candidate = match (true) {
			$release->isLike('debian') || $release->isLike('ubuntu') => $this->apt,
			default => null,
		};

		return $candidate !== null && $candidate->isAvailable() ? $candidate : null;
	}
}
