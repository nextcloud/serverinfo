/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Ref } from 'vue'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { onUnmounted, ref } from 'vue'

/**
 * Polls an endpoint until the component goes away. A failed request keeps the
 * previous values rather than blanking the charts.
 *
 * @param url app-relative endpoint to poll
 * @param intervalMs delay between two requests
 */
export function useLiveData<T>(url: string, intervalMs = 2000) {
	const data = ref<T | null>(null) as Ref<T | null>
	const tick = ref(0)

	let timeoutId: ReturnType<typeof setTimeout> | null = null
	let stopped = false

	async function poll() {
		try {
			const response = await axios.get(generateUrl(url))
			data.value = response.data as T
			tick.value++
		} catch {
			// Keep previous values on error
		} finally {
			if (!stopped) {
				timeoutId = setTimeout(poll, intervalMs)
			}
		}
	}

	timeoutId = setTimeout(poll, 0)

	onUnmounted(() => {
		stopped = true
		if (timeoutId !== null) {
			clearTimeout(timeoutId)
			timeoutId = null
		}
	})

	return { data, tick }
}
