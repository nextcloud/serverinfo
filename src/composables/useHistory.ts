/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Ref } from 'vue'
import type { HistoryPayload, HistoryRange } from '../types.ts'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { ref, watch } from 'vue'

/** New samples arrive every five minutes, so a minute-old answer is still current. */
const MAX_AGE_MS = 60_000

const cache = new Map<HistoryRange, { at: number, request: Promise<HistoryPayload> }>()

/**
 * Loads a range once for every chart that shows it.
 *
 * @param range the range to load
 */
export function fetchHistory(range: HistoryRange): Promise<HistoryPayload> {
	const cached = cache.get(range)
	if (cached && Date.now() - cached.at < MAX_AGE_MS) {
		return cached.request
	}

	const request = axios.get<HistoryPayload>(generateUrl('/apps/serverinfo/history'), { params: { range } })
		.then((response) => response.data)
	request.catch(() => cache.delete(range))
	cache.set(range, { at: Date.now(), request })
	return request
}

/**
 * Forgets loaded ranges, for tests.
 */
export function clearHistoryCache(): void {
	cache.clear()
}

/**
 * The stored history for the selected range. Nothing is loaded while the
 * live values are shown.
 *
 * @param range 'live' or the range to show
 */
export function useHistory(range: Ref<'live' | HistoryRange>) {
	const data = ref<HistoryPayload | null>(null) as Ref<HistoryPayload | null>
	const loading = ref(false)
	const failed = ref(false)
	let latest = 0

	watch(range, async (selected) => {
		if (selected === 'live') {
			return
		}

		const request = ++latest
		if (data.value?.range !== selected) {
			data.value = null
		}
		loading.value = true
		failed.value = false
		try {
			const payload = await fetchHistory(selected)
			if (request === latest) {
				data.value = payload
			}
		} catch {
			if (request === latest) {
				failed.value = true
			}
		} finally {
			if (request === latest) {
				loading.value = false
			}
		}
	}, { immediate: true })

	return { data, loading, failed }
}
