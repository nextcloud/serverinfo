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
	/** Consecutive failed requests; 0 means the values on screen are current. */
	const failures = ref(0)
	const lastUpdated = ref<Date | null>(null)

	const MAX_BACKOFF_MS = 60_000

	let timeoutId: ReturnType<typeof setTimeout> | null = null
	let stopped = false
	let inFlight = false

	async function poll() {
		timeoutId = null
		inFlight = true
		try {
			const response = await axios.get(generateUrl(url))
			data.value = response.data as T
			tick.value++
			failures.value = 0
			lastUpdated.value = new Date()
		} catch {
			// The previous values stay on screen deliberately, but silently: it is
			// `failures` that lets the page admit they have stopped being current.
			failures.value++
		} finally {
			inFlight = false
			schedule()
		}
	}

	/**
	 * Queues the next poll, unless the component is gone or nobody is looking.
	 */
	function schedule() {
		if (stopped || document.hidden || timeoutId !== null) {
			return
		}

		timeoutId = setTimeout(poll, delay())
	}

	/**
	 * Back off while the server is unreachable. Polling a server that is down
	 * every two seconds gains nothing and costs it a full request cycle each
	 * time; the interval returns to normal on the first success.
	 */
	function delay(): number {
		if (failures.value === 0) {
			return intervalMs
		}

		return Math.min(intervalMs * 2 ** failures.value, MAX_BACKOFF_MS)
	}

	/**
	 * A backgrounded tab used to keep polling for nobody, and every request costs
	 * a full Nextcloud request cycle for numbers no one can see. Polling resumes
	 * immediately rather than after the interval, so the page is current the
	 * moment it is looked at again.
	 */
	function onVisibilityChange() {
		if (document.hidden) {
			if (timeoutId !== null) {
				clearTimeout(timeoutId)
				timeoutId = null
			}
		} else if (!inFlight) {
			poll()
		}
	}

	document.addEventListener('visibilitychange', onVisibilityChange)

	timeoutId = setTimeout(poll, 0)

	onUnmounted(() => {
		stopped = true
		document.removeEventListener('visibilitychange', onVisibilityChange)
		if (timeoutId !== null) {
			clearTimeout(timeoutId)
			timeoutId = null
		}
	})

	return { data, tick, failures, lastUpdated }
}
