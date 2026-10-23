<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Host\CommandResult;
use OCA\ServerInfo\OsStatus\Host\IHost;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;

/**
 * Fedora, RHEL and their rebuilds, with dnf 4 or dnf 5.
 *
 * dnf 4 with --cacheonly lets an unprivileged user read root's system cache,
 * which dnf-makecache.timer keeps fresh. dnf 5 does not: it needs a writable
 * home and copies the whole system cache there (hundreds of megabytes) before
 * answering, so it is never run as the web server. On dnf 5 systems pending
 * updates come from the root helper, and locks are read from their file.
 *
 * @psalm-api
 */
class Dnf implements IPackageManager {
	/** check-update's exit code when updates are available */
	private const EXIT_UPDATES = 100;

	private const AUTOMATIC_TIMERS = [
		'dnf-automatic-install.timer' => true,
		'dnf5-automatic.timer' => null,
		'dnf-automatic.timer' => null,
		'dnf-automatic-download.timer' => false,
		'dnf-automatic-notifyonly.timer' => false,
	];

	private ?bool $isDnf5 = null;

	public function __construct(
		private IHost $host,
		private Rpm $rpm,
		private SystemdProbe $systemd,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'dnf';
	}

	#[\Override]
	public function isAvailable(): bool {
		return $this->host->hasBinary('dnf') || $this->host->hasBinary('dnf5');
	}

	#[\Override]
	public function classifiesSecurity(): bool {
		return true;
	}

	#[\Override]
	public function getPendingUpdates(): array {
		if ($this->isDnf5()) {
			throw new ProbeException('dnf 5 can only be asked about pending updates as root, so the root helper reports them on this system.');
		}

		$result = $this->dnf(['-q', '--cacheonly', 'check-update'], 180);
		if ($result === null) {
			throw new ProbeException('dnf could not be run.');
		}
		if ($result->exitCode !== 0 && $result->exitCode !== self::EXIT_UPDATES) {
			throw new ProbeException('dnf check-update failed: ' . ProbeHelper::firstLine($result->stderr ?: $result->stdout));
		}

		$available = self::parseCheckUpdate($result->stdout);
		if ($available === []) {
			return [];
		}

		$advisories = $this->securityAdvisories();
		$installed = $this->rpm->getInstalledVersions();

		$updates = [];
		foreach ($available as $name => [$version, $repository]) {
			$advisory = $advisories[$name] ?? null;
			$updates[] = new PendingUpdate(
				$name,
				$installed[$name] ?? null,
				$version,
				$advisory !== null,
				$repository,
				$advisory['ids'] ?? [],
				$advisory['severity'] ?? null,
			);
		}

		return $updates;
	}

	/**
	 * Parses `dnf check-update`: "name.arch  version-release  repository",
	 * up to the "Obsoleting Packages" section.
	 *
	 * @return array<string, array{0: string, 1: string}> name => [version, repository]
	 */
	public static function parseCheckUpdate(string $output): array {
		$updates = [];
		foreach (preg_split('/\R/', $output) ?: [] as $line) {
			if (preg_match('/^Obsoleting|^Security:/i', $line) === 1) {
				break;
			}
			if (preg_match('/^(\S+)\.(x86_64|aarch64|i[3-6]86|noarch|ppc64le|s390x|armv7hl|riscv64)\s+(\S+)\s+(\S+)\s*$/', $line, $matches) === 1) {
				$updates[$matches[1]] = [$matches[3], $matches[4]];
			}
		}

		return $updates;
	}

	/**
	 * Security advisories for the available updates, by package name.
	 *
	 * @return array<string, array{ids: list<string>, severity: ?string}>
	 */
	private function securityAdvisories(): array {
		$result = $this->dnf(['-q', '--cacheonly', 'updateinfo', 'list', '--security', '--available'], 180);
		if ($result === null || !$result->succeeded()) {
			throw new ProbeException('dnf could not list security advisories: ' . ProbeHelper::firstLine($result?->stderr ?? ''));
		}

		return self::parseAdvisories($result->stdout);
	}

