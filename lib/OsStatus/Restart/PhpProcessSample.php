<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Restart;

use OCA\ServerInfo\OsStatus\Values;

/**
 * What a web request saw of its own PHP process.
 */
final class PhpProcessSample {
	/**
	 * @param list<string> $outdatedLibraries basenames of libraries replaced since the process started
	 */
	public function __construct(
		public readonly int $sampledAt,
		public readonly string $sapi,
		public readonly array $outdatedLibraries,
		/** systemd unit or init script to restart, e.g. "php8.3-fpm" */
		public readonly ?string $service,
		/** AppArmor profile or SELinux context of the web server */
		public readonly ?string $confinement,
	) {
	}

	public function isOutdated(): bool {
		return $this->outdatedLibraries !== [];
	}

	/**
	 * @return array{sampledAt: int, sapi: string, outdatedLibraries: list<string>, service: ?string, confinement: ?string}
	 */
	public function toArray(): array {
		return [
			'sampledAt' => $this->sampledAt,
			'sapi' => $this->sapi,
			'outdatedLibraries' => $this->outdatedLibraries,
			'service' => $this->service,
			'confinement' => $this->confinement,
		];
	}

	public static function fromArray(mixed $data): ?self {
		if (!is_array($data) || !is_int($data['sampledAt'] ?? null) || !is_string($data['sapi'] ?? null)) {
			return null;
		}

		return new self(
			$data['sampledAt'],
			$data['sapi'],
			Values::strings($data['outdatedLibraries'] ?? null),
			Values::string($data['service'] ?? null),
			Values::string($data['confinement'] ?? null),
		);
	}
}
