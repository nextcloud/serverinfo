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
use OCP\Files\GenericFileException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Keeps what the collector found, so pages, setup checks, notifications and
 * the API read a stored answer instead of running commands.
 *
 * The status itself can be a few hundred kilobytes, so it is a file in the
 * app's data with the distributed cache in front of it; app config, whose
 * lazy values are all loaded together, keeps only the small values.
 *
 * @psalm-api
 */
class OsStatusStore {
	/** A new sample with the same findings is stored at most this often. */
	private const PHP_SAMPLE_INTERVAL = 300;

	private const FOLDER = 'os-status';
	private const FILE = 'status.json';
	private const CACHE_KEY = 'status';
	private const CACHE_TTL = 86400;
	/** The app config key the status was kept under before it moved to app data */
	private const LEGACY_STATUS_KEY = 'cached_os_status';

	private ICache $cache;
	private bool $loaded = false;
	private ?OsStatus $status = null;

	public function __construct(
		private IAppConfig $appConfig,
		private IAppData $appData,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('serverinfo-os');
	}

	/**
	 * @throws NotPermittedException|NotFoundException|\JsonException when the status cannot be written
	 */
	public function save(OsStatus $status): void {
		$json = json_encode($status->toArray(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

		$folder = $this->folder();
		if ($folder->fileExists(self::FILE)) {
			$folder->getFile(self::FILE)->putContent($json);
		} else {
			$folder->newFile(self::FILE, $json);
		}

		$this->cache->set(self::CACHE_KEY, $json, self::CACHE_TTL);
		$this->status = $status;
		$this->loaded = true;

		if ($this->appConfig->hasAppKey(self::LEGACY_STATUS_KEY, null)) {
			$this->appConfig->deleteAppValue(self::LEGACY_STATUS_KEY);
		}
	}

	/**
	 * The stored status, read once per request.
	 */
	public function load(): ?OsStatus {
		if (!$this->loaded) {
			$this->status = $this->read();
			$this->loaded = true;
		}

		return $this->status;
	}

	private function read(): ?OsStatus {
		$json = $this->cache->get(self::CACHE_KEY);
		if (!is_string($json)) {
			try {
				$json = $this->appData->getFolder(self::FOLDER)->getFile(self::FILE)->getContent();
			} catch (NotFoundException) {
				$json = '';
			} catch (NotPermittedException|GenericFileException) {
				return null;
			}
			// An empty entry remembers that nothing has been collected yet.
			$this->cache->set(self::CACHE_KEY, $json, self::CACHE_TTL);
		}

		if ($json === '') {
			return null;
		}

		try {
			return OsStatus::fromArray(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
		} catch (\JsonException) {
			return null;
		}
	}

	/**
	 * @throws NotPermittedException
	 */
	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
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

		$since = $this->phpOutdatedSince();
		if ($sample->isOutdated() && $since === null) {
			$this->appConfig->setAppValueInt(ConfigLexicon::CACHED_OS_PHP_OUTDATED_SINCE, $sample->sampledAt);
		} elseif (!$sample->isOutdated() && $since !== null) {
			$this->appConfig->setAppValueInt(ConfigLexicon::CACHED_OS_PHP_OUTDATED_SINCE, 0);
		}
	}

	public function loadPhpSample(): ?PhpProcessSample {
		return PhpProcessSample::fromArray($this->appConfig->getAppValueArray(ConfigLexicon::CACHED_OS_PHP_PROCESS));
	}

	/**
	 * When the PHP process was first seen running outdated libraries.
	 *
	 * Web requests keep this under a key of its own, because the collector
	 * rewrites the first-seen times of everything else at the same time.
	 */
	public function phpOutdatedSince(): ?int {
		$since = $this->appConfig->getAppValueInt(ConfigLexicon::CACHED_OS_PHP_OUTDATED_SINCE);

		return $since > 0 ? $since : null;
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
	 * @return array<string, string> concern id => level last notified
	 */
	public function notifiedLevels(): array {
		return array_filter(
			$this->appConfig->getAppValueArray(ConfigLexicon::OS_NOTIFIED_LEVELS),
			static fn (mixed $level, mixed $key): bool => is_string($key) && is_string($level),
			ARRAY_FILTER_USE_BOTH,
		);
	}

	/**
	 * @param array<string, string> $levels
	 */
	public function setNotifiedLevels(array $levels): void {
		$this->appConfig->setAppValueArray(ConfigLexicon::OS_NOTIFIED_LEVELS, $levels);
	}

	/**
	 * @return array<string, list<string>> concern id => user ids notified since it last escalated
	 */
	public function notifiedUsers(): array {
		$users = [];
		foreach ($this->appConfig->getAppValueArray(ConfigLexicon::OS_NOTIFIED_USERS) as $id => $uids) {
			if (is_string($id) && is_array($uids)) {
				$users[$id] = array_values(array_filter($uids, is_string(...)));
			}
		}

		return $users;
	}

	/**
	 * @param array<string, list<string>> $users
	 */
	public function setNotifiedUsers(array $users): void {
		$this->appConfig->setAppValueArray(ConfigLexicon::OS_NOTIFIED_USERS, $users);
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
