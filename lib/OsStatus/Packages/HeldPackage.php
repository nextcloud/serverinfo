<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\Values;

/**
 * A package the admin has excluded from upgrades.
 */
final class HeldPackage {
	public function __construct(
		public readonly string $name,
		/** How it is held, e.g. "apt-mark hold", "dnf versionlock" */
		public readonly string $mechanism,
		/** The version or constraint it is held at, when known */
		public readonly ?string $constraint = null,
	) {
	}

	/**
	 * @return array{name: string, mechanism: string, constraint: ?string}
	 */
	public function toArray(): array {
		return [
			'name' => $this->name,
			'mechanism' => $this->mechanism,
			'constraint' => $this->constraint,
		];
	}

	public static function fromArray(mixed $data): ?self {
		if (!is_array($data) || !is_string($data['name'] ?? null) || !is_string($data['mechanism'] ?? null)) {
			return null;
		}

		return new self($data['name'], $data['mechanism'], Values::string($data['constraint'] ?? null));
	}
}
