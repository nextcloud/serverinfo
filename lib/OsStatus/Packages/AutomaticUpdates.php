<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\Values;

/**
 * Whether the system installs updates by itself.
 */
final class AutomaticUpdates {
	public const STATE_ENABLED = 'enabled';
	public const STATE_DISABLED = 'disabled';
	public const STATE_UNKNOWN = 'unknown';

	/** Only security updates are installed automatically */
	public const SCOPE_SECURITY = 'security';
	/** Every update is installed automatically */
	public const SCOPE_ALL = 'all';
	/** Updates are downloaded or announced, but not installed */
	public const SCOPE_DOWNLOAD = 'download';
	public const SCOPE_UNKNOWN = 'unknown';

	/**
	 * @param self::STATE_* $state
	 * @param self::SCOPE_* $scope
	 */
	public function __construct(
		public readonly string $state,
		/** The tool doing it, e.g. "unattended-upgrades", "dnf-automatic" */
		public readonly ?string $mechanism = null,
		public readonly string $scope = self::SCOPE_UNKNOWN,
	) {
	}

	public static function unknown(): self {
		return new self(self::STATE_UNKNOWN);
	}

	/**
	 * @return array{state: string, mechanism: ?string, scope: string}
	 */
	public function toArray(): array {
		return [
			'state' => $this->state,
			'mechanism' => $this->mechanism,
			'scope' => $this->scope,
		];
	}

	public static function fromArray(mixed $data): self {
		if (!is_array($data)) {
			return self::unknown();
		}

		$state = in_array($data['state'] ?? null, [self::STATE_ENABLED, self::STATE_DISABLED], true) ? $data['state'] : self::STATE_UNKNOWN;
		$scope = in_array($data['scope'] ?? null, [self::SCOPE_SECURITY, self::SCOPE_ALL, self::SCOPE_DOWNLOAD], true) ? $data['scope'] : self::SCOPE_UNKNOWN;

		return new self($state, Values::string($data['mechanism'] ?? null), $scope);
	}
}
