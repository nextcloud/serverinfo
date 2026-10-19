<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\SetupChecks;

use OCA\ServerInfo\OsStatus\Verdict\Concern;

/**
 * Whether the installed release still receives security updates.
 */
class OsSupportCheck extends AbstractOsCheck {
	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Operating system support');
	}

	#[\Override]
	protected function concernIds(): array {
		return [Concern::SUPPORT];
	}
}
