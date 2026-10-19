<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Alpine Linux. Most Nextcloud installs on Alpine are containers, where the
 * container check comes first; this covers Alpine hosts and LXC guests.
 *
 * @psalm-api
 */
class Apk implements IPackageManager {
	public function __construct(
		private IHost $host,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'apk';
	}

	#[\Override]
	public function isAvailable(): bool {
		return $this->host->hasBinary('apk');
	}

	#[\Override]
	public function classifiesSecurity(): bool {
		return false;
	}

	#[\Override]
	public function getPendingUpdates(): array {
		$result = $this->host->run('apk', ['list', '--upgradable'], 120);
		if ($result === null) {
			throw new ProbeException('apk could not be run.');
		}
		if (!$result->succeeded()) {
			throw new ProbeException('apk list --upgradable failed: ' . mb_substr(trim($result->stderr), 0, 200));
		}

		// apk lists packages pinned to an exact version too, though it never
		// upgrades them.
		$pinned = [];
		foreach ($this->getHeldPackages() ?? [] as $held) {
			if (str_starts_with($held->constraint ?? '', '=')) {
				$pinned[$held->name] = true;
			}
		}

		return array_values(array_filter(
			self::parseUpgradable($result->stdout),
			static fn (PendingUpdate $update): bool => !isset($pinned[$update->name]),
		));
	}

	/**
	 * Parses `apk list --upgradable`, e.g.
	 * busybox-1.36.1-r16 x86_64 {busybox} (GPL-2.0-only) [upgradable from: busybox-1.36.1-r15]
	 *
	 * @return list<PendingUpdate>
	 */
	public static function parseUpgradable(string $output): array {
		$updates = [];
		foreach (preg_split('/\R/', $output) ?: [] as $line) {
			if (preg_match('/^(\S+)-(\d\S*-r\d+) \S+ \{[^}]*\} \([^)]*\) \[upgradable from: (\S+)\]$/', trim($line), $matches) !== 1) {
				continue;
			}

			$name = $matches[1];
			$installed = str_starts_with($matches[3], $name . '-') ? substr($matches[3], strlen($name) + 1) : null;
			$updates[] = new PendingUpdate($name, $installed, $matches[2], false);
		}

		return $updates;
	}

	/**
	 * Packages pinned to a version or tag in /etc/apk/world.
	 */
	#[\Override]
	public function getHeldPackages(): ?array {
		$world = $this->host->readFile('/etc/apk/world', 262144);
		if ($world === null) {
			return null;
		}

		$held = [];
		foreach (preg_split('/\s+/', trim($world)) ?: [] as $entry) {
			if (preg_match('/^([A-Za-z0-9._+-]+?)((?:[=<>~]|@)\S+)$/', $entry, $matches) === 1) {
				$held[] = new HeldPackage($matches[1], 'apk world', $matches[2]);
			}
		}

		return $held;
	}

	#[\Override]
	public function getListsRefreshedAt(): ?int {
		$times = [];
		foreach ($this->host->glob('/var/cache/apk/APKINDEX.*.tar.gz') as $index) {
			$time = $this->host->modifiedAt($index);
			if ($time !== null) {
				$times[] = $time;
			}
		}

		return $times === [] ? null : max($times);
	}

	#[\Override]
	public function getLastUpgradeAt(): ?int {
		return $this->host->modifiedAt('/lib/apk/db/installed');
	}

	#[\Override]
	public function getAutomaticUpdates(): AutomaticUpdates {
		return AutomaticUpdates::unknown();
	}

	#[\Override]
	public function getRebootReasons(): array {
		return [];
	}

	#[\Override]
	public function hasOwnRebootCheck(): bool {
		return false;
	}

	#[\Override]
	public function getUpgradeCommands(): array {
		return ['all' => 'sudo apk upgrade --update-cache', 'security' => null];
	}

	#[\Override]
	public function getPackageDatabasePath(): ?string {
		return '/lib/apk/db/installed';
	}
}
