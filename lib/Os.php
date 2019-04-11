<?php
/**
 * @author Frank Karlitschek <frank@nextcloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\ServerInfo;

use bantu\IniGetWrapper\IniGetWrapper;
use OCA\ServerInfo\OperatingSystems\DefaultOs;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

class Os {

	/** @var IClientService */
	protected $clientService;

	/** @var IConfig */
	protected $config;

	/** @var IDBConnection */
	protected $connection;

	/** @var IniGetWrapper */
	protected $phpIni;

	/** @var \OCP\IL10N */
	protected $l;

	/** @var */
	protected $backend;

	/** @var */
	protected $osname;

	/**
	 * Os constructor.
	 *
	 * @param IClientService $clientService
	 * @param IConfig $config
	 * @param IDBConnection $connection
	 * @param IniGetWrapper $phpIni
	 * @param IL10N $l
	 */
	public function __construct(IClientService $clientService,
								IConfig $config,
								IDBConnection $connection,
								IniGetWrapper $phpIni,
								IL10N $l) {
		$this->clientService = $clientService;
		$this->config        = $config;
		$this->connection    = $connection;
		$this->phpIni        = $phpIni;
		$this->l             = $l;
		$this->backend = new DefaultOs();
	}

	/**
	 * @return bool
	 */
	public function supported() {
		$data = $this->backend->supported();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getHostname() {
		$data = $this->backend->getHostname();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getOSName() {
		$data = $this->osname;
		return $data;
	}

	/**
	 * @return string
	 */
	public function getMemory() {
		$data = $this->backend->getMemory();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getCPUName() {
		$data = $this->backend->getCPUName();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getTime() {
		$data = $this->backend->getTime();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getUptime() {
		$data = $this->backend->getUptime();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getTimeServers() {
		$data = $this->backend->getTimeServers();
		return explode("\n", $data);
	}

	/**
	 * @return string
	 */
	public function getDiskInfo() {
		$data = $this->backend->getDiskInfo();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getDiskData() {
		$disks = $this->backend->getDiskInfo();
		$data = array();
		$i = 0;
		foreach ($disks as $disk) {
			$data[$i] = [
				round(($disk['used']) / 1024 / 1024, 1),
				round($disk['available'] / 1024 / 1024, 1)
			];
			$i++;
		}

//		debug data
		//		$data = array('0'=>array(1,2),'1'=>array(4,5),'2'=>array(3,1));

		return $data;
	}

	/**
	 * @return string
	 */
	public function getNetworkInfo() {
		$data = $this->backend->getNetworkInfo();
		return $data;
	}

	/**
	 * @return string
	 */
	public function getNetworkInterfaces() {
		$data = $this->backend->getNetworkInterfaces();
		return $data;
	}

}
