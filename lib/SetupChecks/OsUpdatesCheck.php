<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\SetupChecks;

use OCA\ServerInfo\OsStatus\Verdict\Concern;

/**
 * Pending operating system updates, security fixes first.
 */
class OsUpdatesCheck extends AbstractOsCheck {
	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Operating system updates');
	}

	#[\Override]
	protected function concernIds(): array {
		return [Concern::UPDATES, Concern::PACKAGE_LISTS];
	}
}
