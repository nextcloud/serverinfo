<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Support;

/**
 * How long the installed release receives security updates.
 *
 * Only dates are stored; the state is worked out against the current day
 * whenever it is asked for, so a cached status does not go stale on its own.
 */
final class SupportStatus {
	public const STATE_SUPPORTED = 'supported';
	/** Past the included support, kept secure by an active paid programme */
	public const STATE_EXTENDED = 'extended';
	/** Past the included support; a paid programme could extend it but is not detected */
	public const STATE_EXTENDED_AVAILABLE = 'extended-available';
	public const STATE_ENDED = 'ended';
	public const STATE_ROLLING = 'rolling';
	public const STATE_UNKNOWN = 'unknown';

	/** Extended support programmes, keyed as in resources/os-support.json */
	public const PROGRAMMES = ['ubuntu-pro', 'debian-elts', 'rhel-els', 'suse-ltss', 'oracle-extended'];

	public function __construct(
		public readonly bool $rolling,
		/** Y-m-d, last day of the security updates included with the release */
		public readonly ?string $securityEnds,
		/** Y-m-d, last day of the paid extended programme */
		public readonly ?string $extendedEnds = null,
		/** One of self::PROGRAMMES */
		public readonly ?string $extended = null,
		/** Whether the extended programme is active here; null when that cannot be told */
		public readonly ?bool $extendedActive = null,
		/** Y-m-d, Debian only: when the volunteer LTS team took over from the security team */
		public readonly ?string $ltsFrom = null,
		/** Y-m-d, when the bundled support data was generated */
		public readonly ?string $dataDate = null,
	) {
	}

	public static function unknown(?string $dataDate = null): self {
		return new self(false, null, dataDate: $dataDate);
	}

	/**
	 * @return self::STATE_*
	 */
	public function state(int $now): string {
		if ($this->rolling) {
			return self::STATE_ROLLING;
		}
		if ($this->securityEnds === null) {
			return self::STATE_UNKNOWN;
		}

		$today = gmdate('Y-m-d', $now);
		if ($today <= $this->securityEnds) {
			return self::STATE_SUPPORTED;
		}
		if ($this->extendedEnds !== null && $today <= $this->extendedEnds) {
			return $this->extendedActive === true ? self::STATE_EXTENDED : self::STATE_EXTENDED_AVAILABLE;
		}

		return self::STATE_ENDED;
	}

	/**
	 * The last day this system receives security updates as it is set up now.
	 */
	public function effectiveEnd(): ?string {
		if ($this->extendedActive === true && $this->extendedEnds !== null) {
			return $this->extendedEnds;
		}

		return $this->securityEnds;
	}

	/**
	 * Whole days until the effective end, negative once it has passed.
	 */
	public function daysLeft(int $now): ?int {
		$end = $this->effectiveEnd();
		if ($end === null) {
			return null;
		}

		$endTime = strtotime($end . ' 23:59:59 UTC');
		if ($endTime === false) {
			return null;
		}

		return (int)floor(($endTime - $now) / 86400);
	}

	/**
	 * @return array{rolling: bool, securityEnds: ?string, extendedEnds: ?string, extended: ?string, extendedActive: ?bool, ltsFrom: ?string, dataDate: ?string}
	 */
	public function toArray(): array {
		return [
			'rolling' => $this->rolling,
			'securityEnds' => $this->securityEnds,
			'extendedEnds' => $this->extendedEnds,
			'extended' => $this->extended,
			'extendedActive' => $this->extendedActive,
			'ltsFrom' => $this->ltsFrom,
			'dataDate' => $this->dataDate,
		];
	}

	public static function fromArray(mixed $data): self {
		if (!is_array($data)) {
			return self::unknown();
		}

		$date = static fn (mixed $value): ?string => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
		$extended = $data['extended'] ?? null;

		return new self(
			(bool)($data['rolling'] ?? false),
			$date($data['securityEnds'] ?? null),
			$date($data['extendedEnds'] ?? null),
			in_array($extended, self::PROGRAMMES, true) ? $extended : null,
			is_bool($data['extendedActive'] ?? null) ? $data['extendedActive'] : null,
			$date($data['ltsFrom'] ?? null),
			$date($data['dataDate'] ?? null),
		);
	}

	public function withExtendedActive(?bool $active): self {
		return new self($this->rolling, $this->securityEnds, $this->extendedEnds, $this->extended, $active, $this->ltsFrom, $this->dataDate);
	}
}
