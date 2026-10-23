<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\OsStatus\Host\IHost;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Arch Linux and its derivatives.
 *
 * `checkupdates` syncs a private copy of the package databases, so it is
 * current without root and without the partial-upgrade hazard of
 * `pacman -Sy`. Where it cannot run, `pacman -Qu` answers from the
 * databases root last synced. Security fixes are only known when arch-audit
 * is installed. Both use the network, unlike every other query of the
 * operating system status, so the os_allow_network app config value can
 * turn them off.
 *
 * @psalm-api
 */
class Pacman implements IPackageManager, IReportsDiagnostics {
	/** checkupdates exits with 2 when there is nothing to update */
	private const EXIT_NO_UPDATES = 2;

	private const LOG = '/var/log/pacman.log';

	/** When checkupdates last synced its own databases, in this collection */
	private ?int $syncedAt = null;
	private bool $auditFailed = false;
	/** @var list<string> */
	private array $diagnostics = [];

	public function __construct(
		private IHost $host,
		private ITimeFactory $timeFactory,
		private IAppConfig $appConfig,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'pacman';
	}

	#[\Override]
	public function isAvailable(): bool {
		return $this->host->binaryState('pacman') !== false;
	}

	#[\Override]
	public function classifiesSecurity(): bool {
		return !$this->auditFailed && $this->networkAllowed() && $this->host->hasBinary('arch-audit');
	}

	private function networkAllowed(): bool {
		return $this->appConfig->getAppValueBool(ConfigLexicon::OS_ALLOW_NETWORK, true, true);
	}

	#[\Override]
	public function getDiagnostics(): array {
		return $this->diagnostics;
	}

	#[\Override]
	public function getPendingUpdates(): array {
		$this->syncedAt = null;
		$this->auditFailed = false;
		$this->diagnostics = [];

		if ($this->networkAllowed() && $this->host->hasBinary('checkupdates')) {
			$result = $this->host->run('checkupdates', [], 180);
			if ($result !== null && !$result->timedOut && !$result->truncated
				&& ($result->exitCode === 0 || $result->exitCode === self::EXIT_NO_UPDATES)) {
				$this->syncedAt = $this->timeFactory->getTime();

				return $this->withSecurity(self::parseUpdates($result->stdout));
			}
			$this->diagnostics[] = 'checkupdates could not sync the package databases, so pending updates come from the databases root last synced'
				. ($result !== null && trim($result->stderr) !== '' ? ': ' . ProbeHelper::firstLine($result->stderr) : '.');
		}

		$result = $this->host->run('pacman', ['-Qu'], 60);
		if ($result === null) {
			throw new ProbeException('pacman could not be run.');
		}
		// pacman -Qu exits 1 without a word when nothing is upgradable.
		$nothing = $result->exitCode === 1 && trim($result->stdout) === '' && trim($result->stderr) === '';
		if (!$nothing && !$result->succeeded()) {
			throw new ProbeException('pacman -Qu failed: ' . $result->failure(60));
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
			$this->auditFailed = true;
			$this->diagnostics[] = 'arch-audit could not be run, so pending updates are not told apart into security fixes and others'
				. ($result !== null && trim($result->stderr) !== '' ? ': ' . ProbeHelper::firstLine($result->stderr) : '.');

			return $updates;
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
	 * checkupdates fetches fresh databases itself. Otherwise the answer came
	 * from root's databases, which pacman replaces in the sync directory when
	 * they changed upstream; the files keep the mirror's time, the directory
	 * the time of the download.
	 */
	#[\Override]
	public function getListsRefreshedAt(): ?int {
		return $this->syncedAt ?? ProbeHelper::newestModified($this->host, ['/var/lib/pacman/sync/*.db', '/var/lib/pacman/sync']);
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