	/**
	 * Parses the advisory list of dnf 4 ("RHSA-2026:1234 Important/Sec. nevra")
	 * and dnf 5 ("FEDORA-2026-abc security Moderate nevra date").
	 *
	 * @return array<string, array{ids: list<string>, severity: ?string}>
	 */
	public static function parseAdvisories(string $output): array {
		$advisories = [];
		foreach (preg_split('/\R/', $output) ?: [] as $line) {
			$columns = preg_split('/\s+/', trim($line)) ?: [];
			if (count($columns) < 3 || preg_match('/^[A-Z][A-Za-z0-9]*-[\w:.-]+$/', $columns[0]) !== 1) {
				continue;
			}

			$severity = null;
			$nevra = null;
			if (preg_match('#^(\w+)/Sec\.$#', $columns[1], $matches) === 1) {
				// dnf 4
				$severity = $matches[1] === 'None' ? null : $matches[1];
				$nevra = $columns[2];
			} elseif ($columns[1] === 'security' && isset($columns[3])) {
				// dnf 5
				$severity = in_array($columns[2], ['None', 'none', 'Unknown'], true) ? null : $columns[2];
				$nevra = $columns[3];
			}

			$split = $nevra !== null ? Rpm::splitNevra($nevra) : null;
			if ($split === null) {
				continue;
			}

			$name = $split[0];
			$advisories[$name] ??= ['ids' => [], 'severity' => null];
			if (!in_array($columns[0], $advisories[$name]['ids'], true)) {
				$advisories[$name]['ids'][] = $columns[0];
			}
			$advisories[$name]['severity'] = PendingUpdate::higherSeverity($advisories[$name]['severity'], $severity);
		}

		return $advisories;
	}

	#[\Override]
	public function getHeldPackages(): ?array {
		if ($this->isDnf5()) {
			$locks = $this->host->readFile('/etc/dnf/versionlock.toml', 262144);

			return $locks === null ? ($this->host->fileExists('/etc/dnf') ? [] : null) : self::parseVersionlockToml($locks);
		}

		// Without --cacheonly, dnf 4 downloads the full repository metadata
		// into a per-user cache just to list the locks.
		$result = $this->dnf(['-q', '--cacheonly', 'versionlock', 'list'], 60);
		if ($result === null || !$result->succeeded()) {
			return null;
		}

		return self::parseVersionlock($result->stdout);
	}

	/**
	 * dnf 4 prints one locked NEVRA pattern per line, dnf 5 a block per
	 * package with a "Package name:" line.
	 *
	 * @return list<HeldPackage>
	 */
	public static function parseVersionlock(string $output): array {
		$held = [];
		if (preg_match_all('/^Package name:\s*(\S+)/mi', $output, $matches) > 0) {
			foreach ($matches[1] as $name) {
				$held[$name] = new HeldPackage($name, 'dnf versionlock');
			}

			return array_values($held);
		}

		foreach (preg_split('/\R/', $output) ?: [] as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || str_starts_with($line, 'Last metadata')) {
				continue;
			}
			$pattern = preg_replace('/\.\*$/', '', $line) ?? $line;
			$split = Rpm::splitNevra($pattern);
			$name = $split[0] ?? $pattern;
			if (preg_match('/^[A-Za-z0-9._+-]+$/', $name) === 1) {
				$held[$name] = new HeldPackage($name, 'dnf versionlock', $split[1] ?? null);
			}
		}

