<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus;

/**
 * Recognises the packages and services Nextcloud itself depends on, so the
 * page can put them first.
 */
final class NextcloudStack {
	private const PACKAGE_PATTERNS = [
		// PHP and its modules, under every distribution's naming
		'/^(php|php\d+(\.\d+)?)(-.+)?$/',
		'/^libapache2-mod-php/',
		// Web servers
		'/^(apache2|httpd|nginx|lighttpd|caddy|frankenphp)(-.+)?$/',
		// Databases
		'/^(mariadb|mysql|percona-server|postgresql\d*)(-.+)?$/',
		'/^(libmariadb\d*|libmysqlclient\d*|libpq\d*)$/',
		// Caches
		'/^(redis|valkey|memcached)(-.+)?$/',
		'/^php\d*-?(apcu|redis|memcached)$/',
		// Previews, Office and antivirus
		'/^(imagemagick|libmagickcore.*|libmagickwand.*|ffmpeg|libreoffice.*|coolwsd|collaboraoffice.*|clamav.*)$/i',
		// TLS and HTTP plumbing every request goes through
		'/^(openssl(-\d+)?|openssl-libs|libopenssl\d*|libssl\d.*|libcurl\d*.*|curl|ca-certificates|libxml2|libzip\d*|gnutls|libgnutls.*)$/',
		// External storage, mail, Talk, push
		'/^(smbclient|libsmbclient|samba-client.*|exim4.*|postfix|coturn|notify_push|nextcloud.*)$/',
	];

	private const SERVICE_PATTERNS = [
		'/^php[\d.]*-fpm[\d.]*\.service$/',
		'/^php-fpm[\d.]*\.service$/',
		'/^(apache2|httpd|nginx|lighttpd|caddy|frankenphp)(@.*)?\.service$/',
		'/^(mariadb|mysql|mysqld|postgresql)(@.*)?\.service$/',
		'/^(redis|redis-server|valkey|valkey-server|memcached)(@.*)?\.service$/',
		'/^(coolwsd|notify_push|nextcloud.*|imaginary|coturn|clamav-daemon|clamd@.*|elasticsearch|opensearch)\.service$/',
		'/^(cron|crond|cronie)\.service$/',
		'/^nextcloud.*\.timer$/',
	];

	public static function isStackPackage(string $name): bool {
		$name = strtolower($name);
		foreach (self::PACKAGE_PATTERNS as $pattern) {
			if (preg_match($pattern, $name) === 1) {
				return true;
			}
		}

		return false;
	}

	public static function isStackService(string $unit): bool {
		if (!str_contains($unit, '.')) {
			$unit .= '.service';
		}

		foreach (self::SERVICE_PATTERNS as $pattern) {
			if (preg_match($pattern, $unit) === 1) {
				return true;
			}
		}

		return false;
	}
}
