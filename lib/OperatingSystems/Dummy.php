<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\ServerInfo\OperatingSystems;

use OCA\ServerInfo\Resources\Memory;

class Dummy implements IOperatingSystem {
	public function supported(): bool {
		return false;
	}

	public function getMemory(): Memory {
		return new Memory();
	}

	public function getCpuName(): string {
		return 'Unknown Processor';
	}

	public function getTime(): string {
		return '';
	}

	public function getUptime(): int {
		return -1;
	}

	public function getNetworkInfo(): array {
		return [
			'hostname' => \gethostname(),
			'dns' => '',
			'gateway' => '',
		];
	}

	public function getNetworkInterfaces(): array {
		return [];
	}

	public function getDiskInfo(): array {
		return [];
	}

	public function getThermalZones(): array {
		return [];
	}

	public function getCpuCount(): int {
		return 1;
	}
}
