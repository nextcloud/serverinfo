<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Helper;

use OCA\ServerInfo\OsStatus\Packages\PendingUpdate;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Restart\ServiceRestart;
use OCA\ServerInfo\OsStatus\Values;

/**
 * The parts of a root helper report the app uses, after validation.
 *
 * The report is written by bin/serverinfo-os-report; its format is
 * documented there.
 */
final class HelperReport {
	public const SCHEMA = 1;

	/** Longest list taken from a report; anything beyond is ignored. */
	private const MAX_ITEMS = 2000;

	/**
	 * @param list<PendingUpdate>|null $pendingUpdates
	 * @param list<ServiceRestart>|null $restartServices
	 * @param list<RebootReason> $rebootReasons
	 * @param list<string> $errors
	 */
	public function __construct(
		public readonly int $generatedAt,
		public readonly string $helperVersion,
		public readonly string $hostname,
		public readonly ?int $bootTime,
		public readonly ?int $listsRefreshedAt,
		public readonly ?array $pendingUpdates,
		public readonly ?array $restartServices,
		public readonly array $rebootReasons,
		/** Whether a paid extended support subscription is active, null when unknown */
		public readonly ?bool $subscriptionActive,
		public readonly array $errors,
	) {
	}

	/**
	 * @throws \InvalidArgumentException when required fields are missing or malformed
	 */
	public static function fromArray(array $data): self {
		$generatedAt = $data['generatedAt'] ?? null;
		$version = $data['helperVersion'] ?? null;
		$hostname = $data['hostname'] ?? null;
		if (!is_int($generatedAt) || !is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1
			|| !is_string($hostname) || $hostname === '') {
			throw new \InvalidArgumentException('Required fields are missing');
		}

		$pendingUpdates = null;
		if (is_array($data['pendingUpdates'] ?? null)) {
			$pendingUpdates = self::mapList($data['pendingUpdates'], static fn (mixed $item): ?PendingUpdate => PendingUpdate::fromArray($item));
		}

		$restartServices = null;
		if (is_array($data['restartServices'] ?? null)) {
			$restartServices = self::mapList(
				$data['restartServices'],
				static fn (mixed $unit): ?ServiceRestart => is_string($unit) ? ServiceRestart::fromArray(['unit' => $unit]) : null,
			);
		}

		$rebootReasons = [];
		if (is_array($data['rebootReasons'] ?? null)) {
			$rebootReasons = self::mapList($data['rebootReasons'], static fn (mixed $item): ?RebootReason => RebootReason::fromArray($item));
		}

		$errors = [];
		if (is_array($data['errors'] ?? null)) {
			$errors = self::mapList(
				$data['errors'],
				static fn (mixed $error): ?string => is_string($error) ? mb_substr($error, 0, 300) : null,
			);
			$errors = array_slice($errors, 0, 20);
		}

		$subscription = $data['subscription'] ?? null;

		return new self(
			$generatedAt,
			$version,
			$hostname,
			Values::int($data['bootTime'] ?? null),
			Values::int($data['listsRefreshedAt'] ?? null),
			$pendingUpdates,
			$restartServices,
			$rebootReasons,
			is_array($subscription) && is_bool($subscription['active'] ?? null) ? $subscription['active'] : null,
			$errors,
		);
	}

	/**
	 * @template T
	 * @param array<array-key, mixed> $items
	 * @param callable(mixed): (T|null) $map
	 * @return list<T>
	 */
	private static function mapList(array $items, callable $map): array {
		$mapped = [];
		foreach (array_slice(array_values($items), 0, self::MAX_ITEMS) as $item) {
			$value = $map($item);
			if ($value !== null) {
				$mapped[] = $value;
			}
		}

		return $mapped;
	}
}
