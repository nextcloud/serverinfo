<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Resources;

/**
 * @psalm-api
 */
class CPU implements \JsonSerializable {
	public function __construct(
		private string $name,
		private int $threads,
	) {
	}

	public function getName(): string {
		return $this->name;
	}

	public function getThreads(): int {
		return $this->threads;
	}

	/**
	 * Retrieves the system load averages.
	 *
	 * @return array|false Returns an array containing the system load averages for the last 1, 5, and 15 minutes.
	 */
	public function getAverageLoad(): array|false {
		if (function_exists('sys_getloadavg')) {
			return sys_getloadavg();
		}
		return false;
	}

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'name' => $this->name,
			'threads' => $this->threads,
		];
	}
}
