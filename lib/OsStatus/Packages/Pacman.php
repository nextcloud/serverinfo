<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Arch Linux and its derivatives.
 *
 * `checkupdates` syncs a private copy of the package databases, so it is
 * current without root and without the partial-upgrade hazard of
 * `pacman -Sy`. Security fixes are only known when arch-audit is installed.
 *
 * @psalm-api
 */
class Pacman implements IPackageManager {
	/** checkupdates exits with 2 when there is nothing to update */
	private const EXIT_NO_UPDATES = 2;

	private const LOG = '/var/log/pacman.log';

	public function __construct(
		private IHost $host,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'pacman';
	}

	#[\Override]
	public function isAvailable(): bool {
		return $this->host->hasBinary('pacman');
	}

	#[\Override]
	public function classifiesSecurity(): bool {
		return $this->host->hasBinary('arch-audit');
	}

	#[\Override]
	public function getPendingUpdates(): array {
		if ($this->host->hasBinary('checkupdates')) {
			$result = $this->host->run('checkupdates', [], 180);
			if ($result !== null && ($result->succeeded() || $result->exitCode === self::EXIT_NO_UPDATES)) {
				return $this->withSecurity(self::parseUpdates($result->stdout));
			}
		}

		// Falls back to the databases root last synced.
		$result = $this->host->run('pacman', ['-Qu'], 60);
		if ($result === null) {
			throw new ProbeException('pacman could not be run.');
		}
		// pacman -Qu exits 1 when nothing is upgradable.
		if (!$result->succeeded() && trim($result->stdout) !== '') {
			throw new ProbeException('pacman -Qu failed: ' . mb_substr(trim($result->stderr), 0, 200));
		}

		return $this->withSecurity(self::parseUpdates($result->stdout));
	}

	/**
	 * Parses "name old -> new" lines, as printed by checkupdates and pacman -Qu.
	 * Packages in IgnorePkg are held, not pending: pacman -Qu marks them
	 * "[ignored]", checkupdates leaves them out.
	 *
	 * @return list<PendingUpdate>
	 */
	public static function parseUpdates(string $output): array {
		$updates = [];
		foreach (preg_split('/\R/', $output) ?: [] as $line) {
			if (preg_match('/^(\S+) (\S+) -> (\S+)$/', trim($line), $matches) === 1) {
				$updates[] = new PendingUpdate($matches[1], $matches[2], $matches[3], false);
			}
		}

		return $updates;
	}

	/**
	 * Marks the updates that fix a known vulnerability, according to arch-audit.
	 *
	 * @param list<PendingUpdate> $updates
	 * @return list<PendingUpdate>
	 */
	private function withSecurity(array $updates): array {
		if ($updates === [] || !$this->classifiesSecurity()) {
			return $updates;
		}

		$result = $this->host->run('arch-audit', ['--upgradable', '--quiet'], 120);
		if ($result === null || !$result->succeeded()) {
			throw new ProbeException('arch-audit could not be run.');
		}

		// Each line starts with the package name, followed by a description
		// unless --quiet was honoured.
		$vulnerable = [];
		foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
			if (preg_match('/^([A-Za-z0-9@._+-]+)/', trim($line), $matches) === 1) {
				$vulnerable[$matches[1]] = true;
			}
		}

		return array_map(
			static fn (PendingUpdate $u): PendingUpdate => isset($vulnerable[$u->name])
				? new PendingUpdate($u->name, $u->installedVersion, $u->candidateVersion, true, $u->origin)
				: $u,
			$updates,
		);
	}

	#[\Override]
	public function getHeldPackages(): ?array {
		$config = $this->host->readFile('/etc/pacman.conf', 262144);
		if ($config === null) {
			return null;
		}

		return self::parseIgnored($config);
	}

	/**
	 * @return list<HeldPackage>
	 */
	public static function parseIgnored(string $config): array {
		$held = [];
		if (preg_match_all('/^\s*IgnorePkg\s*=\s*(.+)$/m', $config, $matches) > 0) {
			foreach ($matches[1] as $list) {
				foreach (preg_split('/\s+/', trim(preg_replace('/#.*$/', '', $list) ?? '')) ?: [] as $name) {
					if ($name !== '') {
						$held[$name] = new HeldPackage($name, 'pacman IgnorePkg');
					}
				}
			}
		}

		return array_values($held);
	}

	/**
	 * checkupdates fetches fresh databases itself, so there are no lists to age.
	 */
	#[\Override]
	public function getListsRefreshedAt(): ?int {
		return null;
	}

	#[\Override]
	public function getLastUpgradeAt(): ?int {
		$log = $this->host->readFileTail(self::LOG, 512 * 1024);
		if ($log === null || preg_match_all('/^\[([0-9T:+-]{19,25})\] \[ALPM\] (?:upgraded|installed) /m', $log, $matches) < 1) {
			return $this->host->modifiedAt('/var/lib/pacman/local');
		}

		$time = strtotime((string)end($matches[1]));

		return $time === false ? null : $time;
	}

	/**
	 * Arch has no standard way to upgrade unattended and advises against it.
	 */
	#[\Override]
	public function getAutomaticUpdates(): AutomaticUpdates {
		return AutomaticUpdates::unknown();
	}

	/**
	 * Covered by the generic kernel check: pacman removes the running
	 * kernel's modules when it upgrades the kernel.
	 */
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
		return ['all' => 'sudo pacman -Syu', 'security' => null];
	}

	#[\Override]
	public function getPackageDatabasePath(): ?string {
		return '/var/lib/pacman/local';
	}
}
