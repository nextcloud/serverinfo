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

namespace OCA\ServerInfo\Controller;

use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\PhpStatistics;
use OCA\ServerInfo\SessionStatistics;
use OCA\ServerInfo\ShareStatistics;
use OCA\ServerInfo\StorageStatistics;
use OCA\ServerInfo\SystemStatistics;
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
	private SystemStatistics $systemStatistics;
	private StorageStatistics $storageStatistics;
	private PhpStatistics $phpStatistics;
	private DatabaseStatistics $databaseStatistics;
	private ShareStatistics $shareStatistics;
	private SessionStatistics $sessionStatistics;

	/**
	 * ApiController constructor.
	 */
	public function __construct(string $appName,
		IRequest $request,
		IConfig $config,
		IGroupManager $groupManager,
		?IUserSession $userSession,
		Os $os,
		SystemStatistics $systemStatistics,
		StorageStatistics $storageStatistics,
		PhpStatistics $phpStatistics,
		DatabaseStatistics $databaseStatistics,
		ShareStatistics $shareStatistics,
		SessionStatistics $sessionStatistics) {
		parent::__construct($appName, $request);

		$this->config = $config;
		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->os = $os;
		$this->systemStatistics = $systemStatistics;
		$this->storageStatistics = $storageStatistics;
		$this->phpStatistics = $phpStatistics;
		$this->databaseStatistics = $databaseStatistics;
		$this->shareStatistics = $shareStatistics;
		$this->sessionStatistics = $sessionStatistics;
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
		return new DataResponse([
			'nextcloud' => [
				'system' => $this->systemStatistics->getSystemStatistics($skipApps, $skipUpdate),
				'storage' => $this->storageStatistics->getStorageStatistics(),
				'shares' => $this->shareStatistics->getShareStatistics()
			],
			'server' => [
				'webserver' => $this->getWebserver(),
				'php' => $this->phpStatistics->getPhpStatistics(),
				'database' => $this->databaseStatistics->getDatabaseStatistics()
			],
			'activeUsers' => $this->sessionStatistics->getSessionStatistics()
		]);
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
	 * Get webserver information
	 */
	private function getWebserver(): string {
		if (isset($_SERVER['SERVER_SOFTWARE'])) {
			return $_SERVER['SERVER_SOFTWARE'];
		}
		return 'unknown';
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
