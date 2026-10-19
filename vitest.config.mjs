/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	plugins: [vue()],
	test: {
		environment: 'happy-dom',
		include: ['src/**/*.spec.ts'],
		server: {
			deps: {
				// Ships ESM with CSS imports, which Node cannot load untransformed.
				inline: [/@nextcloud\/vue/],
			},
		},
	},
})
