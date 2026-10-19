<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Reboot;

use OCA\ServerInfo\OsStatus\Values;

/**
 * One reason the machine needs to be rebooted.
 */
final class RebootReason {
	/** A newer kernel is installed than the one running */
	public const KIND_KERNEL = 'kernel';
	/** The running kernel's modules were removed, so new hardware or filesystems cannot load */
	public const KIND_KERNEL_REMOVED = 'kernel-removed';
	/** The distribution's own tooling says so */
	public const KIND_DISTRIBUTION = 'distribution';
	/** A library every process uses, such as the C library, changed since boot */
	public const KIND_CORE_LIBRARY = 'core-library';
	/** Newer CPU microcode is installed than the one loaded */
	public const KIND_MICROCODE = 'microcode';

	public const KINDS = [self::KIND_KERNEL, self::KIND_KERNEL_REMOVED, self::KIND_DISTRIBUTION, self::KIND_CORE_LIBRARY, self::KIND_MICROCODE];

	/**
	 * @param self::KIND_* $kind
	 * @param list<string> $packages packages or files behind the reason
	 */
	public function __construct(
		public readonly string $kind,
		/** e.g. the newer kernel version */
		public readonly ?string $detail = null,
		public readonly array $packages = [],
		/** When the reason first applied, as a unix timestamp */
		public readonly ?int $since = null,
	) {
	}

	/**
	 * Whether a live kernel patch can stand in for this reboot.
	 */
	public function isKernelOnly(): bool {
		return $this->kind === self::KIND_KERNEL;
	}

	public function key(): string {
		return $this->kind . ':' . ($this->detail ?? '');
	}

	/**
	 * @return array{kind: string, detail: ?string, packages: list<string>, since: ?int}
	 */
	public function toArray(): array {
		return [
			'kind' => $this->kind,
			'detail' => $this->detail,
			'packages' => $this->packages,
			'since' => $this->since,
		];
	}

	public static function fromArray(mixed $data): ?self {
		if (!is_array($data) || !in_array($data['kind'] ?? null, self::KINDS, true)) {
			return null;
		}

		return new self(
			$data['kind'],
			Values::string($data['detail'] ?? null),
			Values::strings($data['packages'] ?? null),
			Values::int($data['since'] ?? null),
		);
	}

	public function withSince(?int $since): self {
		return new self($this->kind, $this->detail, $this->packages, $since);
	}
}
