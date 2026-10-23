<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Verdict;

use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\Reboot\RebootReason;
use OCA\ServerInfo\OsStatus\Support\SupportStatus;
use OCP\IDateTimeFormatter;
use OCP\IL10N;

/**
 * Sentences describing a concern, for places outside the operating system
 * page: setup checks, notifications and the dashboard widget.
 *
 * @psalm-api
 */
class ConcernTexts {
	/** Brand names of the paid extended support programmes */
	public const PROGRAMMES = [
		'ubuntu-pro' => 'Ubuntu Pro',
		'debian-elts' => 'Debian Extended LTS',
		'rhel-els' => 'Red Hat Extended Life Cycle Support',
		'suse-ltss' => 'SUSE Long Term Service Pack Support',
		'oracle-extended' => 'Oracle Linux Extended Support',
	];

	public function __construct(
		private IDateTimeFormatter $dateTimeFormatter,
	) {
	}

	/**
	 * A short headline, e.g. "7 security updates pending".
	 */
	public function title(Concern $concern, OsStatus $status, IL10N $l): string {
		$facts = $concern->facts;

		return match ($concern->id) {
			Concern::UPDATES => $this->updatesTitle($concern, $l),
			Concern::REBOOT => $concern->level === Concern::LEVEL_OK
				? $l->t('No reboot required')
				: $l->t('Reboot required'),
			Concern::RESTART => match (true) {
				$concern->level === Concern::LEVEL_OK => $l->t('No service restart required'),
				($facts['php'] ?? false) === true => $l->t('PHP is running outdated libraries'),
				default => $l->n('%n service needs a restart', '%n services need a restart', self::int($concern, 'services') ?? 0),
			},
			Concern::SUPPORT => $this->supportTitle($concern, $status, $l),
			Concern::PACKAGE_LISTS => $l->n(
				'Package lists were last refreshed %n day ago',
				'Package lists were last refreshed %n days ago',
				self::int($concern, 'days') ?? 0,
			),
			Concern::AUTOMATIC_UPDATES => $concern->level === Concern::LEVEL_OK
				? $l->t('Automatic updates are enabled')
				: $l->t('Automatic updates are off'),
			Concern::FAILED_UNITS => $l->n('%n system service has failed', '%n system services have failed', count(self::strings($concern, 'units'))),
			Concern::TIME_SYNC => $concern->level === Concern::LEVEL_OK
				? $l->t('The system clock is synchronized')
				: $l->t('The system clock is not synchronized'),
			Concern::HELPER => $l->t('The root helper report needs attention'),
			default => $concern->id,
		};
	}

	/**
	 * A sentence explaining the headline; empty when the headline says it all.
	 */
	public function detail(Concern $concern, IL10N $l): string {
		$facts = $concern->facts;
		$days = self::int($concern, 'days');

		switch ($concern->id) {
			case Concern::UPDATES:
				$sentences = [];
				$stack = self::int($concern, 'stack') ?? 0;
				if ($stack > 0) {
					$sentences[] = $l->n('%n of them updates software Nextcloud runs on, such as PHP or the database.', '%n of them update software Nextcloud runs on, such as PHP or the database.', $stack);
				}
				if ($days !== null && $days >= 1) {
					$sentences[] = $l->n('The oldest has been waiting for %n day.', 'The oldest has been waiting for %n days.', $days);
				}
				if (($facts['listsStale'] ?? false) === true) {
					$sentences[] = $l->t('The package lists are outdated, so newer updates may be missing from this count.');
				}

				return implode(' ', $sentences);
			case Concern::REBOOT:
				if ($concern->level === Concern::LEVEL_OK) {
					return '';
				}
				$sentences = [];
				foreach (self::strings($concern, 'kinds') as $kind) {
					$sentences[] = match ($kind) {
						RebootReason::KIND_KERNEL => $l->t('A newer kernel is installed.'),
						RebootReason::KIND_KERNEL_REMOVED => $l->t('The running kernel was removed by an upgrade.'),
						RebootReason::KIND_CORE_LIBRARY => $l->t('Core system libraries were updated since the last boot.'),
						RebootReason::KIND_MICROCODE => $l->t('Newer processor microcode is installed.'),
						default => $l->t('The distribution asks for a reboot.'),
					};
				}
				if ($days !== null && $days >= 1) {
					$sentences[] = $l->n('Pending for %n day.', 'Pending for %n days.', $days);
				}
				if (($facts['livePatched'] ?? false) === true) {
					$sentences[] = $l->t('A live kernel patch is active, which covers the kernel until the reboot.');
				}

				return implode(' ', array_unique($sentences));
			case Concern::RESTART:
				if ($concern->level === Concern::LEVEL_OK) {
					return '';
				}
				$services = self::int($concern, 'services') ?? 0;
				$sentences = [];
				if (($facts['php'] ?? false) === true && $services > 0) {
					$sentences[] = $l->n('Another %n service needs a restart too.', 'Another %n services need a restart too.', $services);
				}
				if ($days !== null && $days >= 1) {
					$sentences[] = $l->n('Pending for %n day.', 'Pending for %n days.', $days);
				}

				return implode(' ', $sentences);
			case Concern::FAILED_UNITS:
				$units = array_slice(self::strings($concern, 'units'), 0, 5);

				return $units === [] ? '' : implode(', ', $units);
			case Concern::TIME_SYNC:
				return $concern->level === Concern::LEVEL_OK ? '' : $l->t('Two-factor codes, federation and object storage requests fail when the clock drifts.');
			default:
				return '';
		}
	}

