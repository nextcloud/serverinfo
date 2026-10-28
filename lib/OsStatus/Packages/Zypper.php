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
use OCA\ServerInfo\OsStatus\OsRelease;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;

/**
 * openSUSE and SUSE Linux Enterprise.
 *
 * SUSE ships security fixes as patches, each an advisory covering one or
 * more packages. The packages a needed security patch covers are reported as
 * security updates carrying its advisories, like on other distributions; a
 * patch whose packages are not listed is reported on its own. zypper's XML
 * output is used where it has one, because its tables are meant for people.
 *
 * @psalm-api
 */
class Zypper implements IPackageManager, IReportsDiagnostics {
	/** `zypper needs-rebooting` exits with this when a reboot is needed */
	private const EXIT_REBOOT_NEEDED = 102;

	/**
	 * Informational exit codes, after which the output is complete: updates
	 * or security updates needed (100, 101), reboot or restart needed (102,
	 * 103), a name not found (104) and repositories skipped (106). 105 means
	 * zypper was interrupted.
	 */
	private const EXIT_INFORMATIONAL = [100, 101, 102, 103, 104, 106];

	/** Some repositories could not be read and were left out */
	private const EXIT_REPOS_SKIPPED = 106;

	/** Another process holds the package manager lock */
	private const EXIT_LOCKED = 7;

	private const ROLLING = ['opensuse-tumbleweed', 'opensuse-microos', 'opensuse-slowroll'];

	private ?OsRelease $release = null;
	/** @var list<string> */
	private array $diagnostics = [];

	/** Patches looked up at once; more stay patch rows of their own. */
	private const MAX_PATCH_LOOKUP = 100;

	public function __construct(
		private IHost $host,
		private Rpm $rpm,
		private SystemdProbe $systemd,
	) {
	}

	#[\Override]
	public function getId(): string {
		return 'zypper';
	}

	#[\Override]
	public function isAvailable(): bool {
		return $this->host->binaryState('zypper') !== false;
	}

	/**
	 * The same package manager, for the given distribution.
	 */
	public function withRelease(OsRelease $release): self {
		$clone = clone $this;
		$clone->release = $release;

		return $clone;
	}

	/**
	 * Tumbleweed and MicroOS publish no patches, only new package versions,
	 * so there is nothing to tell security fixes by.
	 */
	#[\Override]
	public function classifiesSecurity(): bool {
		return !$this->isRolling();
	}

	private function isRolling(): bool {
		return in_array($this->release?->id, self::ROLLING, true);
	}

	#[\Override]
	public function getDiagnostics(): array {
		return array_values(array_unique($this->diagnostics));
	}

	#[\Override]
	public function getPendingUpdates(): array {
		$this->diagnostics = [];
		if ($this->isRolling()) {
			return self::parsePackageUpdates($this->zypperXml(['list-updates']));
		}

		$patches = self::parsePatches($this->zypperXml(['list-patches', '--category', 'security']));
		$packages = self::parsePackageUpdates($this->zypperXml(['list-updates']));

		return self::mergePatches($patches, $packages, $this->patchPackages($patches));
	}

	/**
	 * Which packages each patch updates, from the "Conflicts" of
	 * `zypper info -t patch`, which lists every package older than the fix.
	 *
	 * @param list<PendingUpdate> $patches
	 * @return array<string, list<string>> patch name => package names
	 */
	private function patchPackages(array $patches): array {
		$names = array_values(array_filter(
			array_map(static fn (PendingUpdate $patch): string => $patch->name, $patches),
			static fn (string $name): bool => preg_match('/^[A-Za-z0-9][A-Za-z0-9._:+-]*$/', $name) === 1,
		));
		if ($names === []) {
			return [];
		}

		$result = $this->host->run('zypper', ['--non-interactive', '--no-refresh', 'info', '-t', 'patch', ...array_slice($names, 0, self::MAX_PATCH_LOOKUP)], 180);
		if ($result === null || !self::isComplete($result)) {
			return [];
		}

		return self::parsePatchConflicts($result->stdout);
	}

