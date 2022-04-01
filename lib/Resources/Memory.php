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

class Memory {
	private int $memTotal = -1;
	private int $memFree = -1;
	private int $memAvailable = -1;
	private int $swapTotal = -1;
	private int $swapFree = -1;

	/**
	 * @return int in MB
	 */
	public function getMemTotal(): int {
		return $this->memTotal;
	}

	/**
	 * @param int $memTotal in MB
	 */
	public function setMemTotal(int $memTotal): void {
		$this->memTotal = $memTotal;
	}

	/**
	 * @return int in MB
	 */
	public function getMemFree(): int {
		return $this->memFree;
	}

	/**
	 * @param int $memFree in MB
	 */
	public function setMemFree(int $memFree): void {
		$this->memFree = $memFree;
	}

	/**
	 * @return int in MB
	 */
	public function getMemAvailable(): int {
		return $this->memAvailable;
	}

	/**
	 * @param int $memAvailable in MB
	 */
	public function setMemAvailable(int $memAvailable): void {
		$this->memAvailable = $memAvailable;
	}

	/**
	 * @return int in MB
	 */
	public function getSwapTotal(): int {
		return $this->swapTotal;
	}

	/**
	 * @param int $swapTotal in MB
	 */
	public function setSwapTotal(int $swapTotal): void {
		$this->swapTotal = $swapTotal;
	}

	/**
	 * @return int in MB
	 */
	public function getSwapFree(): int {
		return $this->swapFree;
	}

	/**
	 * @param int $swapFree in MB
	 */
	public function setSwapFree(int $swapFree): void {
		$this->swapFree = $swapFree;
	}
}
