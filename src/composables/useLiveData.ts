/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { MaybeRefOrGetter, Ref } from 'vue'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { onUnmounted, ref, toValue, watch } from 'vue'

/**
 * Delay between two requests for the Status page's live values. The CPU chart
 * plots the 1-minute load average, which the kernel recomputes only every five
 * seconds, so asking more often would mostly repeat the previous value.
 */
export const LIVE_INTERVAL_MS = 5000

/**
 * Polls an endpoint until the component goes away. A failed request keeps the
 * previous values rather than blanking the charts.
 *
 * @param url app-relative endpoint to poll
 * @param intervalMs delay between two requests
 * @param active whether anyone is looking at the values; polling pauses while
 *   it is false and resumes with a fresh request as soon as it is true again
 */
export function useLiveData<T>(url: string, intervalMs = LIVE_INTERVAL_MS, active: MaybeRefOrGetter<boolean> = true) {
	const data = ref<T | null>(null) as Ref<T | null>
	const tick = ref(0)
	/** Consecutive failed requests; 0 means the values on screen are current. */
	const failures = ref(0)
	const lastUpdated = ref<Date | null>(null)

	const MAX_BACKOFF_MS = 60_000

	let timeoutId: ReturnType<typeof setTimeout> | null = null
	let stopped = false
	let inFlight = false

	/**
	 * Whether a request is worth making: the component is still there, the
	 * browser tab is in front and the values are on screen.
	 */
	function wanted(): boolean {
		return !stopped && !document.hidden && toValue(active)
	}

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
	 * Queues the next poll, unless nobody is looking.
	 */
	function schedule() {
		if (!wanted() || timeoutId !== null) {
			return
		}

		timeoutId = setTimeout(poll, delay())
	}

	/**
	 * Back off while the server is unreachable. Polling a server that is down
	 * every few seconds gains nothing and costs it a full request cycle each
	 * time; the interval returns to normal on the first success.
	 */
	function delay(): number {
		if (failures.value === 0) {
			return intervalMs
		}

		return Math.min(intervalMs * 2 ** failures.value, MAX_BACKOFF_MS)
	}

	function pause() {
		if (timeoutId !== null) {
			clearTimeout(timeoutId)
			timeoutId = null
		}
	}

	/**
	 * Polls right away rather than after the interval, so the values are
	 * current the moment they are looked at again. A request still under way
	 * schedules the next one itself when it returns.
	 */
	function resume() {
		if (!wanted() || inFlight) {
			return
		}

		pause()
		poll()
	}

	/**
	 * Every request costs a full Nextcloud request cycle, so a backgrounded
	 * browser tab or a hidden page does not poll for numbers no one can see.
	 */
	function onVisibilityChange() {
		if (document.hidden) {
			pause()
		} else {
			resume()
		}
	}

	document.addEventListener('visibilitychange', onVisibilityChange)

	watch(() => toValue(active), (isActive) => {
		if (isActive) {
			resume()
		} else {
			pause()
		}
	})

	if (wanted()) {
		timeoutId = setTimeout(poll, 0)
	}

	onUnmounted(() => {
		stopped = true
		document.removeEventListener('visibilitychange', onVisibilityChange)
		pause()
	})

	return { data, tick, failures, lastUpdated }
}
