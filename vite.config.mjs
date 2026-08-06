/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'node:path'
import { defineConfig } from 'vite'

export default createAppConfig({
	main: resolve(join('src', 'main.ts')),
}, {
	// create REUSE compliant license information for compiled assets
	extractLicenseInformation: {
		includeSourceMaps: true,
	},
	// disable BOM because we already have the `.license` files
	thirdPartyLicense: false,
	emptyOutputDirectory: {
		additionalDirectories: ['css'],
	},
	config: defineConfig(({ mode }) => ({
		define: {
			'process.env.NODE_ENV': JSON.stringify(mode),
		},
	})),
})
