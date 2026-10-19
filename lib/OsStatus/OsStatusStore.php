<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessSample;
use OCP\AppFramework\Services\IAppConfig;

/**
 * Keeps what the collector found, so pages, setup checks, notifications and
 * the API read a stored answer instead of running commands.
 *
 * @psalm-api
 */
class OsStatusStore {
	/** A new sample with the same findings is stored at most this often. */
	private const PHP_SAMPLE_INTERVAL = 300;

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function save(OsStatus $status): void {
		$this->appConfig->setAppValueArray(ConfigLexicon::CACHED_OS_STATUS, $status->toArray());
	}

	public function load(): ?OsStatus {
		return OsStatus::fromArray($this->appConfig->getAppValueArray(ConfigLexicon::CACHED_OS_STATUS));
	}

	/**
	 * Remembers what a web request saw of its own PHP process. The same
	 * findings are written at most every few minutes, because this runs on
	 * page loads.
	 */
	public function savePhpSample(PhpProcessSample $sample): void {
		$previous = $this->loadPhpSample();
		if ($previous !== null
			&& $previous->outdatedLibraries === $sample->outdatedLibraries
			&& $previous->service === $sample->service
			&& $previous->confinement === $sample->confinement
			&& $sample->sampledAt - $previous->sampledAt < self::PHP_SAMPLE_INTERVAL) {
			return;
		}

		$this->appConfig->setAppValueArray(ConfigLexicon::CACHED_OS_PHP_PROCESS, $sample->toArray());
		$this->firstSeen($sample->isOutdated() ? ['php-process' => null] : [], $sample->sampledAt, ['php-process']);
	}

	public function loadPhpSample(): ?PhpProcessSample {
		return PhpProcessSample::fromArray($this->appConfig->getAppValueArray(ConfigLexicon::CACHED_OS_PHP_PROCESS));
	}

	/**
	 * When the PHP process was first seen running outdated libraries.
	 */
	public function phpOutdatedSince(): ?int {
		$seen = $this->appConfig->getAppValueArray(ConfigLexicon::CACHED_OS_FIRST_SEEN);
		$since = $seen['php-process'] ?? null;

		return is_int($since) ? $since : null;
	}

	/**
	 * Tracks when each finding was first seen, which is what the alert
	 * thresholds count from. Distributions do not record how long an update
	 * has been waiting, so the app has to.
	 *
	 * Findings of one scope that are no longer current are forgotten, so a
	 * finding that comes back starts counting again.
	 *
	 * @param array<string, ?int> $current key => when the system says it began, null when it does not say
	 * @param list<string>|null $scope prefixes this call is authoritative for; all keys when null
	 * @return array<string, int> key => first seen
	 */
	public function firstSeen(array $current, int $now, ?array $scope = null): array {
		$stored = $this->appConfig->getAppValueArray(ConfigLexicon::CACHED_OS_FIRST_SEEN);

		$result = [];
		foreach ($stored as $key => $since) {
			if (!is_string($key) || !is_int($since)) {
				continue;
			}
			if (!array_key_exists($key, $current) && !$this->inScope($key, $scope)) {
				$result[$key] = $since;
			}
		}

		$found = [];
		foreach ($current as $key => $known) {
			if ($scope !== null && !$this->inScope($key, $scope)) {
				continue;
			}

			$previous = $stored[$key] ?? null;
			$candidates = [];
			if (is_int($previous)) {
				$candidates[] = $previous;
			}
			if ($known !== null) {
				$candidates[] = $known;
			}
			$found[$key] = $result[$key] = $candidates === [] ? $now : min($candidates);
		}

		if ($result != $stored) {
			$this->appConfig->setAppValueArray(ConfigLexicon::CACHED_OS_FIRST_SEEN, $result);
		}

		return $found;
	}

	/**
	 * @param list<string>|null $scope
	 */
	private function inScope(string $key, ?array $scope): bool {
		if ($scope === null) {
			return true;
		}

		foreach ($scope as $prefix) {
			if ($key === $prefix || str_starts_with($key, $prefix . ':')) {
				return true;
			}
		}

		return false;
	}
}
