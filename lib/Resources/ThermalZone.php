<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Resources;

/**
 * @psalm-api
 */
class ThermalZone implements \JsonSerializable {
	public function __construct(
		private string $zone,
		private string $type,
		private float $temp,
	) {
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

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'zone' => $this->zone,
			'type' => $this->type,
			'temp' => $this->temp,
		];
	}
}
