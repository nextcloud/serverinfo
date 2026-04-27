/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'
import { createApp } from 'vue'
import AdminSettings from './views/AdminSettings.vue'
import type { ServerInfoState } from './types.ts'

import 'vite/modulepreload-polyfill'

const state = loadState<ServerInfoState>('serverinfo', 'serverinfo')

const app = createApp(AdminSettings, { state })
app.config.idPrefix = 'serverinfo'
app.mount('#serverinfo-admin-settings')
