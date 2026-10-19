<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

use OCA\ServerInfo\OsStatus\Packages\AutomaticUpdates;
use OCA\ServerInfo\OsStatus\Packages\HeldPackage;
use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;

/**
 * Everything collected about the operating system in one run.
 *
 * Null always means "could not be determined", never "nothing found": an
 * empty list is a finding, null is not.
 */
final class OsStatus {
	public const SCHEMA = 1;

	public const STATE_OK = 'ok';
	/** restricted_mode is set, so nothing is read from the system */
	public const STATE_RESTRICTED = 'restricted';
	/** Not Linux */
	public const STATE_UNSUPPORTED = 'unsupported';

	/** Source of a finding */
	public const SOURCE_SYSTEM = 'system';

	/**
	 * @param self::STATE_* $state
	 * @param list<PendingUpdate>|null $updates
	 * @param self::SOURCE_*|null $updatesSource
	 * @param list<HeldPackage>|null $held
	 * @param list<RebootReason>|null $rebootReasons
	 * @param list<string>|null $livePatches
	 * @param list<string>|null $failedUnits
	 * @param array{all: ?string, security: ?string} $upgradeCommands
	 * @param list<string> $diagnostics
	 */
	public function __construct(
		public readonly string $state,
		public readonly int $collectedAt,
		public readonly ?OsRelease $release = null,
		public readonly string $kernel = '',
		public readonly string $architecture = '',
		public readonly Environment $environment = new Environment(Environment::KIND_HOST),
		public readonly SupportStatus $support = new SupportStatus(false, null),
		public readonly ?string $packageManager = null,
		public readonly ?array $updates = null,
		public readonly ?string $updatesSource = null,
		/** Whether the package manager tells security updates from others */
		public readonly bool $securityClassified = false,
		/** Updates left out of $updates to keep the stored status small */
		public readonly int $updatesOmitted = 0,
		public readonly ?array $held = null,
		public readonly ?int $listsRefreshedAt = null,
		public readonly ?int $lastUpgradeAt = null,
		public readonly AutomaticUpdates $automaticUpdates = new AutomaticUpdates(AutomaticUpdates::STATE_UNKNOWN),
		public readonly ?array $rebootReasons = null,
		public readonly ?array $livePatches = null,
		public readonly ?array $failedUnits = null,
		public readonly ?bool $timeSynchronized = null,
		public readonly ?string $timeSource = null,
		public readonly ?string $selinux = null,
		public readonly ?string $apparmor = null,
		public readonly array $upgradeCommands = ['all' => null, 'security' => null],
		public readonly ?string $packageDatabase = null,
		public readonly ?int $bootTime = null,
		public readonly array $diagnostics = [],
	) {
	}

	public static function restricted(int $now): self {
		return new self(self::STATE_RESTRICTED, $now);
	}

	public static function unsupported(int $now, string $kernel, string $architecture): self {
		return new self(self::STATE_UNSUPPORTED, $now, kernel: $kernel, architecture: $architecture);
	}

	/**
	 * @return list<PendingUpdate>|null
	 */
	public function securityUpdates(): ?array {
		if ($this->updates === null) {
			return null;
		}

		return array_values(array_filter($this->updates, static fn (PendingUpdate $update): bool => $update->security));
	}

	public function toArray(): array {
		return [
			'schema' => self::SCHEMA,
			'state' => $this->state,
			'collectedAt' => $this->collectedAt,
			'release' => $this->release?->toArray(),
			'kernel' => $this->kernel,
			'architecture' => $this->architecture,
			'environment' => $this->environment->toArray(),
			'support' => $this->support->toArray(),
			'packageManager' => $this->packageManager,
			'updates' => $this->updates === null ? null : array_map(static fn (PendingUpdate $u): array => $u->toArray(), $this->updates),
			'updatesSource' => $this->updatesSource,
			'securityClassified' => $this->securityClassified,
			'updatesOmitted' => $this->updatesOmitted,
			'held' => $this->held === null ? null : array_map(static fn (HeldPackage $h): array => $h->toArray(), $this->held),
			'listsRefreshedAt' => $this->listsRefreshedAt,
			'lastUpgradeAt' => $this->lastUpgradeAt,
			'automaticUpdates' => $this->automaticUpdates->toArray(),
			'rebootReasons' => $this->rebootReasons === null ? null : array_map(static fn (RebootReason $r): array => $r->toArray(), $this->rebootReasons),
			'livePatches' => $this->livePatches,
			'failedUnits' => $this->failedUnits,
			'timeSynchronized' => $this->timeSynchronized,
			'timeSource' => $this->timeSource,
			'selinux' => $this->selinux,
			'apparmor' => $this->apparmor,
			'upgradeCommands' => $this->upgradeCommands,
			'packageDatabase' => $this->packageDatabase,
			'bootTime' => $this->bootTime,
			'diagnostics' => $this->diagnostics,
		];
	}

