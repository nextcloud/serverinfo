<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\Reboot\RebootReason;

/**
 * What one package manager can tell an unprivileged user about the system.
 *
 * Every query only reads what the package manager already has: nothing here
 * refreshes repository metadata or changes the system.
 */
interface IPackageManager {
	/**
	 * Short identifier, e.g. "apt".
	 */
	public function getId(): string;

	/**
	 * False only when the tool is known to be missing; when open_basedir
	 * hides it, it is assumed to be there and running it tells.
	 */
	public function isAvailable(): bool;

	/**
	 * Whether pending updates can be told apart into security fixes and others.
	 */
	public function classifiesSecurity(): bool;

	/**
	 * @return list<PendingUpdate>
	 * @throws ProbeException when the package manager could not be asked
	 */
	public function getPendingUpdates(): array;

	/**
	 * @return list<HeldPackage>|null null when holds cannot be listed
	 */
	public function getHeldPackages(): ?array;

	/**
	 * When the repository metadata was last refreshed, as a unix timestamp.
	 */
	public function getListsRefreshedAt(): ?int;

	/**
	 * When packages were last installed or upgraded, as a unix timestamp.
	 */
	public function getLastUpgradeAt(): ?int;

	public function getAutomaticUpdates(): AutomaticUpdates;

	/**
	 * Reboot reasons only this package manager's tooling knows about. The
	 * generic kernel and library checks are made separately.
	 *
	 * @return list<RebootReason>
	 */
	public function getRebootReasons(): array;

	/**
	 * Whether getRebootReasons() reflects a complete check of its own, so the
	 * generic core library check would only repeat it.
	 */
	public function hasOwnRebootCheck(): bool;

	/**
	 * Shell commands an admin runs to install the pending updates.
	 *
	 * @return array{all: string, security: ?string}
	 */
	public function getUpgradeCommands(): array;

	/**
	 * A file that changes whenever packages are installed or removed, used to
	 * tell that a cached status is out of date.
	 */
	public function getPackageDatabasePath(): ?string;
}
