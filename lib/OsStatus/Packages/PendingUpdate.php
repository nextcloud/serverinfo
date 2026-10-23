<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\NextcloudStack;
use OCA\ServerInfo\OsStatus\Values;

/**
 * A package that has a newer version waiting in the repositories.
 */
final class PendingUpdate {
	/**
	 * @param list<string> $advisories advisory or CVE identifiers, e.g. "USN-7012-1", "RHSA-2026:1234"
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $installedVersion,
		public readonly string $candidateVersion,
		public readonly bool $security,
		/** Repository, suite or channel the update comes from */
		public readonly ?string $origin = null,
		public readonly array $advisories = [],
		/** Severity the distribution gives the advisory, e.g. "Important" */
		public readonly ?string $severity = null,
		/** When this update was first seen pending, as a unix timestamp */
		public readonly ?int $since = null,
	) {
	}

	/**
	 * @return array{name: string, installed: ?string, candidate: string, security: bool, origin: ?string, advisories: list<string>, severity: ?string, since: ?int, stack: bool}
	 */
	public function toArray(): array {
		return [
			'name' => $this->name,
			'installed' => $this->installedVersion,
			'candidate' => $this->candidateVersion,
			'security' => $this->security,
			'origin' => $this->origin,
			'advisories' => $this->advisories,
			'severity' => $this->severity,
			'since' => $this->since,
			'stack' => $this->isStack(),
		];
	}

	public static function fromArray(mixed $data): ?self {
		if (!is_array($data) || !is_string($data['name'] ?? null) || !is_string($data['candidate'] ?? null)) {
			return null;
		}

		return new self(
			$data['name'],
			Values::string($data['installed'] ?? null),
			$data['candidate'],
			($data['security'] ?? false) === true,
			Values::string($data['origin'] ?? null),
			Values::strings($data['advisories'] ?? null),
			Values::string($data['severity'] ?? null),
			Values::int($data['since'] ?? null),
		);
	}

	/**
	 * Whether this updates something Nextcloud runs on.
	 */
	public function isStack(): bool {
		return NextcloudStack::isStackPackage($this->name);
	}

	/**
	 * What the first-seen time is tracked by: the package, not the version, so
	 * a newer update superseding a pending one keeps counting.
	 */
	public function key(): string {
		return $this->name;
	}

	public function withSince(?int $since): self {
		return new self($this->name, $this->installedVersion, $this->candidateVersion, $this->security, $this->origin, $this->advisories, $this->severity, $since);
	}
}
