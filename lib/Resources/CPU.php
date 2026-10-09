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


	#[\Override]
	public function jsonSerialize(): array {
		return [
			'name' => $this->name,
			'threads' => $this->threads,
		];
	}
}
