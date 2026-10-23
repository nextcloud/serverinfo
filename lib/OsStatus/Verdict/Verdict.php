<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Verdict;

/**
 * The overall assessment and the concerns it is made of.
 */
final class Verdict {
	/**
	 * @param list<Concern> $concerns
	 */
	public function __construct(
		public readonly array $concerns,
	) {
	}

	/**
	 * The most severe level of any concern; "unknown" when nothing could be
	 * assessed, or when nothing is wrong but the pending updates are unknown.
	 *
	 * @return Concern::LEVEL_*
	 */
	public function level(): string {
		$level = Concern::LEVEL_UNKNOWN;
		$known = false;
		foreach ($this->concerns as $concern) {
			if ($concern->level === Concern::LEVEL_UNKNOWN) {
				continue;
			}
			if (!$known || Concern::rank($concern->level) > Concern::rank($level)) {
				$level = $concern->level;
			}
			$known = true;
		}

		if (Concern::rank($level) <= Concern::rank(Concern::LEVEL_INFO) && $this->get(Concern::UPDATES)?->level === Concern::LEVEL_UNKNOWN) {
			return Concern::LEVEL_UNKNOWN;
		}

		return $level;
	}

	public function get(string $id): ?Concern {
		foreach ($this->concerns as $concern) {
			if ($concern->id === $id) {
				return $concern;
			}
		}

		return null;
	}
}
