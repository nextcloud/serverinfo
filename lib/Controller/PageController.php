<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Controller;

use OCA\ServerInfo\LiveData;
use OCA\ServerInfo\PhpInfoResponse;
use OCA\ServerInfo\StaticData;
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
		private IConfig $config,
		private StaticData $staticData,
		private LiveData $liveData,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * One-time snapshot of static/slow-changing server data for the admin page.
	 */
	public function data(): JSONResponse {
		return new JSONResponse($this->staticData->getData());
	}

	/**
	 * request data update
	 */
	public function update(): JSONResponse {
		return new JSONResponse($this->liveData->getData());
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
