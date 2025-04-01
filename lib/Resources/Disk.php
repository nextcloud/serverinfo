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
