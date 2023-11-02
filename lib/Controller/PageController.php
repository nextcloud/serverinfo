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

use OCA\ServerInfo\PhpInfoResponse;
use OCA\ServerInfo\SystemStatistics;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;

class PageController extends Controller {
	public function __construct(string $appName,
		IRequest $request,
		private SystemStatistics $systemStatistics,
		private IConfig $config,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * request data update
	 */
	public function update(): JSONResponse {
		$data = [
			'system' => $this->systemStatistics->getSystemStatistics(true, true)
		];

		return new JSONResponse($data);
	}

	/**
	 * @NoCSRFRequired
	 */
	public function phpinfo(): Response {
		if ($this->config->getAppValue($this->appName, 'phpinfo', 'no') === 'yes') {
			return new PhpInfoResponse();
		}
		return new NotFoundResponse();
	}
}
