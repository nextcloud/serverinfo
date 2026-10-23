<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

/**
 * Only root can answer on this system, so the answer is left to the root
 * helper's report.
 */
class NeedsRootException extends ProbeException {
}
