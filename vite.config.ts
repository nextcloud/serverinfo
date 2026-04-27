/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { resolve } from 'node:path'

export default createAppConfig({
	'settings-admin': resolve(import.meta.dirname, 'src', 'settings-admin.ts'),
}, {
	extractLicenseInformation: {
		includeSourceMaps: true,
	},
})
