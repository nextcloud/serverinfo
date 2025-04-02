<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Resources;

/**
 * @psalm-api
 */
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
