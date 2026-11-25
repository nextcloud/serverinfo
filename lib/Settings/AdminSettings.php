<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Read-only System monitoring page — exposable via Admin delegation
 * (same pattern as logreader).
 */
class AdminSettings implements IDelegatedSettings {
	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript('serverinfo', 'serverinfo-main');
		Util::addStyle('serverinfo', 'serverinfo-main');

		return new TemplateResponse('serverinfo', 'settings-admin', []);
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	#[\Override]
	public function getSection(): string {
		return 'serverinfo';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 *             the admin section. The forms are arranged in ascending order of the
	 *             priority values. It is required to return a value between 0 and 100.
	 *
	 * keep the server setting at the top, right after "server settings"
	 */
	#[\Override]
	public function getPriority(): int {
		return 0;
	}

	#[\Override]
	public function getName(): ?string {
		return null; // Only setting in this section
	}

	#[\Override]
	public function getAuthorizedAppConfig(): array {
		// Page is read-only; no appconfig writes to authorize.
		return [];
	}
}
