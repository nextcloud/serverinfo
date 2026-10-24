<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Jobs;

use OCA\ServerInfo\AppInfo\Application;
use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\History\Downsampler;
use OCA\ServerInfo\History\Sampler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Records a history sample, then updates the hourly and daily rows and
 * removes expired ones.
 *
 * @psalm-api
 */
class CollectHistory extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Sampler $sampler,
		private Downsampler $downsampler,
		private LoggerInterface $logger,
		IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval($appConfig->getValueInt(Application::APP_ID, ConfigLexicon::JOB_INTERVAL_HISTORY));
	}

	#[\Override]
	protected function run($argument): void {
		try {
			$this->sampler->sample();
		} catch (\Throwable $e) {
			// The samples already stored still need their rollups and expiry.
			$this->logger->error('serverinfo: could not record a history sample', ['exception' => $e]);
		}

		$this->downsampler->run();
	}
}
