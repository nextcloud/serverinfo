<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo\Settings;

use OCA\ServerInfo\ActiveConnections;
use OCA\ServerInfo\ActivityRate;
use OCA\ServerInfo\AppStoreReachability;
use OCA\ServerInfo\CachingInfo;
use OCA\ServerInfo\CronInfo;
use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\DbHealth;
use OCA\ServerInfo\DiskGrowth;
use OCA\ServerInfo\EolInfo;
use OCA\ServerInfo\ExternalStoragesInfo;
use OCA\ServerInfo\FederationStats;
use OCA\ServerInfo\FpmStatistics;
use OCA\ServerInfo\JobQueueInfo;
use OCA\ServerInfo\LogTailReader;
use OCA\ServerInfo\LoginStats;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\OsUpdates;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\Resources\Disk;
use OCA\ServerInfo\Resources\Memory;
use OCA\ServerInfo\Resources\NetInterface;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\SlowestJobs;
use OCA\ServerInfo\StorageStatistics;
use OCA\ServerInfo\SystemStatistics;
use OCA\ServerInfo\TopUsersByQuota;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
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
		private JobQueueInfo $jobQueueInfo,
		private LogTailReader $logTailReader,
		private LoginStats $loginStats,
		private CachingInfo $cachingInfo,
		private SlowestJobs $slowestJobs,
		private DbHealth $dbHealth,
		private ExternalStoragesInfo $externalStoragesInfo,
		private AppStoreReachability $appStoreReachability,
		private TopUsersByQuota $topUsersByQuota,
		private ActivityRate $activityRate,
		private ActiveConnections $activeConnections,
		private DiskGrowth $diskGrowth,
		private FederationStats $federationStats,
		private EolInfo $eolInfo,
		private OsUpdates $osUpdates,
		private IInitialState $initialState,
		private IConfig $config,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('serverinfo', $this->buildState());

		Util::addStyle('serverinfo', 'settings-admin');
		Util::addScript('serverinfo', 'settings-admin');

		return new TemplateResponse('serverinfo', 'settings-admin', [], '');
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

	/**
	 * Build the payload consumed by the Vue admin frontend.
	 *
	 * Keys must stay in sync with `ServerInfoState` in
	 * `build/frontend/apps/serverinfo/src/types.ts`.
	 *
	 * @return array<string, mixed>
	 */
	private function buildState(): array {
		$systemStats = $this->systemStatistics->getSystemStatistics(true, true);
		$apps = $systemStats['apps'] ?? ['num_installed' => 0, 'num_updates_available' => 0, 'app_updates' => []];

		return [
			'hostname' => $this->os->getHostname(),
			'osname' => $this->os->getOSName(),
			'cpu' => $this->os->getCPU(),
			'memory' => $this->serializeMemory($this->os->getMemory()),
			'disks' => array_map([$this, 'serializeDisk'], $this->os->getDiskInfo()),
			'networkinfo' => $this->serializeNetworkInfo($this->os->getNetworkInfo()),
			'interfaces' => array_map([$this, 'serializeInterface'], array_values($this->os->getNetworkInterfaces())),
			'thermalzones' => array_values($this->os->getThermalZones()),
			'storage' => $this->storageStatistics->getStorageStatistics(),
			'shares' => $this->shareStatistics->getShareStatistics(),
			'php' => $this->phpStatistics->getPhpStatistics(),
			'fpm' => $this->fpmStatistics->getFpmStatistics(),
			'database' => $this->databaseStatistics->getDatabaseStatistics(),
			'activeUsers' => $this->sessionStatistics->getSessionStatistics(),
			'system' => $systemStats,
			'apps' => [
				'numInstalled' => (int)($apps['num_installed'] ?? 0),
				'numUpdatesAvailable' => (int)($apps['num_updates_available'] ?? 0),
				'appUpdates' => $this->serializeAppUpdates($apps['app_updates'] ?? []),
			],
			'cron' => $this->cronInfo->getCronInfo(),
			'jobQueue' => $this->jobQueueInfo->getJobQueueInfo(),
			'recentErrors' => $this->logTailReader->recentErrors(),
			'logins' => $this->loginStats->getStats(),
			'caching' => $this->cachingInfo->getCachingInfo(),
			'slowestJobs' => $this->slowestJobs->getSlowestJobs(),
			'dbHealth' => $this->dbHealth->getDbHealth(),
			'externalStorages' => $this->externalStoragesInfo->getExternalStorages(),
			'appStore' => $this->appStoreReachability->check(),
			'topUsers' => $this->topUsersByQuota->getTopUsers(),
			'activity' => $this->activityRate->getActivityRate(),
			'connections' => $this->activeConnections->getActiveConnections(),
			'diskGrowth' => $this->diskGrowth->getGrowthInfo(),
			'federation' => $this->federationStats->getFederationStats(),
			'eol' => $this->eolInfo->getEolInfo(),
			'osUpdates' => $this->osUpdates->getOsUpdates(),
			'phpinfoEnabled' => $this->config->getAppValue('serverinfo', 'phpinfo', 'no') === 'yes',
			'phpinfoUrl' => $this->urlGenerator->linkToRoute('serverinfo.page.phpinfo'),
			'updateUrl' => $this->urlGenerator->linkToRoute('serverinfo.page.update'),
			'appsAdminUrl' => $this->urlGenerator->linkToRoute('settings.AppSettings.viewApps', ['category' => 'updates']),
			'backgroundJobsUrl' => $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'server']) . '#backgroundjobs',
			'overviewUrl' => $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'overview']),
			'logSettingsUrl' => $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'logging']),
			'serverSettingsUrl' => $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'server']),
			'monitoringEndpoint' => $this->urlGenerator->getAbsoluteURL('ocs/v2.php/apps/serverinfo/api/v1/info'),
		];
	}

	/**
	 * @param array<string, string> $appUpdates
	 * @return list<array{id: string, version: string}>
	 */
	private function serializeAppUpdates(array $appUpdates): array {
		$out = [];
		foreach ($appUpdates as $appId => $version) {
			$out[] = [
				'id' => (string)$appId,
				'version' => (string)$version,
			];
		}
		return $out;
	}

	/**
	 * @return array{total: int, free: int, available: int, swapTotal: int, swapFree: int}
	 */
	private function serializeMemory(Memory $memory): array {
		return [
			'total' => $memory->getMemTotal(),
			'free' => $memory->getMemFree(),
			'available' => $memory->getMemAvailable(),
			'swapTotal' => $memory->getSwapTotal(),
			'swapFree' => $memory->getSwapFree(),
		];
	}

	/**
	 * @return array{device: string, fs: string, mount: string, used: int, available: int, percent: string}
	 */
	private function serializeDisk(Disk $disk): array {
		return [
			'device' => $disk->getDevice(),
			'fs' => $disk->getFs(),
			'mount' => $disk->getMount(),
			'used' => $disk->getUsed(),
			'available' => $disk->getAvailable(),
			'percent' => $disk->getPercent(),
		];
	}

	/**
	 * @param array<string, string> $info
	 * @return array{hostname: string, gateway: string, dns: string}
	 */
	private function serializeNetworkInfo(array $info): array {
		return [
			'hostname' => (string)($info['hostname'] ?? ''),
			'gateway' => trim((string)($info['gateway'] ?? '')),
			'dns' => trim((string)($info['dns'] ?? '')),
		];
	}

	/**
	 * @return array{name: string, up: bool, ipv4: string[], ipv6: string[], mac: string, speed: string, duplex: string, loopback: bool}
	 */
	private function serializeInterface(NetInterface $iface): array {
		return [
			'name' => $iface->getName(),
			'up' => $iface->isUp(),
			'ipv4' => $iface->getIPv4(),
			'ipv6' => $iface->getIPv6(),
			'mac' => $iface->getMAC(),
			'speed' => $iface->getSpeed(),
			'duplex' => $iface->getDuplex(),
			'loopback' => $iface->isLoopback(),
		];
	}
}
