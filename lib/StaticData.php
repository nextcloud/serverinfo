<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IURLGenerator;

class StaticData {
	public function __construct(
		private Os $os,
		private IURLGenerator $urlGenerator,
		private StorageStatistics $storageStatistics,
		private PhpStatistics $phpStatistics,
		private FpmStatistics $fpmStatistics,
		private DatabaseStatistics $databaseStatistics,
		private ShareStatistics $shareStatistics,
		private SessionStatistics $sessionStatistics,
		private SystemStatistics $systemStatistics,
		private CronInfo $cronInfo,
		private IAppConfig $appConfig,
	) {
	}

	public function getData(): array {
		$diskinfo = $this->os->getDiskInfo();
		$interfaces = $this->os->getNetworkInterfaces();
		$memory = $this->os->getMemory();

		return [
			'hostname' => $this->os->getHostname(),
			'osname' => $this->os->getOSName(),
			'cpu' => $this->os->getCPU(),
			'diskinfo' => array_map(fn ($d) => [
				'device' => $d->getDevice(),
				'fs' => $d->getFs(),
				'used' => $d->getUsed(),
				'available' => $d->getAvailable(),
				'percent' => $d->getPercent(),
				'mount' => $d->getMount(),
			], $diskinfo),
			'networkinfo' => $this->os->getNetworkInfo(),
			'networkinterfaces' => array_map(fn ($i) => [
				'name' => $i->getName(),
				'up' => $i->isUp(),
				'ipv4' => $i->getIPv4(),
				'ipv6' => $i->getIPv6(),
				'mac' => $i->getMAC(),
				'speed' => $i->getSpeed(),
				'duplex' => $i->getDuplex(),
				'loopback' => $i->isLoopback(),
			], $interfaces),
			'ocs' => $this->urlGenerator->getAbsoluteURL('ocs/v2.php/apps/serverinfo/api/v1/info'),
			'storage' => $this->storageStatistics->getStorageStatistics(),
			'shares' => $this->shareStatistics->getShareStatistics(),
			'php' => $this->phpStatistics->getPhpStatistics(),
			'fpm' => $this->fpmStatistics->getFpmStatistics(),
			'database' => $this->databaseStatistics->getDatabaseStatistics(),
			'activeUsers' => $this->sessionStatistics->getSessionStatistics(),
			'freeSpace' => $this->systemStatistics->getFreeSpace(),
			'memTotal' => $memory->getMemTotal(),
			'cron' => $this->cronInfo->getCronInfo(),
			'phpinfo' => $this->appConfig->getAppValueBool('phpinfo', false),
			'phpinfoUrl' => $this->urlGenerator->linkToRoute('serverinfo.page.phpinfo'),
		];
	}
}
