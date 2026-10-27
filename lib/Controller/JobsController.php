<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Controller;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\Collector\BackgroundJobs;
use OCA\ServerInfo\JobQueueActions;
use OCA\ServerInfo\JobQueueInfo;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The queue health on the Background jobs page and its two actions.
 *
 * Admin-only by the app framework's default: none of these methods carries
 * NoAdminRequired or AuthorizedAdminSetting, so delegated admins are
 * rejected too. No action runs job code; cron does, on its next run.
 *
 * @psalm-api
 */
class JobsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private JobQueueInfo $queueInfo,
		private JobQueueActions $actions,
		private BackgroundJobs $backgroundJobs,
		private ITimeFactory $timeFactory,
		private ISession $session,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Read when the page is opened, not with the minute poll: the totals scan
	 * the whole jobs table.
	 */
	public function queue(): JSONResponse {
		// Nothing below writes to the session, and on a large jobs table this
		// can take long enough to hold up the page's other requests.
		$this->session->close();

		$failures = $this->backgroundJobs->failuresByClass();
		$jobIds = $this->queueInfo->soleJobIds(array_column($failures, 'className'));

		return new JSONResponse([
			'now' => $this->timeFactory->getTime(),
			'stuckAfter' => JobQueueInfo::STUCK_THRESHOLD_SECONDS,
			'queue' => $this->queueInfo->getJobQueueInfo(),
			'failures' => array_map(static fn (array $failure): array => $failure + [
				'jobId' => $jobIds[$failure['className']] ?? null,
			], $failures),
		]);
	}

	/**
	 * Clears the reservation of a job that has been reserved for longer than
	 * cron honours, see JobQueueActions::release().
	 */
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 20, period: 600)]
	public function release(string $id): JSONResponse {
		$job = $this->findJob($id);
		if ($job instanceof JSONResponse) {
			return $job;
		}
		if (!$this->actions->isStuck($job)) {
			return new JSONResponse(['reason' => 'not-stuck'], Http::STATUS_CONFLICT);
		}
		if (!$this->actions->release($job)) {
			return new JSONResponse(['reason' => 'changed'], Http::STATUS_CONFLICT);
		}

		$this->log('released the reservation of background job {jobId} ({jobClass})', $job);
		$this->queueInfo->invalidate();

		return new JSONResponse(['id' => $job['id']]);
	}

	/**
	 * Makes a job due at the next cron run, see JobQueueActions::runAtNextCron().
	 * A job reserved within the stuck threshold may be running and is refused.
	 */
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 20, period: 600)]
	public function runAtNextCron(string $id): JSONResponse {
		$job = $this->findJob($id);
		if ($job instanceof JSONResponse) {
			return $job;
		}
		if ($this->actions->isReserved($job)) {
			return new JSONResponse(['reason' => 'running'], Http::STATUS_CONFLICT);
		}
		if (!$this->actions->runAtNextCron($job)) {
			return new JSONResponse(['reason' => 'changed'], Http::STATUS_CONFLICT);
		}

		$this->log('scheduled background job {jobId} ({jobClass}) for the next cron run', $job);
		$this->queueInfo->invalidate();

		return new JSONResponse(['id' => $job['id']]);
	}

	/**
	 * @return array{id: string, class: string, reservedAt: int}|JSONResponse
	 */
	private function findJob(string $id): array|JSONResponse {
		if (preg_match('/^[1-9][0-9]{0,19}$/', $id) !== 1) {
			return new JSONResponse(['reason' => 'invalid'], Http::STATUS_BAD_REQUEST);
		}

		return $this->actions->find($id) ?? new JSONResponse(['reason' => 'unknown'], Http::STATUS_NOT_FOUND);
	}

	/**
	 * Records who changed which job. The job's argument stays out: it can
	 * hold user ids or paths.
	 *
	 * @param array{id: string, class: string, reservedAt: int} $job
	 */
	private function log(string $what, array $job): void {
		$this->logger->info('Admin {actor} ' . $what, [
			'app' => Application::APP_ID,
			'actor' => $this->userSession->getUser()?->getUID() ?? '',
			'jobId' => $job['id'],
			'jobClass' => $job['class'],
		]);
	}
}