	/**
	 * Rebuilds a stored status. Anything malformed reads as unknown rather
	 * than failing, so a damaged cache entry cannot break the page.
	 */
	public static function fromArray(mixed $data): ?self {
		if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA || !is_int($data['collectedAt'] ?? null)) {
			return null;
		}

		$state = in_array($data['state'] ?? null, [self::STATE_OK, self::STATE_RESTRICTED, self::STATE_UNSUPPORTED], true)
			? $data['state'] : self::STATE_OK;
		$source = ($data['updatesSource'] ?? null) === self::SOURCE_SYSTEM ? self::SOURCE_SYSTEM : null;
		$commands = is_array($data['upgradeCommands'] ?? null) ? $data['upgradeCommands'] : [];

		return new self(
			$state,
			$data['collectedAt'],
			OsRelease::fromArray($data['release'] ?? null),
			is_string($data['kernel'] ?? null) ? $data['kernel'] : '',
			is_string($data['architecture'] ?? null) ? $data['architecture'] : '',
			Environment::fromArray($data['environment'] ?? null),
			SupportStatus::fromArray($data['support'] ?? null),
			Values::string($data['packageManager'] ?? null),
			self::listOf($data['updates'] ?? null, static fn (mixed $item): ?PendingUpdate => PendingUpdate::fromArray($item)),
			$source,
			($data['securityClassified'] ?? false) === true,
			is_int($data['updatesOmitted'] ?? null) ? $data['updatesOmitted'] : 0,
			self::listOf($data['held'] ?? null, static fn (mixed $item): ?HeldPackage => HeldPackage::fromArray($item)),
			Values::int($data['listsRefreshedAt'] ?? null),
			Values::int($data['lastUpgradeAt'] ?? null),
			AutomaticUpdates::fromArray($data['automaticUpdates'] ?? null),
			self::listOf($data['rebootReasons'] ?? null, static fn (mixed $item): ?RebootReason => RebootReason::fromArray($item)),
			self::stringList($data['livePatches'] ?? null),
			self::stringList($data['failedUnits'] ?? null),
			is_bool($data['timeSynchronized'] ?? null) ? $data['timeSynchronized'] : null,
			Values::string($data['timeSource'] ?? null),
			Values::string($data['selinux'] ?? null),
			Values::string($data['apparmor'] ?? null),
			[
				'all' => is_string($commands['all'] ?? null) ? $commands['all'] : null,
				'security' => is_string($commands['security'] ?? null) ? $commands['security'] : null,
			],
			Values::string($data['packageDatabase'] ?? null),
			Values::int($data['bootTime'] ?? null),
			self::stringList($data['diagnostics'] ?? null) ?? [],
		);
	}

	/**
	 * @template T
	 * @param callable(mixed): (T|null) $map
	 * @return list<T>|null
	 */
	private static function listOf(mixed $items, callable $map): ?array {
		if (!is_array($items)) {
			return null;
		}

		$list = [];
		foreach ($items as $item) {
			$value = $map($item);
			if ($value !== null) {
				$list[] = $value;
			}
		}

		return $list;
	}

	/**
	 * @return list<string>|null
	 */
	private static function stringList(mixed $items): ?array {
		return is_array($items) ? array_values(array_filter($items, 'is_string')) : null;
	}
}
