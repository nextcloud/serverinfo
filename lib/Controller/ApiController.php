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
use OCP\IL10N;

class ApiController extends OCSController {

	/** @var IL10N */
	private $l;

	/** @var Os */
	private $os;

	/** @var IConfig */
	private $config;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IUserSession */
	private $userSession;

	/** @var SystemStatistics */
	private $systemStatistics;

	/** @var StorageStatistics */
	private $storageStatistics;

	/** @var PhpStatistics */
	private $phpStatistics;

	/** @var DatabaseStatistics  */
	private $databaseStatistics;

	/** @var ShareStatistics */
	private $shareStatistics;

	/** @var SessionStatistics */
	private $sessionStatistics;

	/**
	 * ApiController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param IConfig $config
	 * @param IGroupManager $groupManager
	 * @param IUserSession $userSession
	 * @param IL10N $l
	 * @param Os $os
	 * @param SystemStatistics $systemStatistics
	 * @param StorageStatistics $storageStatistics
	 * @param PhpStatistics $phpStatistics
	 * @param DatabaseStatistics $databaseStatistics
	 * @param ShareStatistics $shareStatistics
	 * @param SessionStatistics $sessionStatistics
	 */
	public function __construct($appName,
								IRequest $request,
								IConfig $config,
								IGroupManager $groupManager,
								?IUserSession $userSession,
								IL10N $l,
								Os $os,
								SystemStatistics $systemStatistics,
								StorageStatistics $storageStatistics,
								PhpStatistics $phpStatistics,
								DatabaseStatistics $databaseStatistics,
								ShareStatistics $shareStatistics,
								SessionStatistics $sessionStatistics) {
		parent::__construct($appName, $request);

		$this->l                  = $l;
		$this->config             = $config;
		$this->groupManager       = $groupManager;
		$this->userSession        = $userSession;
		$this->os                 = $os;
		$this->systemStatistics   = $systemStatistics;
		$this->storageStatistics  = $storageStatistics;
		$this->phpStatistics      = $phpStatistics;
		$this->databaseStatistics = $databaseStatistics;
		$this->shareStatistics    = $shareStatistics;
		$this->sessionStatistics  = $sessionStatistics;
	}

	private function checkAuthorized() {
		$token = $this->request->getHeader('NC-Token');
		if (!empty($token)) {
			$storedToken = $this->config->getAppValue('serverinfo', 'token', null);
			if (hash_equals($storedToken, $token)) {
				return true;
			}
		}

		$userSession = $this->userSession;
		if ($userSession === null) {
			return false;
		}

		$user = $userSession->getUser();
		if ($user === null) {
			return false;
		}

		if (!$this->groupManager->isAdmin($user->getUID())) {
			return false;
		};

		return true;
	}

	/**
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 * @PublicPage
	 *
	 * @return DataResponse
	 */
	public function info() {
		if (!$this->checkAuthorized()) {
			$response = new DataResponse(['message' => 'Unauthorized']);
			$response->setStatus(Http::STATUS_UNAUTHORIZED);
			return $response;
		}
		return new DataResponse([
			'nextcloud' => [
				'system'  => $this->systemStatistics->getSystemStatistics(),
				'storage' => $this->storageStatistics->getStorageStatistics(),
				'shares'  => $this->shareStatistics->getShareStatistics()
			],
			'server' => [
				'webserver' => $this->getWebserver(),
				'php'       => $this->phpStatistics->getPhpStatistics(),
				'database'  => $this->databaseStatistics->getDatabaseStatistics()
			],
			'activeUsers' => $this->sessionStatistics->getSessionStatistics()
		]);
	}

	/**
	 * @return DataResponse
	 */
	public function BasicData(): DataResponse {
		$servertime  = $this->os->getTime();
		$uptime      = $this->formatUptime($this->os->getUptime());

		return new DataResponse([
			'servertime' => $servertime,
			'uptime' => $uptime
		]);
	}

	/**
	 * @return DataResponse
	 */
	public function DiskData(): DataResponse {
		$result = $this->os->getDiskData();
		return new DataResponse($result);
	}

	/**
	 * get webserver
	 *
	 * @return string
	 */
	private function getWebserver() {
		if (isset($_SERVER['SERVER_SOFTWARE'])) {
			return $_SERVER['SERVER_SOFTWARE'];
		}
		return 'unknown';
	}

	/**
	 * Return the uptime of the system as human readable value
	 *
	 * @param int $uptime
	 * @return string
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
		$hours = $this->l->n('%n hour', '%n hours', $interval->format('%h'));
		$minutes = $this->l->n('%n minute', '%n minutes', $interval->format('%i'));
		$seconds = $this->l->n('%n second', '%n seconds', $interval->format('%s'));
		$result = $hours . ' ' . $minutes . ' ' . $seconds;

		if ($interval->days > 0) {
			$days = $this->l->n('%n day', '%n days', $interval->format('%a'));
			$result = $days . ' ' . $result;
		}
		
		return $result;
	}
}
