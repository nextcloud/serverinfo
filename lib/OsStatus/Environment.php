<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

/**
 * Where Nextcloud runs: directly on a machine, or inside a container.
 *
 * The difference decides which questions make sense. An application
 * container (Docker, Podman, Kubernetes) is replaced rather than updated, so
 * its packages and reboots are not the admin's to manage from inside. A
 * system container (LXC, systemd-nspawn) is managed like a machine, except
 * that the kernel belongs to the host.
 */
final class Environment {
	public const KIND_HOST = 'host';
	public const KIND_APPLICATION_CONTAINER = 'application-container';
	public const KIND_SYSTEM_CONTAINER = 'system-container';
	public const KIND_SNAP = 'snap';

	/**
	 * @param self::KIND_* $kind
	 * @param string|null $container technology, e.g. "docker", "lxc"
	 * @param string|null $virtualization hypervisor, e.g. "kvm", "vmware", "wsl"
	 */
	public function __construct(
		public readonly string $kind,
		public readonly ?string $container = null,
		public readonly ?string $virtualization = null,
	) {
	}

	/**
	 * Whether packages are installed and upgraded from inside.
	 */
	public function managesPackages(): bool {
		return $this->kind === self::KIND_HOST || $this->kind === self::KIND_SYSTEM_CONTAINER;
	}

	/**
	 * Whether a kernel upgrade here is booted by rebooting this system.
	 */
	public function ownsKernel(): bool {
		return $this->kind === self::KIND_HOST && $this->virtualization !== 'wsl';
	}

	/**
	 * @return array{kind: string, container: ?string, virtualization: ?string}
	 */
	public function toArray(): array {
		return [
			'kind' => $this->kind,
			'container' => $this->container,
			'virtualization' => $this->virtualization,
		];
	}

	public static function fromArray(mixed $data): self {
		$kind = is_array($data) ? ($data['kind'] ?? null) : null;
		if (!in_array($kind, [self::KIND_HOST, self::KIND_APPLICATION_CONTAINER, self::KIND_SYSTEM_CONTAINER, self::KIND_SNAP], true)) {
			return new self(self::KIND_HOST);
		}

		/** @var array<string, mixed> $data */
		return new self(
			$kind,
			Values::string($data['container'] ?? null),
			Values::string($data['virtualization'] ?? null),
		);
	}
}
