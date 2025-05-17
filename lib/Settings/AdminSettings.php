<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo\Settings;

use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\FpmStatistics;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\StorageStatistics;
use OCA\ServerInfo\SystemStatistics;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
	public function __construct(
		private Os $os,
		private IL10N $l,
		private IURLGenerator $urlGenerator,
		private StorageStatistics $storageStatistics,
		private PhpStatistics $phpStatistics,
		private FpmStatistics $fpmStatistics,
		private DatabaseStatistics $databaseStatistics,
		private ShareStatistics $shareStatistics,
		private SessionStatistics $sessionStatistics,
		private SystemStatistics $systemStatistics,
		private IConfig $config,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		$monitoringEndPoint = $this->urlGenerator->getAbsoluteURL('ocs/v2.php/apps/serverinfo/api/v1/info');
		$params = [
			'hostname' => $this->os->getHostname(),
			'osname' => $this->os->getOSName(),
			'memory' => $this->os->getMemory(),
			'cpu' => $this->os->getCPU(),
			'diskinfo' => $this->os->getDiskInfo(),
			'networkinfo' => $this->os->getNetworkInfo(),
			'networkinterfaces' => $this->os->getNetworkInterfaces(),
			'ocs' => $monitoringEndPoint,
			'storage' => $this->storageStatistics->getStorageStatistics(),
			'shares' => $this->shareStatistics->getShareStatistics(),
			'php' => $this->phpStatistics->getPhpStatistics(),
			'fpm' => $this->fpmStatistics->getFpmStatistics(),
			'database' => $this->databaseStatistics->getDatabaseStatistics(),
			'activeUsers' => $this->sessionStatistics->getSessionStatistics(),
			'system' => $this->systemStatistics->getSystemStatistics(true, true),
			'thermalzones' => $this->os->getThermalZones(),
			'phpinfo' => $this->config->getAppValue('serverinfo', 'phpinfo', 'no') === 'yes',
			'phpinfoUrl' => $this->urlGenerator->linkToRoute('serverinfo.page.phpinfo')
		];

		return new TemplateResponse('serverinfo', 'settings-admin', $params);
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	#[\Override]
	public function getSection(): string {
		return 'serverinfo';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 *             the admin section. The forms are arranged in ascending order of the
	 *             priority values. It is required to return a value between 0 and 100.
	 *
	 * keep the server setting at the top, right after "server settings"
	 */
	#[\Override]
	public function getPriority(): int {
		return 0;
	}
}
