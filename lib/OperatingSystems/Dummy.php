<?php

declare(strict_types=1);

/**
 * @author Frank Karlitschek <frank@nextcloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
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
}
