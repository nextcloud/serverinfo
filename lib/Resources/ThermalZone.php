<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Daniel Kesselberg <mail@danielkesselberg.de>
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

class ThermalZone implements \JsonSerializable {
	public function __construct(
		private string $zone,
		private string $type,
		private float  $temp) {
	}

	public function getZone(): string {
		return $this->zone;
	}

	public function getType(): string {
		return $this->type;
	}

	public function getTemp(): float {
		return $this->temp;
	}

	public function jsonSerialize(): array {
		return [
			'zone' => $this->zone,
			'type' => $this->type,
			'temp' => $this->temp,
		];
	}
}
