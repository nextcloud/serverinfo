<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
	public function __construct(
		string $appName,
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
