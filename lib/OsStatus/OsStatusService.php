<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

use OCA\ServerInfo\Jobs\UpdateOsStatus;
use OCA\ServerInfo\OsStatus\Host\IHost;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessProbe;
use OCA\ServerInfo\OsStatus\Restart\PhpProcessSample;
use OCA\ServerInfo\OsStatus\Verdict\OsVerdict;
use OCA\ServerInfo\OsStatus\Verdict\Thresholds;
use OCA\ServerInfo\OsStatus\Verdict\Verdict;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig as IServerConfig;

/**
 * Reads the stored status and judges it. Everything that shows the
 * operating system status goes through here, and nothing here runs a command.
 *
 * @psalm-api
 */
class OsStatusService {
	public function __construct(
		private OsStatusStore $store,
		private OsVerdict $verdict,
		private PhpProcessProbe $phpProcessProbe,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private IHost $host,
		private IServerConfig $serverConfig,
	) {
	}

	public function getStatus(): ?OsStatus {
		return $this->store->load();
	}

	/**
	 * Looks at the PHP process serving this request and remembers what it
	 * found. Cheap: one read of /proc/self/maps.
	 */
	public function samplePhpProcess(): void {
		if (!$this->phpProcessProbe->canSample()) {
			return;
		}

		$sample = $this->phpProcessProbe->sample($this->store->load()?->release, $this->timeFactory->getTime());
		if ($sample !== null) {
			$this->store->savePhpSample($sample);
		}
	}

	public function getPhpSample(): ?PhpProcessSample {
		return $this->store->loadPhpSample();
	}

	public function getThresholds(): Thresholds {
		return Thresholds::load($this->appConfig);
	}

	public function evaluate(?OsStatus $status = null): ?Verdict {
		$status ??= $this->store->load();
		if ($status === null) {
			return null;
		}

		return $this->verdict->evaluate(
			$status,
			$this->store->loadPhpSample(),
			$this->store->phpOutdatedSince(),
			$this->getThresholds(),
			$this->timeFactory->getTime(),
			UpdateOsStatus::runsFromSystemCron($this->serverConfig),
			UpdateOsStatus::interval($this->serverConfig),
		);
	}

	/**
	 * Whether packages were installed or removed after the status was
	 * collected, so its pending updates may already be installed.
	 */
	public function isOutdated(OsStatus $status): bool {
		if ($status->packageDatabase === null) {
			return false;
		}

		$changedAt = $this->host->modifiedAt($status->packageDatabase);

		return $changedAt !== null && $changedAt > $status->collectedAt;
	}
}
