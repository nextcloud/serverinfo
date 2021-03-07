<?php
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
use OCP\IConfig;

class SystemStatistics {

	/** @var IConfig */
	private $config;
	/** @var View view on data/ */
	private $view;
	/** @var IAppManager */
	private $appManager;
	/** @var Installer */
	private $installer;
	/** @var Os */
	protected $os;

	/**
	 * SystemStatistics constructor.
	 *
	 * @param IConfig $config
	 * @param IAppManager $appManager
	 * @param Installer $installer
	 * @param Os $os
	 * @throws \Exception
	 */
	public function __construct(IConfig $config, IAppManager $appManager, Installer $installer, Os $os) {
		$this->config = $config;
		$this->view = new View();
		$this->appManager = $appManager;
		$this->installer = $installer;
		$this->os = $os;
	}

	/**
	 * Get statistics about the system
	 *
	 * @return array with with of data
	 * @throws \OCP\Files\InvalidPathException
	 */
	public function getSystemStatistics() {
		$processorUsage = $this->getProcessorUsage();
		$memoryUsage = $this->os->getMemory();
		return [
			'version' => $this->config->getSystemValue('version'),
			'theme' => $this->config->getSystemValue('theme', 'none'),
			'enable_avatars' => $this->config->getSystemValue('enable_avatars', true) ? 'yes' : 'no',
			'enable_previews' => $this->config->getSystemValue('enable_previews', true) ? 'yes' : 'no',
			'memcache.local' => $this->config->getSystemValue('memcache.local', 'none'),
			'memcache.distributed' => $this->config->getSystemValue('memcache.distributed', 'none'),
			'filelocking.enabled' => $this->config->getSystemValue('filelocking.enabled', true) ? 'yes' : 'no',
			'memcache.locking' => $this->config->getSystemValue('memcache.locking', 'none'),
			'debug' => $this->config->getSystemValue('debug', false) ? 'yes' : 'no',
			'freespace' => $this->view->free_space(),
			'cpuload' => $processorUsage['loadavg'],
			'mem_total' => $memoryUsage['MemTotal'],
			'mem_free' => $memoryUsage['MemFree'],
			'swap_total' => $memoryUsage['SwapTotal'],
			'swap_free' => $memoryUsage['SwapFree'],
			'apps' => $this->getAppsInfo()
		];
	}

	/**
	 * Get some info about installed apps, including available updates.
	 *
	 * @return array data about apps
	 */
	protected function getAppsInfo() {

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
	 * @return array load average with three values, 1/5/15 minutes average.
	 */
	protected function getProcessorUsage() {
		// get current system load average.
		$loadavg = sys_getloadavg();

		// check if we got any values back.
		if (!(is_array($loadavg) && count($loadavg) === 3)) {
			// either no array or too few array keys.
			// returning back zeroes to prevent any errors on JS side.
			$loadavg = 'N/A';
		}

		return [
			'loadavg' => $loadavg
		];
	}
}
