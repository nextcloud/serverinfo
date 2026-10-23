<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Controller;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\Jobs\RefreshOsStatus;
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
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig as IServerConfig;
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
		private IJobList $jobList,
		private IServerConfig $serverConfig,
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
	 * Asks for a check now instead of waiting for the next scheduled one.
	 * The package manager may run for minutes, so the check is queued for
	 * the next cron run and the page polls the status until it changes.
	 * Background jobs that run inside web requests (AJAX, webcron) would
	 * hold a web server worker for that long, so this needs system cron.
	 */
	#[UserRateLimit(limit: 6, period: 600)]
	public function refresh(): JSONResponse {
		if (!$this->hasSystemCron()) {
			return new JSONResponse(['reason' => 'cron'], Http::STATUS_CONFLICT);
		}
		if ($this->collector->isCollecting()) {
			return new JSONResponse(['reason' => 'running'], Http::STATUS_CONFLICT);
		}

		$this->jobList->add(RefreshOsStatus::class);

		return new JSONResponse(['reason' => 'queued'], Http::STATUS_ACCEPTED);
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
			'refresh' => [
				'systemCron' => $this->hasSystemCron(),
				'running' => $this->collector->isCollecting() || $this->jobList->has(RefreshOsStatus::class, null),
			],
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

	private function hasSystemCron(): bool {
		return $this->serverConfig->getValueString('core', 'backgroundjobs_mode', 'ajax') === 'cron';
	}
}
