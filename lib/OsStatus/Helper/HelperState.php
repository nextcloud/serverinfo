<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Helper;

use OCA\ServerInfo\OsStatus\Values;

/**
 * Whether a root helper report was found, and why it was or was not used.
 */
final class HelperState {
	/** No report at the configured path: the helper is not set up */
	public const STATE_ABSENT = 'absent';
	public const STATE_OK = 'ok';
	/** Older than the helper's schedule allows; the cron job probably stopped */
	public const STATE_STALE = 'stale';
	/** Written before the last reboot, so its reboot and restart findings are void */
	public const STATE_PREVIOUS_BOOT = 'previous-boot';
	/** Written on another machine, e.g. a copied data directory */
	public const STATE_OTHER_HOST = 'other-host';
	/** Writable by someone other than root, so it could be forged */
	public const STATE_INSECURE = 'insecure';
	/** Exists but cannot be read, e.g. because of open_basedir */
	public const STATE_UNREADABLE = 'unreadable';
	/** Not valid JSON, too large, or missing required fields */
	public const STATE_INVALID = 'invalid';
	/** Written by a helper version that uses a format this app does not know */
	public const STATE_UNSUPPORTED = 'unsupported';

	public const STATES = [
		self::STATE_ABSENT, self::STATE_OK, self::STATE_STALE, self::STATE_PREVIOUS_BOOT, self::STATE_OTHER_HOST,
		self::STATE_INSECURE, self::STATE_UNREADABLE, self::STATE_INVALID, self::STATE_UNSUPPORTED,
	];

	/**
	 * @param self::STATE_* $state
	 * @param list<string> $errors problems the helper itself reported
	 */
	public function __construct(
		public readonly string $state,
		public readonly string $path,
		public readonly ?int $generatedAt = null,
		public readonly ?string $version = null,
		/** The installed helper is older than the one bundled with the app */
		public readonly bool $outdated = false,
		public readonly array $errors = [],
	) {
	}

	/**
	 * @return array{state: string, path: string, generatedAt: ?int, version: ?string, outdated: bool, errors: list<string>}
	 */
	public function toArray(): array {
		return [
			'state' => $this->state,
			'path' => $this->path,
			'generatedAt' => $this->generatedAt,
			'version' => $this->version,
			'outdated' => $this->outdated,
			'errors' => $this->errors,
		];
	}

	public static function fromArray(mixed $data, string $defaultPath): self {
		if (!is_array($data) || !in_array($data['state'] ?? null, self::STATES, true)) {
			return new self(self::STATE_ABSENT, $defaultPath);
		}

		return new self(
			$data['state'],
			is_string($data['path'] ?? null) ? $data['path'] : $defaultPath,
			Values::int($data['generatedAt'] ?? null),
			Values::string($data['version'] ?? null),
			($data['outdated'] ?? false) === true,
			Values::strings($data['errors'] ?? null),
		);
	}
}
