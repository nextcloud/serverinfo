<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * @psalm-api
 */
class EnvironmentDetector {
	/** Containers that run a whole distribution, managed from inside. */
	private const SYSTEM_CONTAINERS = ['lxc', 'lxc-libvirt', 'systemd-nspawn', 'openvz', 'incus', 'lxd'];

	/** Containers built from an image and replaced to update. */
	private const APPLICATION_CONTAINERS = ['docker', 'podman', 'kubernetes', 'rkt', 'oci', 'containerd', 'container-other'];

	/** DMI vendor or product fragments and the hypervisor they reveal. */
	private const DMI_HYPERVISORS = [
		'QEMU' => 'kvm',
		'KVM' => 'kvm',
		'VMware' => 'vmware',
		'VirtualBox' => 'oracle',
		'innotek' => 'oracle',
		'Xen' => 'xen',
		'Amazon EC2' => 'amazon',
		'Google Compute Engine' => 'google',
		'Virtual Machine' => 'microsoft',
		'Parallels' => 'parallels',
		'Bochs' => 'bochs',
	];

	public function __construct(
		private IHost $host,
	) {
	}

	public function detect(): Environment {
		$virtualization = $this->detectVirtualization();

		if ($this->host->environment('SNAP') !== null && $this->host->environment('SNAP_NAME') !== null) {
			return new Environment(Environment::KIND_SNAP, 'snap', $virtualization);
		}

		$container = $this->detectContainer();
		if ($container === 'wsl') {
			return new Environment(Environment::KIND_HOST, null, 'wsl');
		}
		if ($container !== null) {
			$kind = in_array($container, self::SYSTEM_CONTAINERS, true)
				? Environment::KIND_SYSTEM_CONTAINER
				: Environment::KIND_APPLICATION_CONTAINER;

			return new Environment($kind, $container, $virtualization);
		}

		return new Environment(Environment::KIND_HOST, null, $virtualization);
	}

	private function detectContainer(): ?string {
		// systemd writes the container manager it was started under here.
		$marker = $this->host->readFile('/run/systemd/container', 256);
		if ($marker !== null && ($name = $this->normalizeContainer(trim($marker))) !== null) {
			return $name;
		}

		if ($this->host->fileExists('/.dockerenv')) {
			return 'docker';
		}
		if ($this->host->fileExists('/run/.containerenv')) {
			return 'podman';
		}
		if ($this->host->environment('KUBERNETES_SERVICE_HOST') !== null) {
			return 'kubernetes';
		}

		$result = $this->host->run('systemd-detect-virt', ['--container'], 5);
		if ($result !== null && $result->succeeded() && ($name = $this->normalizeContainer(trim($result->stdout))) !== null) {
			return $name;
		}

		$cgroup = $this->host->readFile('/proc/1/cgroup', 65536);
		if ($cgroup !== null) {
			foreach (['kubepods' => 'kubernetes', 'docker' => 'docker', 'libpod' => 'podman', '/lxc' => 'lxc'] as $needle => $name) {
				if (str_contains($cgroup, $needle)) {
					return $name;
				}
			}
		}

		if ($this->host->fileExists('/proc/vz') && !$this->host->fileExists('/proc/bc')) {
			return 'openvz';
		}

		$release = strtolower($this->host->uname('r'));
		if (str_contains($release, 'microsoft') || str_contains($release, 'wsl')) {
			return 'wsl';
		}

		return null;
	}

	private function normalizeContainer(string $name): ?string {
		$name = strtolower($name);
		if ($name === '' || $name === 'none') {
			return null;
		}
		if ($name === 'wsl' || in_array($name, self::SYSTEM_CONTAINERS, true) || in_array($name, self::APPLICATION_CONTAINERS, true)) {
			return $name;
		}

		return preg_match('/^[a-z0-9._-]{1,32}$/', $name) === 1 ? $name : 'container-other';
	}

	private function detectVirtualization(): ?string {
		$result = $this->host->run('systemd-detect-virt', ['--vm'], 5);
		if ($result !== null && $result->succeeded()) {
			$name = strtolower(trim($result->stdout));
			if ($name !== '' && $name !== 'none' && preg_match('/^[a-z0-9._-]{1,32}$/', $name) === 1) {
				return $name;
			}
		}
		if ($result !== null && trim($result->stdout) === 'none') {
			return null;
		}

		$dmi = trim(($this->host->readFile('/sys/class/dmi/id/sys_vendor', 256) ?? '') . ' '
			. ($this->host->readFile('/sys/class/dmi/id/product_name', 256) ?? ''));
		foreach (self::DMI_HYPERVISORS as $needle => $name) {
			if (str_contains($dmi, $needle)) {
				return $name;
			}
		}

		return null;
	}
}