	/**
	 * @return array<string, list<string>> patch name => package names, without architecture
	 */
	public static function parsePatchConflicts(string $output): array {
		$packages = [];
		$patch = null;
		$inConflicts = false;
		foreach (preg_split('/\R/', $output) ?: [] as $line) {
			if (preg_match('/^Information for patch (\S+):$/', $line, $matches) === 1) {
				$patch = $matches[1];
				$packages[$patch] = [];
				$inConflicts = false;
				continue;
			}
			if ($patch === null) {
				continue;
			}
			if (preg_match('/^Conflicts\s*:/', $line) === 1) {
				$inConflicts = true;
				continue;
			}
			if ($inConflicts && preg_match('/^\s+([A-Za-z0-9._+-]+?)(?:\.' . Rpm::ARCH . ')?\s*[<=>]/', $line, $matches) === 1) {
				$packages[$patch][$matches[1]] = true;
				continue;
			}
			$inConflicts = $inConflicts && trim($line) !== '' && preg_match('/^\s/', $line) === 1;
		}

		return array_map(static fn (array $names): array => array_keys($names), $packages);
	}

	/**
	 * Marks the package updates a security patch covers as security updates
	 * with its advisories; patches covering no listed package stay rows of
	 * their own, so no security fix goes unreported.
	 *
	 * @param list<PendingUpdate> $patches
	 * @param list<PendingUpdate> $packages
	 * @param array<string, list<string>> $coverage patch name => package names
	 * @return list<PendingUpdate>
	 */
	public static function mergePatches(array $patches, array $packages, array $coverage): array {
		$byPackage = [];
		foreach ($patches as $patch) {
			foreach ($coverage[$patch->name] ?? [] as $package) {
				$byPackage[$package][] = $patch;
			}
		}

		$covered = [];
		$merged = [];
		foreach ($packages as $package) {
			$fixes = $byPackage[$package->name] ?? [];
			if ($fixes === []) {
				$merged[] = $package;
				continue;
			}

			$advisories = [];
			$severity = null;
			foreach ($fixes as $patch) {
				$covered[$patch->name] = true;
				$advisories = array_merge($advisories, $patch->advisories);
				$severity = PendingUpdate::higherSeverity($severity, $patch->severity);
			}
			$merged[] = new PendingUpdate(
				$package->name,
				$package->installedVersion,
				$package->candidateVersion,
				true,
				$package->origin,
				array_values(array_unique($advisories)),
				$severity,
			);
		}

		foreach ($patches as $patch) {
			if (!isset($covered[$patch->name])) {
				$merged[] = $patch;
			}
		}

		return $merged;
	}

	/**
	 * @return list<PendingUpdate>
	 */
	public static function parsePatches(string $xml): array {
		$updates = [];
		foreach (self::updateElements($xml) as $update) {
			if ((string)$update['kind'] !== 'patch' || (string)$update['category'] !== 'security') {
				continue;
			}
			// Patches already applied, or not applicable to this system, are listed too.
			if (!in_array((string)$update['status'], ['', 'needed'], true)) {
				continue;
			}

			$name = (string)$update['name'];
			$severity = (string)$update['severity'];
			$advisories = [$name];
			foreach ($update->xpath('issue-list/issue[@type="cve"]') ?: [] as $issue) {
				$id = (string)$issue['id'];
				if (preg_match('/^CVE-\d{4}-\d+$/', $id) === 1 && !in_array($id, $advisories, true)) {
					$advisories[] = $id;
				}
			}
			$summary = trim((string)$update->summary);

			$updates[] = new PendingUpdate(
				$name,
				null,
				(string)$update['edition'],
				true,
				null,
				$advisories,
				$severity !== '' && $severity !== 'unspecified' ? ucfirst($severity) : null,
				null,
				PendingUpdate::KIND_PATCH,
				$summary !== '' ? mb_substr($summary, 0, 200) : null,
			);
		}

		return $updates;
	}

