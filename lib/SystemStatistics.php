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
use OCP\IConfig;

class SystemStatistics {

	/** @var IConfig */
	private $config;

	/** @var View view on data/ */
	private $view;

	/**
	 * SystemStatistics constructor.
	 *
	 * @param IConfig $config
	 */
	public function __construct(IConfig $config) {
		$this->config = $config;
		$this->view = new View();
	}

	public function getSystemStatistics() {
		$memoryUsage = $this->getMemoryUsage();
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
			'cpuload' => sys_getloadavg(),
			'mem_total' => $memoryUsage['mem_total'],
			'mem_free' => $memoryUsage['mem_free']
		];
	}

	/**
	 * get available and free memory including both RAM and Swap
	 *
	 * @return array with the two values 'mem_free' and 'mem_total'
	 */
	protected function getMemoryUsage() {
		$memoryUsage = @file_get_contents('/proc/meminfo');
		if ($memoryUsage === false) {
			return ['mem_free' => 'N/A', 'mem_total' => 'N/A'];
		}
		$array = explode(PHP_EOL, $memoryUsage);
		// the last value is a empty string after explode, skip it
		$values = array_slice($array, 0, count($array) - 1);
		$data = [];
		foreach($values as $value) {
			list($k, $v) = preg_split('/[\s:]+/', $value);
			$data[$k] = $v;
		}

		if (array_key_exists('MemAvailable', $data)) {
			// MemAvailable is only present in newer kernels (after 2014).
			$available = $data['MemAvailable'];
		} else {
			$available = $data['MemFree'];
		}

		return [
			'mem_free' => (int)$available + (int)$data['SwapFree'],
			'mem_total' => (int)$data['MemTotal'] + (int)$data['SwapTotal']
		];
	}

}
