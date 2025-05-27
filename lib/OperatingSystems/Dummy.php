<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\ServerInfo\OperatingSystems;

use OCA\ServerInfo\Resources\CPU;
use OCA\ServerInfo\Resources\Memory;

class Dummy implements IOperatingSystem {
	#[\Override]
	public function supported(): bool {
		return false;
	}

	#[\Override]
	public function getCPU(): CPU {
		return new CPU('Unknown Processor', 1);
	}

	#[\Override]
	public function getMemory(): Memory {
		return new Memory();
	}

	#[\Override]
	public function getTime(): string {
		return '';
	}

	#[\Override]
	public function getUptime(): int {
		return -1;
	}

	#[\Override]
	public function getNetworkInfo(): array {
		return [
			'hostname' => \gethostname(),
			'dns' => '',
			'gateway' => '',
		];
	}

	#[\Override]
	public function getNetworkInterfaces(): array {
		return [];
	}

	#[\Override]
	public function getDiskInfo(): array {
		return [];
	}

	#[\Override]
	public function getThermalZones(): array {
		return [];
	}
}