	/**
	 * @return list<PendingUpdate>
	 */
	public static function parsePackageUpdates(string $xml): array {
		$updates = [];
		foreach (self::updateElements($xml) as $update) {
			if ((string)$update['kind'] !== 'package') {
				continue;
			}

			$old = (string)$update['edition-old'];
			$source = $update->source['alias'] ?? $update->source['url'] ?? null;
			$updates[] = new PendingUpdate(
				(string)$update['name'],
				$old !== '' ? $old : null,
				(string)$update['edition'],
				false,
				$source !== null ? (string)$source : null,
			);
		}

		return $updates;
	}

	/**
	 * @return list<\SimpleXMLElement>
	 */
	private static function updateElements(string $xml): array {
		$document = self::parseXml($xml);
		if ($document === null) {
			return [];
		}

		$elements = $document->xpath('//update-list/update') ?: [];

		return array_values(array_filter($elements, static fn (mixed $e): bool => $e instanceof \SimpleXMLElement));
	}

	/**
	 * Parses zypper's XML without network access or entity expansion.
	 */
	private static function parseXml(string $xml): ?\SimpleXMLElement {
		$start = strpos($xml, '<?xml');
		if ($start === false) {
			$start = strpos($xml, '<stream');
		}
		if ($start === false) {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		$document = simplexml_load_string(substr($xml, $start), \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		return $document instanceof \SimpleXMLElement ? $document : null;
	}

	/**
	 * @param list<string> $arguments
	 */
	private function zypperXml(array $arguments): string {
		$result = $this->host->run('zypper', ['--non-interactive', '--no-refresh', '--xmlout', ...$arguments], 180);
		if ($result === null) {
			throw new ProbeException('zypper could not be run.');
		}
		if ($result->exitCode === self::EXIT_LOCKED) {
			throw new ProbeException('zypper is busy: another program holds the package manager lock, so pending updates could not be listed this time.');
		}

		// zypper reports trouble inside the XML stream as <message type="error">.
		$xmlError = preg_match('#<message type="error">([^<]*)</message>#', $result->stdout, $matches) === 1
			? html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1)
			: null;
		$skipped = $result->exitCode === self::EXIT_REPOS_SKIPPED;
		if (!self::isComplete($result) || self::parseXml($result->stdout) === null || ($xmlError !== null && !$skipped)) {
			throw new ProbeException('zypper ' . $arguments[0] . ' failed: ' . ($xmlError !== null ? ProbeHelper::firstLine($xmlError) : $result->failure(180)));
		}
		if ($skipped) {
			$this->diagnostics[] = 'zypper skipped repositories it could not read, so pending updates may be incomplete'
				. ($xmlError !== null ? ': ' . ProbeHelper::firstLine($xmlError) : '.');
		}

		return $result->stdout;
	}

	/**
	 * Whether zypper finished with its whole output.
	 */
	private static function isComplete(CommandResult $result): bool {
		return ($result->exitCode === 0 || in_array($result->exitCode, self::EXIT_INFORMATIONAL, true))
			&& !$result->timedOut && !$result->truncated;
	}

	#[\Override]
	public function getHeldPackages(): ?array {
		$result = $this->host->run('zypper', ['--non-interactive', '--no-refresh', '--xmlout', 'locks'], 60);
		if ($result === null || !self::isComplete($result)) {
			return null;
		}

		return self::parseLocks($result->stdout);
	}

	/**
	 * @return list<HeldPackage>
	 */
	public static function parseLocks(string $xml): array {
		$document = self::parseXml($xml);
		if ($document === null) {
			return [];
		}

		$held = [];
		foreach ($document->xpath('//locks/lock') ?: [] as $lock) {
			$name = trim((string)$lock->name);
			if ($name !== '') {
				$held[$name] = new HeldPackage($name, 'zypper addlock');
			}
		}

		return array_values($held);
	}

	/**
	 * zypper writes a cookie next to the raw metadata of each repository on
	 * every refresh.
	 */
	#[\Override]
	public function getListsRefreshedAt(): ?int {
		return ProbeHelper::newestModified($this->host, ['/var/cache/zypp/raw/*/cookie', '/var/cache/zypp/solv/*/cookie', '/var/cache/zypp/raw/*/repodata/repomd.xml']);
	}

	#[\Override]
	public function getLastUpgradeAt(): ?int {
		return $this->rpm->getLastInstallTime();
	}

	#[\Override]
	public function getAutomaticUpdates(): AutomaticUpdates {
		$transactional = $this->systemd->getUnitFileState('transactional-update.timer');
		if ($transactional === 'enabled') {
			return new AutomaticUpdates(AutomaticUpdates::STATE_ENABLED, 'transactional-update', AutomaticUpdates::SCOPE_ALL);
		}

		$yast = $this->host->readFile('/etc/sysconfig/automatic_online_update', 65536);
		if ($yast !== null && preg_match('/^AOU_ENABLE_CRONJOB="?(true|yes)"?/mi', $yast) === 1) {
			$securityOnly = preg_match('/^AOU_PATCH_CATEGORIES="?security"?/mi', $yast) === 1;

			return new AutomaticUpdates(
				AutomaticUpdates::STATE_ENABLED,
				'yast2-online-update',
				$securityOnly ? AutomaticUpdates::SCOPE_SECURITY : AutomaticUpdates::SCOPE_ALL,
			);
		}

		return $this->systemd->isRunning() ? new AutomaticUpdates(AutomaticUpdates::STATE_DISABLED) : AutomaticUpdates::unknown();
	}

	/**
	 * `zypper needs-rebooting` only knows what /etc/zypp/needreboot lists,
	 * which is nothing at all on some images, so its "no" is not a complete
	 * answer and the generic core library check still runs.
	 */
	#[\Override]
	public function getRebootReasons(): array {
		$result = $this->host->run('zypper', ['--non-interactive', 'needs-rebooting'], 60);
		if (($result !== null && $result->exitCode === self::EXIT_REBOOT_NEEDED)
			|| ($result === null && $this->host->fileExists('/run/reboot-needed'))) {
			return [new RebootReason(RebootReason::KIND_DISTRIBUTION, null, [], $this->host->modifiedAt('/run/reboot-needed'))];
		}

		return [];
	}

	#[\Override]
	public function hasOwnRebootCheck(): bool {
		return false;
	}

	#[\Override]
	public function getUpgradeCommands(): array {
		if ($this->isRolling()) {
			return [
				'all' => $this->host->hasBinary('transactional-update') ? 'sudo transactional-update dup' : 'sudo zypper refresh && sudo zypper dup',
				'security' => null,
			];
		}

		return [
			'all' => 'sudo zypper refresh && sudo zypper patch',
			'security' => 'sudo zypper refresh && sudo zypper patch --category security',
		];
	}

	#[\Override]
	public function getPackageDatabasePath(): ?string {
		return $this->rpm->getDatabasePath();
	}

	/**
	 * The RPM database, the solv cache that zypper reads with --no-refresh,
	 * the repositories and the package locks.
	 */
	#[\Override]
	public function getUpdateInputs(): ?array {
		$database = $this->rpm->getUpdateInputs();
		if ($database === null) {
			return null;
		}

		return [
			...$database,
			'/var/cache/zypp/solv',
			'/var/cache/zypp/solv/*/cookie',
			'/var/cache/zypp/solv/*/solv',
			'/var/cache/zypp/raw',
			'/var/cache/zypp/raw/*/repodata/repomd.xml',
			'/etc/zypp/zypp.conf',
			'/etc/zypp/locks',
			'/etc/zypp/repos.d',
			'/etc/zypp/repos.d/*.repo',
		];
	}
}
