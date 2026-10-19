/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OsPayload } from '../types.ts'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { ref } from 'vue'

/**
 * The operating system page's data. Loading only reads what the background
 * job stored; refreshing makes the server run the package manager, so it is
 * an explicit action.
 */
export function useOsStatus() {
	const data = ref<OsPayload | null>(null)
	const loading = ref(false)
	const refreshing = ref(false)
	const error = ref(false)
	/** HTTP status of a failed refresh, e.g. 429 when it was rate limited */
	const refreshError = ref<number | null>(null)

	async function load() {
		loading.value = true
		error.value = false
		try {
			const response = await axios.get<OsPayload>(generateUrl('/apps/serverinfo/os'))
			data.value = response.data
		} catch {
			error.value = true
		} finally {
			loading.value = false
		}
	}

	async function refresh() {
		refreshing.value = true
		refreshError.value = null
		try {
			const response = await axios.post<OsPayload>(generateUrl('/apps/serverinfo/os/refresh'))
			data.value = response.data
		} catch (caught) {
			refreshError.value = axios.isAxiosError(caught) ? (caught.response?.status ?? 0) : 0
		} finally {
			refreshing.value = false
		}
	}

	return { data, loading, refreshing, error, refreshError, load, refresh }
}
