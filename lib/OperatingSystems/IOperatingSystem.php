<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020 Daniel Kesselberg <mail@danielkesselberg.de>
 *
 * @author Daniel Kesselberg <mail@danielkesselberg.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\ServerInfo\OperatingSystems;

use OCA\ServerInfo\Resources\Disk;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\Resources\NetInterface;
use OCA\ServerInfo\Resources\ThermalZone;

interface IOperatingSystem {
	public function supported(): bool;

	/**
	 * Get name of the processor.
	 *
	 * @return string
	 */
	public function getCpuName(): string;

	/**
	 * Get disk info returns a list of Disk objects. Used and Available in bytes.
	 *
	 * @return Disk[]
	 */
	public function getDiskInfo(): array;

	/**
	 * Get memory returns a Memory object. All values are in bytes.
	 *
	 * @return Memory
	 */
	public function getMemory(): Memory;

	/**
	 * Get info about network connection.
	 *
	 * [
	 *        'dns' => string,
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
