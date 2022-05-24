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


namespace OCA\ServerInfo\Settings;

use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\StorageStatistics;
use OCA\ServerInfo\SystemStatistics;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
	private Os $os;
	private IL10N $l;
	private IURLGenerator $urlGenerator;
	private StorageStatistics $storageStatistics;
	private PhpStatistics $phpStatistics;
	private DatabaseStatistics $databaseStatistics;
	private ShareStatistics $shareStatistics;
	private SessionStatistics $sessionStatistics;
	private SystemStatistics $systemStatistics;

	public function __construct(Os $os,
								IL10N $l,
								IURLGenerator $urlGenerator,
								StorageStatistics $storageStatistics,
								PhpStatistics $phpStatistics,
								DatabaseStatistics $databaseStatistics,
								ShareStatistics $shareStatistics,
								SessionStatistics $sessionStatistics,
								SystemStatistics $systemStatistics
	) {
		$this->os = $os;
		$this->l = $l;
		$this->urlGenerator = $urlGenerator;
		$this->storageStatistics = $storageStatistics;
		$this->phpStatistics = $phpStatistics;
		$this->databaseStatistics = $databaseStatistics;
		$this->shareStatistics = $shareStatistics;
		$this->sessionStatistics = $sessionStatistics;
		$this->systemStatistics = $systemStatistics;
	}

	public function getForm(): TemplateResponse {
		$monitoringEndPoint = $this->urlGenerator->getAbsoluteURL('ocs/v2.php/apps/serverinfo/api/v1/info');
		$params = [
			'hostname' => $this->os->getHostname(),
			'osname' => $this->os->getOSName(),
			'memory' => $this->os->getMemory(),
			'cpu' => $this->os->getCpuName(),
			'diskinfo' => $this->os->getDiskInfo(),
			'networkinfo' => $this->os->getNetworkInfo(),
			'networkinterfaces' => $this->os->getNetworkInterfaces(),
			'ocs' => $monitoringEndPoint,
			'storage' => $this->storageStatistics->getStorageStatistics(),
			'shares' => $this->shareStatistics->getShareStatistics(),
			'php' => $this->phpStatistics->getPhpStatistics(),
			'database' => $this->databaseStatistics->getDatabaseStatistics(),
			'activeUsers' => $this->sessionStatistics->getSessionStatistics(),
			'system' => $this->systemStatistics->getSystemStatistics(),
			'thermalzones' => $this->os->getThermalZones()
		];

		return new TemplateResponse('serverinfo', 'settings-admin', $params);
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	public function getSection(): string {
		return 'serverinfo';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 * the admin section. The forms are arranged in ascending order of the
	 * priority values. It is required to return a value between 0 and 100.
	 *
	 * keep the server setting at the top, right after "server settings"
	 */
	public function getPriority(): int {
		return 0;
	}
}
