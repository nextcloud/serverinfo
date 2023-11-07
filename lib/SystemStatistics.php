<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2016 Bjoern Schiessle <bjoern@schiessle.org>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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
		$processorUsage = $this->getProcessorUsage();
		$memoryUsage = $this->os->getMemory();

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
			'cpuload' => $processorUsage['loadavg'],
			'mem_total' => $memoryUsage->getMemTotal() * 1024,
			'mem_free' => $memoryUsage->getMemAvailable() * 1024,
			'swap_total' => $memoryUsage->getSwapTotal() * 1024,
			'swap_free' => $memoryUsage->getSwapFree() * 1024,
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
			'lastupdatedat' => (int) $this->config->getAppValue('core', 'lastupdatedat'),
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
	 * Get current CPU load average
	 *
	 * @return array{loadavg: array|string} load average with three values, 1/5/15 minutes average.
	 */
	protected function getProcessorUsage(): array {
		// get current system load average - if we can
		$loadavg = (function_exists('sys_getloadavg')) ? sys_getloadavg() : false;

		// check if we got any values back.
		if ($loadavg === false || count($loadavg) !== 3) {
			// either no array or too few array keys.
			// returning back zeroes to prevent any errors on JS side.
			$loadavg = 'N/A';
		}

		return [
			'loadavg' => $loadavg
		];
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
