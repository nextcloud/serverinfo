<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2022 Daniel Kesselberg <mail@danielkesselberg.de>
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

class NetInterface {
	private string $name = '';
	private bool $up = false;
	private array $ipv4 = [];
	private array $ipv6 = [];
	private string $mac = '';
	private string $speed = 'unknown';
	private string $duplex = 'unknown';
	private bool $loopback = false;

	public function __construct(string $name, bool $up) {
		$this->name = $name;
		$this->up = $up;
	}

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $name): void {
		$this->name = $name;
	}

	public function isUp(): bool {
		return $this->up;
	}

	public function setUp(bool $up): void {
		$this->up = $up;
	}

	/**
	 * @return string[]
	 */
	public function getIPv4(): array {
		return $this->ipv4;
	}

	public function addIPv4(string $ipv4): void {
		$this->ipv4[] = $ipv4;
		if ($ipv4 === '127.0.0.1') {
			$this->loopback = true;
		}
	}

	/**
	 * @return string[]
	 */
	public function getIPv6(): array {
		return $this->ipv6;
	}

	public function addIPv6(string $ipv6): void {
		$this->ipv6[] = $ipv6;
		if ($ipv6 === '::1') {
			$this->loopback = true;
		}
	}

	public function getMAC(): string {
		return $this->mac;
	}

	public function setMAC(string $mac): void {
		$this->mac = $mac;
	}

	public function getSpeed(): string {
		return $this->speed;
	}

	public function setSpeed(string $speed): void {
		$this->speed = $speed;
	}

	public function getDuplex(): string {
		return $this->duplex;
	}

	public function setDuplex(string $duplex): void {
		$this->duplex = $duplex;
	}

	public function isLoopback(): bool {
		return $this->loopback;
	}
}
