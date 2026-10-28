<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo;

use OCP\App\IAppManager;
use OCP\IAppConfig;

class SubscriptionStatistics {
	public function __construct(
		private IAppManager $appManager,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Number of users covered by the subscription registered in the support app,
	 * or null when the support app is disabled or the subscription has no user limit.
	 */
	public function getSubscriptionUsers(): ?int {
		if (!$this->appManager->isEnabledForAnyone('support')) {
			return null;
		}

		// Last subscription info fetched by the support app, no public API exposes it
		$subscriptionInfo = $this->appConfig->getValueArray('support', 'last_response', lazy: true);
		$amountOfUsers = (int)($subscriptionInfo['amountOfUsers'] ?? 0);

		return $amountOfUsers > 0 ? $amountOfUsers : null;
	}
}
