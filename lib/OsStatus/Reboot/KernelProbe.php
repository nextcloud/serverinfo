<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Reboot;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Compares the running kernel with the kernels installed on disk.
 *
 * Works the same on every distribution because it only looks at
 * /lib/modules, which every kernel package fills and which anyone can list.
 *
 * @psalm-api
 */
class KernelProbe {
	private const MODULE_ROOTS = ['/lib/modules', '/usr/lib/modules'];

	/** Early boot can touch files before the clock settles. */
	private const BOOT_SLACK = 60;

	public function __construct(
		private IHost $host,
	) {
	}

	/**
	 * @param int|null $bootTime unix timestamp of the last boot, null when unknown
	 * @return list<RebootReason>
	 */
	public function getRebootReasons(?int $bootTime = null): array {
		$running = trim($this->host->uname('r'));
		$installed = $this->installedKernels();
		if ($running === '' || $installed === []) {
			// Nothing to compare against: a container, or /lib/modules hidden.
			return [];
		}

		$flavour = self::flavour($running);
		$sameFlavour = array_values(array_filter(
			array_keys($installed),
			static fn (string $release): bool => self::flavour($release) === $flavour,
		));

		if (!isset($installed[$running])) {
			return $this->removed($installed, $sameFlavour, $bootTime);
		}

		$newest = $this->newest($sameFlavour);
		if ($newest === null || $newest === $running || version_compare($newest, $running, '<=')) {
			return [];
		}

		return [new RebootReason(RebootReason::KIND_KERNEL, $newest, [], $installed[$newest])];
	}

	/**
	 * The running kernel has no modules on disk. Arch and others delete them
	 * when they upgrade the kernel, but a kernel the provider or a netboot
	 * server supplies never had any, and no reboot changes that. So this is
	 * only a reason when an installed kernel of the same build exists, or
	 * when the newest one was installed after the boot.
	 *
	 * @param array<string, ?int> $installed
	 * @param list<string> $sameFlavour
	 * @return list<RebootReason>
	 */
	private function removed(array $installed, array $sameFlavour, ?int $bootTime): array {
		$newest = $this->newest($sameFlavour);
		if ($newest === null) {
			$newest = $this->newest(array_keys($installed));
			$installedAt = $newest !== null ? $installed[$newest] : null;
			if ($newest === null || $bootTime === null || $installedAt === null || $installedAt <= $bootTime + self::BOOT_SLACK) {
				return [];
			}
		}

		return [new RebootReason(RebootReason::KIND_KERNEL_REMOVED, $newest, [], $installed[$newest])];
	}

	/**
	 * Installed kernel releases, each with when it was installed.
	 *
	 * Only directories with a modules.dep count: removing a kernel package can
	 * leave its directory behind with DKMS leftovers in it.
	 *
	 * @return array<string, ?int>
	 */
	public function installedKernels(): array {
		foreach (self::MODULE_ROOTS as $root) {
			$kernels = [];
			foreach ($this->host->glob($root . '/*/modules.dep') as $file) {
				$release = basename(dirname($file));
				$kernels[$release] = $this->host->changedAt($file);
			}

			if ($kernels !== []) {
				return $kernels;
			}
		}

		return [];
	}

	/**
	 * Kernels of one build only differ in their numbers, so replacing every
	 * run of digits leaves what tells "generic" from "lowlatency", or "lts"
	 * from the mainline kernel.
	 */
	public static function flavour(string $release): string {
		return (string)preg_replace('/\d+/', 'N', $release);
	}

	/**
	 * @param list<string> $releases
	 */
	private function newest(array $releases): ?string {
		if ($releases === []) {
			return null;
		}

		usort($releases, static fn (string $a, string $b): int => version_compare($b, $a));

		return $releases[0];
	}
}