	private function updatesTitle(Concern $concern, IL10N $l): string {
		$classified = ($concern->facts['classified'] ?? false) === true;
		$security = self::int($concern, 'security') ?? 0;
		$other = self::int($concern, 'other') ?? 0;

		if ($concern->level === Concern::LEVEL_UNKNOWN) {
			return $l->t('Pending updates could not be determined');
		}
		if ($classified) {
			return $security > 0
				? $l->n('%n security update pending', '%n security updates pending', $security)
				: $l->t('No security updates pending');
		}

		return $other > 0
			? $l->n('%n update pending', '%n updates pending', $other)
			: $l->t('The system is up to date');
	}

	private function supportTitle(Concern $concern, OsStatus $status, IL10N $l): string {
		$name = $status->release?->prettyName ?? $l->t('This release');
		$ends = $this->date(self::string($concern, 'ends'), $l);
		$programme = self::PROGRAMMES[self::string($concern, 'extended') ?? ''] ?? null;
		$extendedEnds = $this->date(self::string($concern, 'extendedEnds'), $l);

		return match (self::string($concern, 'state')) {
			SupportStatus::STATE_ROLLING => $l->t('%1$s is a rolling release', [$name]),
			SupportStatus::STATE_ENDED => $l->t('%1$s no longer receives security updates since %2$s', [$name, $ends]),
			// TRANSLATORS: %1$s is the release, %2$s a paid support programme, e.g. "Ubuntu Pro", %3$s the date it ends
			SupportStatus::STATE_EXTENDED_AVAILABLE => $l->t('Standard support for %1$s has ended. Security updates continue only with %2$s until %3$s', [
				$name,
				$programme ?? '',
				$extendedEnds,
			]),
			// TRANSLATORS: %1$s is the release, %2$s a paid support programme, e.g. "Ubuntu Pro", %3$s the date it ends
			SupportStatus::STATE_EXTENDED => $l->t('%1$s receives security updates through %2$s until %3$s', [
				$name,
				$programme ?? '',
				$ends,
			]),
			SupportStatus::STATE_SUPPORTED => $concern->level === Concern::LEVEL_OK
				? $l->t('%1$s receives security updates until %2$s', [$name, $ends])
				: $l->t('Security updates for %1$s end on %2$s', [$name, $ends]),
			default => $l->t('The support status of %1$s is unknown', [$name]),
		};
	}

	private static function int(Concern $concern, string $fact): ?int {
		$value = $concern->facts[$fact] ?? null;

		return is_int($value) ? $value : null;
	}

	private static function string(Concern $concern, string $fact): ?string {
		$value = $concern->facts[$fact] ?? null;

		return is_string($value) ? $value : null;
	}

	/**
	 * @return list<string> the fact's string items; anything else is skipped
	 */
	private static function strings(Concern $concern, string $fact): array {
		$value = $concern->facts[$fact] ?? null;

		return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
	}

	private function date(?string $date, IL10N $l): string {
		if ($date === null) {
			return '';
		}
		$time = strtotime($date . ' 12:00:00 UTC');
		if ($time === false) {
			return $date;
		}

		return $this->dateTimeFormatter->formatDate($time, 'long', null, $l);
	}
}
