<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Support;

use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * Whether a paid extended support programme is active, where that can be
 * read without privilege.
 *
 * @psalm-api
 */
class SubscriptionProbe {
	/** The Pro client keeps its last status here, readable by everyone. */
	private const UBUNTU_PRO_STATUS = '/var/lib/ubuntu-advantage/status.json';

	public function __construct(
		private IHost $host,
	) {
	}

	/**
	 * @param string|null $programme one of SupportStatus::PROGRAMMES
	 * @return bool|null null when it cannot be told
	 */
	public function isActive(?string $programme): ?bool {
		return match ($programme) {
			'ubuntu-pro' => $this->ubuntuPro(),
			'debian-elts' => $this->debianElts(),
			default => null,
		};
	}

	/**
	 * Ubuntu's extended security maintenance for the main archive is the
	 * "esm-infra" service of an attached Pro subscription.
	 */
	private function ubuntuPro(): ?bool {
		$json = $this->host->readFile(self::UBUNTU_PRO_STATUS, 1024 * 1024);
		if ($json === null) {
			return $this->host->fileExists('/usr/bin/pro') ? null : false;
		}

		return self::parseUbuntuProStatus($json);
	}

	public static function parseUbuntuProStatus(string $json): ?bool {
		$status = json_decode($json, true, 32);
		if (!is_array($status) || !is_bool($status['attached'] ?? null)) {
			return null;
		}
		if (!$status['attached']) {
			return false;
		}

		$services = $status['services'] ?? null;
		foreach (is_array($services) ? $services : [] as $service) {
			if (is_array($service) && ($service['name'] ?? null) === 'esm-infra') {
				return ($service['status'] ?? null) === 'enabled';
			}
		}

		return false;
	}

	/**
	 * Freexian's Extended LTS is configured as an extra apt source.
	 */
	private function debianElts(): ?bool {
		$files = array_merge(
			['/etc/apt/sources.list'],
			$this->host->glob('/etc/apt/sources.list.d/*.list'),
			$this->host->glob('/etc/apt/sources.list.d/*.sources'),
		);

		$readable = false;
		foreach ($files as $file) {
			$content = $this->host->readFile($file, 256 * 1024);
			if ($content === null) {
				continue;
			}
			$readable = true;

			foreach (preg_split('/\R/', $content) ?: [] as $line) {
				$line = trim($line);
				if ($line !== '' && $line[0] !== '#' && str_contains($line, 'deb.freexian.com/extended-lts')) {
					return true;
				}
			}
		}

		return $readable ? false : null;
	}
}
