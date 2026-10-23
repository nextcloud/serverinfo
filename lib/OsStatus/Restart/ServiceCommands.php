<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Restart;

use OCA\ServerInfo\OsStatus\Health\SystemdProbe;
use OCA\ServerInfo\OsStatus\Host\IHost;

/**
 * The commands an admin copies to restart a service or reboot, for the init
 * system the machine runs. Only looks for files, so a page request may ask.
 *
 * @psalm-api
 */
class ServiceCommands {
	public const SYSTEMD = 'systemd';
	public const OPENRC = 'openrc';

	public function __construct(
		private SystemdProbe $systemd,
		private IHost $host,
	) {
	}

	/**
	 * @return self::SYSTEMD|self::OPENRC|null null when it cannot be told
	 */
	public function initSystem(): ?string {
		if ($this->systemd->isRunning()) {
			return self::SYSTEMD;
		}

		return $this->host->fileExists('/run/openrc') ? self::OPENRC : null;
	}

	/**
	 * @param string $service a systemd unit or an OpenRC service name
	 */
	public static function restart(?string $initSystem, string $service): ?string {
		$name = preg_replace('/\.service$/', '', $service) ?? $service;

		return match ($initSystem) {
			self::SYSTEMD => 'sudo systemctl restart ' . self::quote($name),
			self::OPENRC => 'sudo rc-service ' . self::quote($name) . ' restart',
			default => null,
		};
	}

	public static function reboot(?string $initSystem): ?string {
		return match ($initSystem) {
			self::SYSTEMD => 'sudo systemctl reboot',
			self::OPENRC => 'sudo reboot',
			default => null,
		};
	}

	/**
	 * Quotes a word for a POSIX shell, so escaped unit names such as
	 * "foo\x2dbar" keep their backslash.
	 */
	public static function quote(string $word): string {
		return preg_match('#^[A-Za-z0-9@%+=:,./_-]+$#', $word) === 1
			? $word
			: "'" . str_replace("'", "'\\''", $word) . "'";
	}
}
