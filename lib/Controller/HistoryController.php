<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Controller;

use OCA\ServerInfo\Db\Sample;
use OCA\ServerInfo\Db\SampleMapper;
use OCA\ServerInfo\History\Range;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * The stored history for the charts. Admin only, like the rest of the app.
 *
 * @psalm-api
 */
class HistoryController extends Controller {
	/** Most points one response carries; the longest range needs 720. */
	public const MAX_POINTS = 1000;

	public function __construct(
		string $appName,
		IRequest $request,
		private SampleMapper $mapper,
		private ITimeFactory $timeFactory,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * One series per metric for the range, at the finest resolution kept for
	 * all of it, as parallel arrays with the bucket start times in `t`. With
	 * several nodes writing samples, the series is the one of the node that
	 * wrote the newest.
	 *
	 * @param string $range 24h, 7d, 30d or 1y
	 */
	public function series(string $range = '24h'): JSONResponse {
		$selected = Range::tryFrom($range);
		if ($selected === null) {
			return new JSONResponse(['field' => 'range'], Http::STATUS_BAD_REQUEST);
		}

		$now = $this->timeFactory->getTime();
		$from = $now - $selected->seconds();
		$resolution = $selected->resolution();
		$node = $this->mapper->latestNode($resolution);
		$rows = $node === null ? [] : $this->mapper->findSince($resolution, $from, $node, self::MAX_POINTS);

		$series = ['t' => [], 'samples' => []];
		foreach ([...Sample::METRICS, ...array_keys(Sample::PEAKS)] as $metric) {
			$series[$metric] = [];
		}
		foreach ($rows as $row) {
			$series['t'][] = $row->getBucketStart();
			$series['samples'][] = $row->getSamples();
			foreach ([...Sample::METRICS, ...array_keys(Sample::PEAKS)] as $metric) {
				$series[$metric][] = $row->getMetric($metric);
			}
		}

		return new JSONResponse([
			'range' => $selected->value,
			'resolution' => $resolution,
			'from' => $from,
			'now' => $now,
			'node' => $node,
			'series' => $series,
		]);
	}
}
