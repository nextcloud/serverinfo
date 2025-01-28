<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Controller;

use OCA\ServerInfo\Os;
use OCA\ServerInfo\Service\InfoService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {
	private Os $os;
	private IConfig $config;
	private IGroupManager $groupManager;
	private ?IUserSession $userSession;
    private InfoService $infoService;

	/**
	 * ApiController constructor.
	 */
	public function __construct(string $appName,
		IRequest $request,
		IConfig $config,
		IGroupManager $groupManager,
		?IUserSession $userSession,
		Os $os,
        InfoService $infoService) { 
		parent::__construct($appName, $request);

		$this->config = $config;
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->os = $os;
        $this->infoService = $infoService;
	}

	/**
	 * Check if authorized to view serverinfo API.
	 */
	private function checkAuthorized(): bool {
		// check for monitoring privilege
		$token = $this->request->getHeader('NC-Token');
		if (!empty($token)) {
			$storedToken = $this->config->getAppValue('serverinfo', 'token', '');
			if (hash_equals($storedToken, $token)) {
				return true;
			}
		}

		// fallback to admin privilege
		$userSession = $this->userSession;
		if ($userSession === null) {
			return false;
		}

		$user = $userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 * @BruteForceProtection(action=serverinfo)
	 */
	public function info(bool $skipApps = true, bool $skipUpdate = true): DataResponse {
		if (!$this->checkAuthorized()) {
			$response = new DataResponse(['message' => 'Unauthorized']);
			$response->throttle();
			$response->setStatus(Http::STATUS_UNAUTHORIZED);
			return $response;
		}
		return new DataResponse($this->infoService->getServerInfo($skipApps, $skipUpdate));
	}

	public function BasicData(): DataResponse {
		$servertime = $this->os->getTime();
		$uptime = $this->formatUptime($this->os->getUptime());

		return new DataResponse([
			'servertime' => $servertime,
			'uptime' => $uptime,
			'thermalzones' => $this->os->getThermalZones()
		]);
	}

	public function DiskData(): DataResponse {
		$result = $this->os->getDiskData();
		return new DataResponse($result);
	}

	/**
	 * Return the uptime of the system as human readable value
	 */
	private function formatUptime(int $uptime): string {
		if ($uptime === -1) {
			return 'Unknown';
		}

		try {
			$boot = new \DateTime($uptime . ' seconds ago');
		} catch (\Exception $e) {
			return 'Unknown';
		}

		$interval = $boot->diff(new \DateTime());
		if ($interval->days > 0) {
			return $interval->format('%a days, %h hours, %i minutes, %s seconds');
		}
		return $interval->format('%h hours, %i minutes, %s seconds');
	}
}
