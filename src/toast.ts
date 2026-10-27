/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The toasts of `@nextcloud/dialogs`. The package does not tree-shake and
 * would add its file picker to the page, so callers import this module
 * dynamically, when there is something to say.
 */
export { showError, showSuccess } from '@nextcloud/dialogs'

import '@nextcloud/dialogs/style.css'
