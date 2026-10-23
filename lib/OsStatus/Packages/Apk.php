<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Alpine Linux and its derivatives such as postmarketOS. Most Nextcloud
 * installs on Alpine are containers, where the container check comes first;
 * this covers Alpine hosts and LXC guests. apk only reads its cached indexes
 * here and never goes online.
 *
 * @psalm-api
 */
class Apk implements IPackageManager {
	private const WORLD = '/etc/apk/world';

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
		$result = $this->host->run('apk', ['--no-network', 'list', '--upgradable'], 120);
		if ($result === null) {
			throw new ProbeException('apk could not be run.');
		}
		if (!$result->succeeded()) {
			throw new ProbeException('apk list --upgradable failed: ' . ProbeHelper::firstLine($result->stderr ?: $result->stdout));
		}

		// apk lists every newer version it knows of, including those the
		// constraints in /etc/apk/world keep it from installing.
		$constraints = [];
		foreach (self::parseWorld($this->host->readFile(self::WORLD, 262144) ?? '') as [$name, $operator, $version]) {
			if ($operator !== null) {
				$constraints[$name][] = [$operator, $version];
			}
		}

		return array_values(array_filter(
			self::parseUpgradable($result->stdout),
			static function (PendingUpdate $update) use ($constraints): bool {
				foreach ($constraints[$update->name] ?? [] as [$operator, $version]) {
					if (!self::satisfies($update->candidateVersion, $operator, $version)) {
						return false;
					}
				}

				return true;
			},
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
	 * Packages whose version /etc/apk/world restricts in a way that can hold
	 * back an upgrade: "=", "<", "<=" and the fuzzy "~". A repository tag
	 * alone ("curl@edge") only lets the package come from the tagged
	 * repository as well, and upgrades go on from there.
	 */
	#[\Override]
	public function getHeldPackages(): ?array {
		$world = $this->host->readFile(self::WORLD, 262144);
		if ($world === null) {
			return null;
		}

		$held = [];
		foreach (self::parseWorld($world) as [$name, $operator, $version, $tag]) {
			if ($operator !== null && !in_array($operator, ['>', '>='], true)) {
				$held[] = new HeldPackage($name, 'apk world', ($tag ?? '') . $operator . $version);
			}
		}

		return $held;
	}

	/**
	 * Parses the dependencies in /etc/apk/world: name[@tag][operator version].
	 * Conflicts ("!name") are left out.
	 *
	 * @return list<array{0: string, 1: ?string, 2: string, 3: ?string}> [name, operator, version, tag]
	 */
	public static function parseWorld(string $world): array {
		$entries = [];
		foreach (preg_split('/\s+/', trim($world)) ?: [] as $entry) {
			if (preg_match('/^([A-Za-z0-9][A-Za-z0-9._+-]*?)(@[A-Za-z0-9._-]+)?(?:(<=|>=|~=|=~|[<>=~])(\S+))?$/', $entry, $matches) !== 1) {
				continue;
			}
			$operator = ($matches[3] ?? '') !== '' ? $matches[3] : null;
			$entries[] = [
				$matches[1],
				$operator === '~=' || $operator === '=~' ? '~' : $operator,
				$matches[4] ?? '',
				($matches[2] ?? '') !== '' ? $matches[2] : null,
			];
		}

		return $entries;
	}

	/**
	 * Whether a version meets a world constraint. "~1.2" matches 1.2 and
	 * every 1.2.x or 1.2-rN.
	 */
	public static function satisfies(string $version, string $operator, string $constraint): bool {
		if ($operator === '~') {
			return $version === $constraint
				|| (str_starts_with($version, $constraint) && in_array($version[strlen($constraint)], ['.', '-', '_'], true));
		}

		$order = self::compareVersions($version, $constraint);

		return match ($operator) {
			'=' => $order === 0,
			'<' => $order < 0,
			'<=' => $order <= 0,
			'>' => $order > 0,
			'>=' => $order >= 0,
			default => true,
		};
	}

	/**
	 * Compares apk versions such as "3.5.8-r0" or "1.2_rc1-r3": the
	 * upstream version as PHP's version_compare() orders it, then the -rN
	 * package release.
	 */
	public static function compareVersions(string $a, string $b): int {
		[$baseA, $releaseA] = self::splitRelease($a);
		[$baseB, $releaseB] = self::splitRelease($b);

		return version_compare($baseA, $baseB) ?: $releaseA <=> $releaseB;
	}

	/**
	 * @return array{0: string, 1: int}
	 */
	private static function splitRelease(string $version): array {
		$release = 0;
		if (preg_match('/^(.*)-r(\d+)$/', $version, $matches) === 1) {
			$version = $matches[1];
			$release = (int)$matches[2];
		}

		// apk's _pre sorts like a release candidate; version_compare() does not know it.
		return [str_replace(['_pre', '_'], ['.rc', '.'], $version), $release];
	}

	#[\Override]
	public function getListsRefreshedAt(): ?int {
		return ProbeHelper::newestModified($this->host, ['/var/cache/apk/APKINDEX.*.tar.gz']);
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