		return array_values($held);
	}

	/**
	 * dnf 5 keeps its locks in /etc/dnf/versionlock.toml, readable by everyone.
	 *
	 * @return list<HeldPackage>
	 */
	public static function parseVersionlockToml(string $toml): array {
		$held = [];
		$current = null;
		foreach (preg_split('/\R/', $toml) ?: [] as $line) {
			$line = trim($line);
			if ($line === '[[packages]]') {
				$current = null;
			} elseif ($current === null && preg_match('/^name\s*=\s*"([A-Za-z0-9._+-]+)"$/', $line, $matches) === 1) {
				$current = $matches[1];
				$held[$current] = new HeldPackage($current, 'dnf versionlock');
			} elseif ($current !== null && preg_match('/^value\s*=\s*"([^"]{1,100})"$/', $line, $matches) === 1) {
				$held[$current] = new HeldPackage($current, 'dnf versionlock', $matches[1]);
			}
		}

		return array_values($held);
	}

	/**
	 * Both dnf versions rewrite each repository's metalink.xml when they
	 * refresh it, whether or not the metadata changed.
	 */
	#[\Override]
	public function getListsRefreshedAt(): ?int {
		return ProbeHelper::newestModified($this->host, [
			'/var/cache/dnf/*/metalink.xml',
			'/var/cache/libdnf5/*/metalink.xml',
			'/var/cache/dnf/*/repodata/repomd.xml',
			'/var/cache/libdnf5/*/repodata/repomd.xml',
			'/var/cache/dnf/last_makecache',
		]);
	}

	#[\Override]
	public function getLastUpgradeAt(): ?int {
		return $this->rpm->getLastInstallTime();
	}

	#[\Override]
	public function getAutomaticUpdates(): AutomaticUpdates {
		$config = $this->host->readFile('/etc/dnf/automatic.conf', 65536)
			?? $this->host->readFile('/etc/dnf/dnf5-plugins/automatic.conf', 65536)
			?? '';
		$applies = preg_match('/^\s*apply_updates\s*=\s*(yes|true|1)\s*$/mi', $config) === 1;
		$securityOnly = preg_match('/^\s*upgrade_type\s*=\s*security\s*$/mi', $config) === 1;

		$known = false;
		foreach (self::AUTOMATIC_TIMERS as $timer => $installs) {
			$state = $this->systemd->getUnitFileState($timer);
			if ($state === null || $state === 'not-found') {
				continue;
			}
			$known = true;
			if ($state !== 'enabled') {
				continue;
			}

			$installs ??= $applies;
			if (!$installs) {
				return new AutomaticUpdates(AutomaticUpdates::STATE_DISABLED, 'dnf-automatic', AutomaticUpdates::SCOPE_DOWNLOAD);
			}

			return new AutomaticUpdates(
				AutomaticUpdates::STATE_ENABLED,
				'dnf-automatic',
				$securityOnly ? AutomaticUpdates::SCOPE_SECURITY : AutomaticUpdates::SCOPE_ALL,
			);
		}

		return $known || $this->systemd->isRunning()
			? new AutomaticUpdates(AutomaticUpdates::STATE_DISABLED)
			: AutomaticUpdates::unknown();
	}

	/**
	 * `needs-restarting -r` exits 1 when core packages were updated since boot.
	 */
	#[\Override]
	public function getRebootReasons(): array {
		if ($this->isDnf5()) {
			return [];
		}

		$result = $this->dnf(['-q', '--cacheonly', 'needs-restarting', '-r'], 120);
		if ($result === null || $result->exitCode !== 1 || $result->timedOut) {
			return [];
		}

		$packages = [];
		if (preg_match_all('/^\s*\*\s*(\S+)/m', $result->stdout, $matches) > 0) {
			$packages = array_values(array_unique($matches[1]));
		}

		return [new RebootReason(RebootReason::KIND_DISTRIBUTION, null, $packages)];
	}

	/**
	 * dnf 5's needs-restarting cannot run unprivileged; there the generic
	 * kernel and core library checks answer instead.
	 */
	#[\Override]
	public function hasOwnRebootCheck(): bool {
		if ($this->isDnf5()) {
			return false;
		}

		$result = $this->dnf(['-q', 'needs-restarting', '--help'], 30);

		return $result !== null && $result->succeeded();
	}

	#[\Override]
	public function getUpgradeCommands(): array {
		return [
			'all' => 'sudo dnf upgrade --refresh',
			'security' => 'sudo dnf upgrade --refresh --security',
		];
	}

	#[\Override]
	public function getPackageDatabasePath(): ?string {
		return $this->rpm->getDatabasePath();
	}

	/**
	 * Told apart by the binary rather than by running dnf, because running
	 * dnf 5 as the web server is what has to be avoided.
	 */
	private function isDnf5(): bool {
		return $this->isDnf5 ??= $this->host->hasBinary('dnf5');
	}

	/**
	 * @param list<string> $arguments
	 */
	private function dnf(array $arguments, int $timeout): ?CommandResult {
		return $this->host->run($this->host->hasBinary('dnf') ? 'dnf' : 'dnf5', $arguments, $timeout);
	}
}
