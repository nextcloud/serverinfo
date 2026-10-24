<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Db;

use OCP\AppFramework\Db\SnowflakeAwareEntity;
use OCP\DB\Types;

/**
 * One point of the history: a five-minute sample, or the hourly or daily
 * aggregate of those samples. Memory and swap are in MB, disk space and the
 * database size in bytes. A metric that could not be read is null.
 *
 * @method string getNodeName()
 * @method void setNodeName(string $nodeName)
 * @method int getResolution()
 * @method void setResolution(int $resolution)
 * @method int getBucketStart()
 * @method void setBucketStart(int $bucketStart)
 * @method int getSamples()
 * @method void setSamples(int $samples)
 *
 * @psalm-suppress PossiblyUnusedProperty the properties are reached through Entity's magic accessors
 */
class Sample extends SnowflakeAwareEntity {
	public const RESOLUTION_RAW = 300;
	public const RESOLUTION_HOURLY = 3600;
	public const RESOLUTION_DAILY = 86400;

	/** Metrics whose aggregate is the average of the samples. */
	public const METRICS = [
		'load1', 'load5', 'load15',
		'memUsed', 'memTotal', 'swapUsed', 'swapTotal',
		'diskFree', 'diskTotal',
		'users5min', 'users24h',
		'files', 'dbSize',
	];

	/** Metrics whose aggregate is the highest sample, and the metric they are taken from. */
	public const PEAKS = [
		'load1Max' => 'load1',
		'memUsedMax' => 'memUsed',
		'users5minMax' => 'users5min',
	];

	private const FLOATS = ['load1', 'load5', 'load15', 'load1Max'];

	protected ?string $nodeName = null;
	protected ?int $resolution = null;
	protected ?int $bucketStart = null;
	protected ?int $samples = null;
	protected ?float $load1 = null;
	protected ?float $load5 = null;
	protected ?float $load15 = null;
	protected ?float $load1Max = null;
	protected ?int $memUsed = null;
	protected ?int $memUsedMax = null;
	protected ?int $memTotal = null;
	protected ?int $swapUsed = null;
	protected ?int $swapTotal = null;
	protected ?int $diskFree = null;
	protected ?int $diskTotal = null;
	protected ?int $users5min = null;
	protected ?int $users5minMax = null;
	protected ?int $users24h = null;
	protected ?int $files = null;
	protected ?int $dbSize = null;

	public function __construct() {
		$this->addType('id', Types::STRING);
		$this->addType('nodeName', Types::STRING);
		$this->addType('resolution', Types::INTEGER);
		$this->addType('bucketStart', Types::BIGINT);
		$this->addType('samples', Types::INTEGER);
		foreach ([...self::METRICS, ...array_keys(self::PEAKS)] as $metric) {
			$this->addType($metric, in_array($metric, self::FLOATS, true) ? Types::FLOAT : Types::BIGINT);
		}
	}

	public static function isFloat(string $metric): bool {
		return in_array($metric, self::FLOATS, true);
	}

	public function getMetric(string $metric): int|float|null {
		/** @var int|float|null */
		return $this->getter($metric);
	}

	public function setMetric(string $metric, int|float|null $value): void {
		$this->setter($metric, [$value]);
	}
}
