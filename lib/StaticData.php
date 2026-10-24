<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCA\ServerInfo\Collector\Opcache;
use OCA\ServerInfo\Collector\Php;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IURLGenerator;

class StaticData {
	public function __construct(
		private Os $os,
		private IURLGenerator $urlGenerator,
		private StatisticsCache $statisticsCache,
		private Php $php,
		private Opcache $opcache,
		private FpmStatistics $fpmStatistics,
		private SystemStatistics $systemStatistics,
		private IAppConfig $appConfig,
		private OsStatusService $osStatus,
	) {
	}

	public function getData(): array {
		$diskinfo = $this->os->getDiskInfo();
		$interfaces = $this->os->getNetworkInterfaces();
		$memory = $this->os->getMemory();
		$statistics = $this->statisticsCache->get();

		return [
			'hostname' => $this->os->getHostname(),
			'osname' => $this->os->getOSName(),
			'osLevel' => $this->osStatus->evaluate()?->level(),
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
			'storage' => $statistics['storage'],
			'shares' => $statistics['shares'],
			'php' => $this->php->getData(),
			'opcache' => $this->opcache->getData(),
			'fpm' => $this->fpmStatistics->getFpmStatistics(),
			'database' => $statistics['database'],
			'activeUsers' => $statistics['activeUsers'],
			'freeSpace' => $this->systemStatistics->getFreeSpace(),
			'memTotal' => $memory->getMemTotal(),
			'phpinfo' => $this->appConfig->getAppValueBool('phpinfo', false),
			'phpinfoUrl' => $this->urlGenerator->linkToRoute('serverinfo.page.phpinfo'),
		];
	}
}
