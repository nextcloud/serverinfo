<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\AppInfo;

use OCA\ServerInfo\Config\ConfigLexicon;
use OCA\ServerInfo\Dashboard\ServerHealthWidget;
use OCA\ServerInfo\OsStatus\Host\IHost;
use OCA\ServerInfo\OsStatus\Host\LocalHost;
use OCA\ServerInfo\OsStatus\Notifications\OsNotifier;
use OCA\ServerInfo\SetupChecks\DatabaseChecks;
use OCA\ServerInfo\SetupChecks\OsRebootCheck;
use OCA\ServerInfo\SetupChecks\OsSupportCheck;
use OCA\ServerInfo\SetupChecks\OsUpdatesCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * @psalm-api
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'serverinfo';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerConfigLexicon(ConfigLexicon::class);
		$context->registerSetupCheck(DatabaseChecks::class);
		$context->registerSetupCheck(OsUpdatesCheck::class);
		$context->registerSetupCheck(OsRebootCheck::class);
		$context->registerSetupCheck(OsSupportCheck::class);
		$context->registerServiceAlias(IHost::class, LocalHost::class);
		$context->registerNotifierService(OsNotifier::class);
		$context->registerDashboardWidget(ServerHealthWidget::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
