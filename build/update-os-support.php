<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Regenerates resources/os-support.json from the endoflife.date dataset
 * (https://endoflife.date, MIT License).
 *
 * Run it before a release: php build/update-os-support.php
 * The app itself never contacts endoflife.date.
 */

const API = 'https://endoflife.date/api/v1/products/%s/';

/**
 * os-release ID => endoflife.date product, how VERSION_ID maps to a release
 * cycle, and which paid programme (if any) extends security support.
 */
const PRODUCTS = [
	'ubuntu' => ['product' => 'ubuntu', 'cycle' => 'minor', 'extended' => 'ubuntu-pro'],
	'debian' => ['product' => 'debian', 'cycle' => 'major', 'extended' => 'debian-elts', 'lts' => true],
	'rhel' => ['product' => 'rhel', 'cycle' => 'major', 'extended' => 'rhel-els'],
	'almalinux' => ['product' => 'almalinux', 'cycle' => 'major', 'extended' => null],
	'rocky' => ['product' => 'rocky-linux', 'cycle' => 'major', 'extended' => null],
	'centos-stream' => ['product' => 'centos-stream', 'cycle' => 'major', 'extended' => null],
	'centos' => ['product' => 'centos', 'cycle' => 'major', 'extended' => null],
	'ol' => ['product' => 'oracle-linux', 'cycle' => 'major', 'extended' => 'oracle-extended'],
	'fedora' => ['product' => 'fedora', 'cycle' => 'major', 'extended' => null],
	'opensuse-leap' => ['product' => 'opensuse', 'cycle' => 'minor', 'extended' => null],
	'sles' => ['product' => 'sles', 'cycle' => 'minor', 'extended' => 'suse-ltss'],
	'alpine' => ['product' => 'alpine-linux', 'cycle' => 'minor', 'extended' => null],
	'amzn' => ['product' => 'amazon-linux', 'cycle' => 'major', 'extended' => null],
];

/** os-release IDs of rolling releases, which have no end of support. */
const ROLLING = [
	'arch', 'archarm', 'manjaro', 'endeavouros', 'cachyos', 'garuda',
	'opensuse-tumbleweed', 'opensuse-microos', 'opensuse-slowroll',
	'gentoo', 'void', 'nixos-unstable',
];

/** Releases whose support ended before this are left out; nothing that old runs a current Nextcloud. */
const OLDEST = '2018-01-01';

function fetchProduct(string $product): array {
	$context = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'nextcloud-serverinfo-build']]);
	$json = file_get_contents(sprintf(API, $product), false, $context);
	if ($json === false) {
		fwrite(STDERR, "Could not download $product\n");
		exit(1);
	}

	$data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
	if (!isset($data['result']['releases']) || !is_array($data['result']['releases'])) {
		fwrite(STDERR, "Unexpected response for $product\n");
		exit(1);
	}

	return $data['result']['releases'];
}

function date_or_null(mixed $value): ?string {
	return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
}

$releases = [];
foreach (PRODUCTS as $id => $config) {
	foreach (fetchProduct($config['product']) as $release) {
		$cycle = (string)($release['name'] ?? '');
		$securityEnds = date_or_null($release['eolFrom'] ?? null);
		$extendedEnds = $config['extended'] !== null ? date_or_null($release['eoesFrom'] ?? null) : null;
		if ($cycle === '' || $securityEnds === null) {
			continue;
		}
		if (max($securityEnds, $extendedEnds ?? '') < OLDEST) {
			continue;
		}

		$entry = ['securityEnds' => $securityEnds];
		if (($config['lts'] ?? false) && ($ltsFrom = date_or_null($release['eoasFrom'] ?? null)) !== null) {
			$entry['ltsFrom'] = $ltsFrom;
		}
		if ($extendedEnds !== null && $extendedEnds > $securityEnds) {
			$entry['extendedEnds'] = $extendedEnds;
			$entry['extended'] = $config['extended'];
		}

		$releases[$id . ':' . $cycle] = $entry;
	}
}

ksort($releases, SORT_NATURAL);

$table = [
	'source' => 'endoflife.date, MIT License, https://endoflife.date',
	'generatedAt' => gmdate('Y-m-d'),
	'cycles' => array_map(static fn (array $c): string => $c['cycle'], PRODUCTS),
	'rolling' => ROLLING,
	'releases' => $releases,
];

$target = __DIR__ . '/../resources/os-support.json';
file_put_contents($target, json_encode($table, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'Wrote ' . count($releases) . " releases to resources/os-support.json\n";
