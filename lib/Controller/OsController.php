<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Controller;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\OsStatus\Helper\HelperReportReader;
use OCA\ServerInfo\OsStatus\Notifications\OsNotificationSender;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Verdict\Thresholds;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * The operating system page. Admin only, like the rest of the app.
 *
 * @psalm-api
 */
class OsController extends Controller {
	/** Where the setup instructions tell admins to install the helper. */
	public const HELPER_INSTALL_PATH = '/usr/local/sbin/serverinfo-os-report';

	public function __construct(
		string $appName,
		IRequest $request,
		private OsStatusService $service,
		private OsStatusCollector $collector,
		private OsNotificationSender $notifications,
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ITimeFactory $timeFactory,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The stored status, judged against the current thresholds. Runs no
	 * commands; the status is collected by the background job.
	 */
	public function status(): JSONResponse {
		$this->service->samplePhpProcess();

		return new JSONResponse($this->payload());
	}

	/**
	 * Collects the status now instead of waiting for the background job.
	 * This runs the package manager, so it is rate limited.
	 */
	#[UserRateLimit(limit: 6, period: 600)]
	public function refresh(): JSONResponse {
		$status = $this->collector->collectAndStore();
		$this->service->samplePhpProcess();
		if ($status === null) {
			return new JSONResponse(['message' => 'The operating system status could not be collected; see the Nextcloud log.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->notifications->sync();

		return new JSONResponse($this->payload());
	}

	/**
	 * @param array<string, mixed> $thresholds days per setting, see Thresholds
	 */
	public function updateSettings(array $thresholds, bool $notifications): JSONResponse {
		try {
			$validated = Thresholds::fromInput($thresholds);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['field' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$validated->save($this->appConfig);
		$this->appConfig->setAppValueBool(ConfigLexicon::OS_NOTIFICATIONS, $notifications);
		$this->notifications->sync();

		return new JSONResponse($this->payload());
	}

	private function payload(): array {
		$status = $this->service->getStatus();
		$php = $this->service->getPhpSample();

		return [
			'now' => $this->timeFactory->getTime(),
			'status' => $status?->toArray(),
			'verdict' => $status !== null ? $this->service->evaluate($status)?->toArray() : null,
			'outdated' => $status !== null && $this->service->isOutdated($status),
			'php' => $php?->toArray(),
			'thresholds' => $this->service->getThresholds()->toArray(),
			'notifications' => $this->appConfig->getAppValueBool(ConfigLexicon::OS_NOTIFICATIONS, true),
			'helper' => [
				'bundledVersion' => HelperReportReader::BUNDLED_VERSION,
				'scriptPath' => $this->appManager->getAppPath(Application::APP_ID) . '/bin/serverinfo-os-report',
				'installPath' => self::HELPER_INSTALL_PATH,
				'reportPath' => HelperReportReader::sanitizePath(
					$this->appConfig->getAppValueString(ConfigLexicon::OS_HELPER_REPORT_PATH, HelperReportReader::DEFAULT_PATH),
				),
			],
		];
	}
}
