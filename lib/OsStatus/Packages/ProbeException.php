<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ServerInfo\OsStatus\Packages;

/**
 * A probe ran but could not produce an answer. The message is shown to the
 * admin as a diagnostic, so it says what failed in plain words.
 */
class ProbeException extends \RuntimeException {
}
