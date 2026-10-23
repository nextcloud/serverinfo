<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

/**
 * A package manager that can answer only in part, e.g. list pending updates
 * without telling the security fixes apart, and says what it left out.
 */
interface IReportsDiagnostics {
	/**
	 * What the last collection could not find out, as English sentences.
	 *
	 * @return list<string>
	 */
	public function getDiagnostics(): array;
}
