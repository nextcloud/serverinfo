<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Resources;

/**
 * @psalm-api
 */
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
