<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\History;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\DatabaseStatistics;
use OCA\ServerInfo\Db\Sample;
use OCA\ServerInfo\Db\SampleMapper;
use OCA\ServerInfo\Os;
use OCA\ServerInfo\SessionStatistics;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Takes the five-minute samples of the history. It runs from a background
 * job, so it records only what a cron process sees the same way the web
 * server does: PHP-FPM and OPcache figures would be the CLI process's own.
 *
 * @psalm-api
 */
class Sampler {
	/** How long a measured database size is reused before it is measured again. */
	private const DB_SIZE_TTL = 3600;
	private const NODE_NAME_LENGTH = 64;

	public function __construct(
		private Os $os,
		private SessionStatistics $sessionStatistics,
		private DatabaseStatistics $databaseStatistics,
		private SampleMapper $mapper,
		private IConfig $config,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Stores a sample for the current five-minute bucket. Returns false when
	 * this node already has one there.
	 */
	public function sample(): bool {
		$now = $this->timeFactory->getTime();
		$bucketStart = intdiv($now, Sample::RESOLUTION_RAW) * Sample::RESOLUTION_RAW;
		$node = $this->nodeName();
		if ($this->mapper->hasBucket(Sample::RESOLUTION_RAW, $bucketStart, $node)) {
			return false;
		}

		$sample = new Sample();
		$sample->setNodeName($node);
		$sample->setResolution(Sample::RESOLUTION_RAW);
		$sample->setBucketStart($bucketStart);
		$sample->setSamples(1);
		$this->fillSystem($sample);
		$this->fillNextcloud($sample, $now);
		foreach (Sample::PEAKS as $peak => $metric) {
			$sample->setMetric($peak, $sample->getMetric($metric));
		}

		try {
			$this->mapper->insert($sample);
		} catch (Exception $e) {
			// Another cron process sampled the same bucket in the meantime.
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return false;
			}
			throw $e;
		}

		return true;
	}

	/**
	 * A short name of this server, so that the samples of several web
	 * servers sharing one database can be told apart.
	 */
	public function nodeName(): string {
		$name = trim($this->os->getHostname());
		return $name === '' ? 'unknown' : mb_substr($name, 0, self::NODE_NAME_LENGTH);
	}

	private function fillSystem(Sample $sample): void {
		$load = $this->os->getAverageLoad();
		if (is_array($load) && count($load) >= 3) {
			$sample->setMetric('load1', (float)$load[0]);
			$sample->setMetric('load5', (float)$load[1]);
			$sample->setMetric('load15', (float)$load[2]);
		}

		$memory = $this->os->getMemory();
		if ($memory->getMemTotal() > 0 && $memory->getMemAvailable() >= 0) {
			$sample->setMetric('memTotal', $memory->getMemTotal());
			$sample->setMetric('memUsed', max(0, $memory->getMemTotal() - $memory->getMemAvailable()));
		}
		if ($memory->getSwapTotal() >= 0 && $memory->getSwapFree() >= 0) {
			$sample->setMetric('swapTotal', $memory->getSwapTotal());
			$sample->setMetric('swapUsed', max(0, $memory->getSwapTotal() - $memory->getSwapFree()));
		}

		$disk = $this->diskSpace($this->config->getSystemValueString('datadirectory', ''));
		if ($disk !== null) {
			$sample->setMetric('diskFree', $disk['free']);
			$sample->setMetric('diskTotal', $disk['total']);
		}
	}

	private function fillNextcloud(Sample $sample, int $now): void {
		try {
			$sessions = $this->sessionStatistics->getSessionStatistics();
			$sample->setMetric('users5min', (int)$sessions['last5minutes']);
			$sample->setMetric('users24h', (int)$sessions['last24hours']);
		} catch (\Throwable $e) {
			$this->logger->warning('serverinfo: could not count active users for the history', ['exception' => $e]);
		}

		// Written by the storage statistics job; the count is only as old as its last run.
		if ($this->appConfig->hasAppKey('cached_count_filecache', null)) {
			$sample->setMetric('files', $this->appConfig->getAppValueInt('cached_count_filecache'));
		}

		$sample->setMetric('dbSize', $this->databaseSize($now));
	}

	/**
	 * Free and total bytes of the file system holding the data directory.
	 * Free is what Nextcloud may still use, so blocks reserved for root
	 * count as used.
	 *
	 * @return array{free: int, total: int}|null
	 */
	private function diskSpace(string $path): ?array {
		if ($path === '' || !@is_dir($path)) {
			return null;
		}
		$free = @disk_free_space($path);
		$total = @disk_total_space($path);
		if ($free === false || $total === false || $total <= 0) {
			return null;
		}

		return ['free' => (int)$free, 'total' => (int)$total];
	}

	/**
	 * The database size, measured at most once an hour: on MySQL it walks
	 * the status of every table.
	 */
	private function databaseSize(int $now): ?int {
		$cached = $this->appConfig->getAppValueArray(ConfigLexicon::CACHED_HISTORY_DB_SIZE);
		if (isset($cached['size'], $cached['measuredAt']) && $now - (int)$cached['measuredAt'] < self::DB_SIZE_TTL) {
			$size = (int)$cached['size'];
		} else {
			try {
				$size = $this->databaseStatistics->getDatabaseStatistics()['size'];
			} catch (\Throwable $e) {
				$this->logger->warning('serverinfo: could not measure the database size for the history', ['exception' => $e]);
				$size = -1;
			}
			$this->appConfig->setAppValueArray(ConfigLexicon::CACHED_HISTORY_DB_SIZE, ['size' => $size, 'measuredAt' => $now]);
		}

		return $size >= 0 ? $size : null;
	}
}
