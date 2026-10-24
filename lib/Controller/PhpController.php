<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Controller;

use OCA\ServerInfo\Database\Advisor;
use OCA\ServerInfo\Database\RuleResult;
use OCA\ServerInfo\Php\PhpProbe;
use OCA\ServerInfo\Php\PhpRuleSet;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\ISession;

/**
 * The PHP performance checks on the Status page.
 *
 * Admin-only by the app framework's default: none of these methods carries
 * NoAdminRequired, so the security middleware rejects everyone else.
 *
 * @psalm-api
 */
class PhpController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ISession $session,
		private PhpProbe $probe,
		private PhpRuleSet $ruleSet,
		private Advisor $advisor,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Run the checks against the PHP serving this request.
	 *
	 * PHP-FPM and OPcache state is per SAPI, so this is evaluated live on
	 * every call rather than taken from a background job. It costs
	 * fpm_get_status(), opcache_get_status() and a short /proc read per
	 * worker.
	 */
	public function check(): JSONResponse {
		// Released early so that the Status page's polling does not queue up
		// behind this request. Nothing below writes to the session.
		$this->session->close();

		$snapshot = $this->probe->snapshot();

		return new JSONResponse([
			'sapi' => PHP_SAPI,
			'pool' => $snapshot->details['fpm_pool'][0] ?? null,
			'ranAt' => time(),
			'results' => array_map(
				static fn (RuleResult $result): array => $result->jsonSerialize(),
				$this->advisor->run($this->ruleSet, $snapshot),
			),
		]);
	}
}
