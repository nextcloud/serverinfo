<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OperatingSystems;

use OCA\ServerInfo\Resources\CPU;
use OCA\ServerInfo\Resources\Disk;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\Resources\NetInterface;
use OCA\ServerInfo\Resources\ThermalZone;

interface IOperatingSystem {
	public function supported(): bool;

	public function getCPU(): CPU;

	public function getMemory(): Memory;

	/**
	 * @return Disk[]
	 */
	public function getDiskInfo(): array;

	/**
	 * Get info about network connection.
	 *
	 * [
	 *        'gateway' => string,
	 *        'hostname' => string,
	 * ]
	 */
	public function getNetworkInfo(): array;

	/**
	 * Get info about available network interfaces.
	 *
	 * @return NetInterface[]
	 */
	public function getNetworkInterfaces(): array;

	/**
	 * Get system time and timezone.
	 * Empty string in case of errors
	 */
	public function getTime(): string;

	/**
	 * Get the total number of seconds the system has been up or -1 on failure.
	 */
	public function getUptime(): int;

	/**
	 * Get info about available thermal zones.
	 *
	 * @return ThermalZone[]
	 */
	public function getThermalZones(): array;
}
