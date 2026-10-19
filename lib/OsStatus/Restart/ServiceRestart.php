<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Restart;

use OCA\ServerInfo\OsStatus\NextcloudStack;
use OCA\ServerInfo\OsStatus\Values;

/**
 * A running service that still uses files replaced by an upgrade.
 */
final class ServiceRestart {
	public function __construct(
		/** systemd unit, e.g. "php8.3-fpm.service" */
		public readonly string $unit,
		/** When this was first seen, as a unix timestamp */
		public readonly ?int $since = null,
	) {
	}

	/**
	 * @return array{unit: string, since: ?int, stack: bool}
	 */
	public function toArray(): array {
		return ['unit' => $this->unit, 'since' => $this->since, 'stack' => NextcloudStack::isStackService($this->unit)];
	}

	public static function fromArray(mixed $data): ?self {
		if (!is_array($data) || !is_string($data['unit'] ?? null) || preg_match('/^[A-Za-z0-9@._:\\\\-]{1,200}$/', $data['unit']) !== 1) {
			return null;
		}

		return new self($data['unit'], Values::int($data['since'] ?? null));
	}

	public function withSince(?int $since): self {
		return new self($this->unit, $since);
	}
}
