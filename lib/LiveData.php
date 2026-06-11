<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

class LiveData {
	public function __construct(
		private Os $os,
		private UptimeFormatter $uptimeFormatter,
	) {
	}

	public function getData(): array {
		$cpu = $this->os->getCPU();
		$memory = $this->os->getMemory();

		return [
			'cpu' => [
				'load' => $cpu->getAverageLoad(),
			],
			'memory' => [
				'total' => $memory->getMemTotal(),
				'free' => $memory->getMemAvailable(),
				'swap_total' => $memory->getSwapTotal(),
				'swap_free' => $memory->getSwapFree(),
			],
			'servertime' => $this->os->getTime(),
			'uptime' => $this->uptimeFormatter->format($this->os->getUptime()),
			'thermalzones' => $this->os->getThermalZones(),
		];
	}
}
