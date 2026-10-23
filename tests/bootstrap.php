<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCP\App\IAppManager;
use OCP\Server;

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

require_once __DIR__ . '/../../../lib/base.php';
require_once __DIR__ . '/../../../tests/autoload.php';

Server::get(IAppManager::class)->loadApp('serverinfo');

// Shared test doubles, not test cases, so PHPUnit does not load them on its own.
require_once __DIR__ . '/lib/OsStatus/FakeHost.php';
require_once __DIR__ . '/lib/OsStatus/FakeL10N.php';
