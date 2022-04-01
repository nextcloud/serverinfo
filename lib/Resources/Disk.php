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

namespace OCA\ServerInfo\Resources;

class Disk {
	private string $device = '';
	private string $fs = '';
	private int $used = 0;
	private int $available = 0;
	private string $percent = '';
	private string $mount = '';

	public function getDevice(): string {
		return $this->device;
	}

	public function setDevice(string $device): void {
		$this->device = $device;
	}

	public function getFs(): string {
		return $this->fs;
	}

	public function setFs(string $fs): void {
		$this->fs = $fs;
	}

	/**
	 * @return int in MB
	 */
	public function getUsed(): int {
		return $this->used;
	}

	/**
	 * @param int $used in MB
	 */
	public function setUsed(int $used): void {
		$this->used = $used;
	}

	/**
	 * @return int in MB
	 */
	public function getAvailable(): int {
		return $this->available;
	}

	/**
	 * @param int $available in MB
	 */
	public function setAvailable(int $available): void {
		$this->available = $available;
	}

	public function getPercent(): string {
		return $this->percent;
	}

	public function setPercent(string $percent): void {
		$this->percent = $percent;
	}

	public function getMount(): string {
		return $this->mount;
	}

	public function setMount(string $mount): void {
		$this->mount = $mount;
	}
}
