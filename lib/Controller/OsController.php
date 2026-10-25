<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Controller;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\Jobs\RefreshOsStatus;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\OsStatus\Helper\HelperReportReader;
use OCA\ServerInfo\OsStatus\Notifications\OsNotificationSender;
use OCA\ServerInfo\OsStatus\OsStatus;
use OCA\ServerInfo\OsStatus\OsStatusCollector;
use OCA\ServerInfo\OsStatus\OsStatusService;
use OCA\ServerInfo\OsStatus\Restart\ServiceCommands;
use OCA\ServerInfo\OsStatus\Verdict\Concern;
use OCA\ServerInfo\OsStatus\Verdict\ConcernTexts;
use OCA\ServerInfo\OsStatus\Verdict\Thresholds;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig as IServerConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The operating system page. Admin only, like the rest of the app.
 *
 * @psalm-api
 */
class OsController extends Controller {
	/** Where the setup instructions tell admins to install the helper. */
	public const HELPER_INSTALL_PATH = '/usr/local/sbin/serverinfo-os-report';
	/** Whether admins are notified when nothing was chosen */
	private const NOTIFICATIONS_DEFAULT = true;

	public function __construct(
		string $appName,
		IRequest $request,
		private OsStatusService $service,
		private OsStatusCollector $collector,
		private OsNotificationSender $notifications,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private IJobList $jobList,
		private IServerConfig $serverConfig,
		private ServiceCommands $serviceCommands,
		private ConcernTexts $texts,
		private IL10N $l10n,
		private Os $os,
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
	 * Both values are required; a request without one is rejected with the
	 * name of the missing field.
	 *
	 * @param mixed $thresholds days per setting, see Thresholds
	 */
	#[PasswordConfirmationRequired]
	public function updateSettings(mixed $thresholds = null, ?bool $notifications = null): JSONResponse {
		try {
			$validated = Thresholds::fromInput(is_array($thresholds) ? $thresholds : []);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['field' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		if ($notifications === null) {
			return new JSONResponse(['field' => 'notifications'], Http::STATUS_BAD_REQUEST);
		}

		$validated->save($this->appConfig);
		$this->appConfig->setAppValueBool(ConfigLexicon::OS_NOTIFICATIONS, $notifications);
		$this->notifications->sync();

		return new JSONResponse($this->payload());
	}

	private function payload(): array {
		$status = $this->service->getStatus();
		$php = $this->service->getPhpSample();
		$init = $this->serviceCommands->initSystem();
		$services = [];
		foreach ($status?->restartServices ?? [] as $service) {
			$services[$service->unit] = ServiceCommands::restart($init, $service->unit);
		}

		return [
			'now' => $this->timeFactory->getTime(),
			'status' => $status?->toArray(),
			'osName' => $status?->state === OsStatus::STATE_UNSUPPORTED ? $this->os->getOSName() : null,
			'verdict' => $status !== null ? $this->verdict($status) : null,
			'outdated' => $status !== null && $this->service->isOutdated($status),
			'php' => $php?->toArray(),
			'initSystem' => $init,
			'commands' => [
				'reboot' => ServiceCommands::reboot($init),
				'php' => $php !== null && $php->service !== null ? ServiceCommands::restart($init, $php->service) : null,
				'services' => $services,
			],
			'thresholds' => $this->service->getThresholds()->toArray(),
			'notifications' => $this->appConfig->getAppValueBool(ConfigLexicon::OS_NOTIFICATIONS, self::NOTIFICATIONS_DEFAULT),
			'settingsDefaults' => [
				'thresholds' => (new Thresholds())->toArray(),
				'notifications' => self::NOTIFICATIONS_DEFAULT,
			],
			'thresholdLimits' => [
				'maxDays' => Thresholds::MAX_DAYS,
				'pairs' => Thresholds::PAIRS,
			],
			'refresh' => [
				'systemCron' => $this->hasSystemCron(),
				'running' => $this->collector->isCollecting() || $this->jobList->has(RefreshOsStatus::class, null),
			],
			'helper' => [
				'bundledVersion' => HelperReportReader::BUNDLED_VERSION,
				'installPath' => self::HELPER_INSTALL_PATH,
				'reportPath' => HelperReportReader::sanitizePath(
					$this->appConfig->getAppValueString(ConfigLexicon::OS_HELPER_REPORT_PATH, HelperReportReader::DEFAULT_PATH),
				),
			],
		];
	}

	/**
	 * The verdict with each concern's sentences, in the admin's language,
	 * so the page says the same as setup checks, notifications and widget.
	 */
	private function verdict(OsStatus $status): ?array {
		$verdict = $this->service->evaluate($status);
		if ($verdict === null) {
			return null;
		}

		return [
			'level' => $verdict->level(),
			'concerns' => array_map(fn (Concern $concern): array => $concern->toArray() + [
				'title' => $this->texts->title($concern, $status, $this->l10n),
				'detail' => $this->texts->detail($concern, $this->l10n),
			], $verdict->concerns),
		];
	}

	private function hasSystemCron(): bool {
		return $this->serverConfig->getValueString('core', 'backgroundjobs_mode', 'ajax') === 'cron';
	}
}
