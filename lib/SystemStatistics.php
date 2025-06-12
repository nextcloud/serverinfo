<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo;

use OC\Files\View;
use OC\Installer;
use OCP\App\IAppManager;
use OCP\Files\FileInfo;
use OCP\IConfig;

class SystemStatistics {
	private IConfig $config;
	private View $view;
	private IAppManager $appManager;
	private Installer $installer;
	protected Os $os;

	public function __construct(IConfig $config, IAppManager $appManager, Installer $installer, Os $os) {
		$this->config = $config;
		$this->view = new View('');
		$this->appManager = $appManager;
		$this->installer = $installer;
		$this->os = $os;
	}

	/**
	 * Get statistics about the system
	 *
	 * @throws \OCP\Files\InvalidPathException
	 */
	public function getSystemStatistics(bool $skipApps = false, bool $skipUpdate = true): array {
		$cpu = $this->os->getCPU();
		$memory = $this->os->getMemory();

		$data = [
			'version' => $this->config->getSystemValue('version'),
			'theme' => $this->config->getSystemValue('theme', 'none'),
			'enable_avatars' => $this->config->getSystemValue('enable_avatars', true) ? 'yes' : 'no',
			'enable_previews' => $this->config->getSystemValue('enable_previews', true) ? 'yes' : 'no',
			'memcache.local' => $this->config->getSystemValue('memcache.local', 'none'),
			'memcache.distributed' => $this->config->getSystemValue('memcache.distributed', 'none'),
			'filelocking.enabled' => $this->config->getSystemValue('filelocking.enabled', true) ? 'yes' : 'no',
			'memcache.locking' => $this->config->getSystemValue('memcache.locking', 'none'),
			'debug' => $this->config->getSystemValue('debug', false) ? 'yes' : 'no',
			'freespace' => $this->getFreeSpace(),
			'cpuload' => $cpu->getAverageLoad(),
			'cpunum' => $cpu->getThreads(),
			'mem_total' => $memory->getMemTotal() * 1024,
			'mem_free' => $memory->getMemAvailable() * 1024,
			'swap_total' => $memory->getSwapTotal() * 1024,
			'swap_free' => $memory->getSwapFree() * 1024,
		];

		if (!$skipApps) {
			$data['apps'] = $this->getAppsInfo();
		}

		if (!$skipUpdate) {
			$data['update'] = $this->getServerUpdateInfo();
		}

		return $data;
	}

	/**
	 * Get info about server updates and last checked timestamp
	 *
	 * @return array information about core updates
	 */
	protected function getServerUpdateInfo(): array {
		$updateInfo = [
			'lastupdatedat' => (int)$this->config->getAppValue('core', 'lastupdatedat'),
			'available' => false,
		];

		$lastUpdateResult = json_decode($this->config->getAppValue('core', 'lastupdateResult'), true);
		if (is_array($lastUpdateResult)) {
			$updateInfo['available'] = (count($lastUpdateResult) > 0);
			if (array_key_exists('version', $lastUpdateResult)) {
				$updateInfo['available_version'] = $lastUpdateResult['version'];
			}
		}

		return $updateInfo;
	}

	/**
	 * Get some info about installed apps, including available updates.
	 *
	 * @return array data about apps
	 */
	protected function getAppsInfo(): array {
		// sekeleton about the data we return back
		$info = [
			'num_installed' => 0,
			'num_updates_available' => 0,
			'app_updates' => [],
		];

		// load all apps
		$apps = $this->appManager->getInstalledApps();
		$info['num_installed'] = \count($apps);

		// iteriate through all installed apps.
		foreach ($apps as $appId) {
			// check if there is any new version available for that specific app
			$newVersion = $this->installer->isUpdateAvailable($appId);
			if ($newVersion) {
				// new version available, count up and tell which version.
				$info['num_updates_available']++;
				$info['app_updates'][$appId] = $newVersion;
			}
		}

		return $info;
	}

	/**
	 * Get free space if it can be calculated.
	 *
	 * @return mixed free space or null
	 * @throws \OCP\Files\InvalidPathException
	 */
	protected function getFreeSpace() {
		$free_space = $this->view->free_space();
		if ($free_space === FileInfo::SPACE_UNKNOWN
			|| $free_space === FileInfo::SPACE_UNLIMITED
			|| $free_space === FileInfo::SPACE_NOT_COMPUTED) {
			return null;
		}
		return $free_space;
	}
}
