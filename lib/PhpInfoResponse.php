<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Http\Response;

/**
 * @template-extends Response<Http::STATUS_*, array<string, mixed>>
 */
class PhpInfoResponse extends Response {
	public function __construct() {
		parent::__construct();

		$this->setContentSecurityPolicy(new ContentSecurityPolicy());
		$this->setFeaturePolicy(new FeaturePolicy());
	}

	#[\Override]
	public function render() {
		ob_start();
		phpinfo(INFO_ALL & ~INFO_ENVIRONMENT & ~INFO_VARIABLES);
		return ob_get_clean();
	}
}
