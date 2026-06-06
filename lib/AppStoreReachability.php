<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;

class AppStoreReachability {
	private const CACHE_TTL = 3600;
	private const TIMEOUT = 4;

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @return array{
	 *     reachable: bool,
	 *     statusCode: int,
	 *     latencyMs: int,
	 *     checkedAt: int,
	 *     cached: bool
	 * }
	 */
	public function check(): array {
		$cachedAt = $this->appConfig->getValueInt('serverinfo', 'appstore_check_at', 0);
		if ($cachedAt > 0 && (time() - $cachedAt) < self::CACHE_TTL) {
			return [
				'reachable' => $this->appConfig->getValueBool('serverinfo', 'appstore_check_reachable'),
				'statusCode' => $this->appConfig->getValueInt('serverinfo', 'appstore_check_status'),
				'latencyMs' => $this->appConfig->getValueInt('serverinfo', 'appstore_check_latency'),
				'checkedAt' => $cachedAt,
				'cached' => true,
			];
		}

		$client = $this->clientService->newClient();
		$start = microtime(true);
		$reachable = false;
		$status = 0;
		try {
			$response = $client->head('https://apps.nextcloud.com/api/v1/platform/29.0.0/apps.json', [
				'timeout' => self::TIMEOUT,
				'connect_timeout' => self::TIMEOUT,
				'verify' => true,
			]);
			$status = $response->getStatusCode();
			$reachable = $status >= 200 && $status < 400;
		} catch (\Throwable) {
			$reachable = false;
		}
		$latency = (int)round((microtime(true) - $start) * 1000);
		$now = time();
		$this->appConfig->setValueInt('serverinfo', 'appstore_check_at', $now);
		$this->appConfig->setValueBool('serverinfo', 'appstore_check_reachable', $reachable);
		$this->appConfig->setValueInt('serverinfo', 'appstore_check_status', $status);
		$this->appConfig->setValueInt('serverinfo', 'appstore_check_latency', $latency);
		return [
			'reachable' => $reachable,
			'statusCode' => $status,
			'latencyMs' => $latency,
			'checkedAt' => $now,
			'cached' => false,
		];
	}
}
