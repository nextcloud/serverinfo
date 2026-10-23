/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OsPayload } from '../types.ts'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { onScopeDispose, ref } from 'vue'

/** Milliseconds before a request is given up */
export const REQUEST_TIMEOUT = 30_000
/** Milliseconds before the first look at the status after asking for a check */
export const POLL_FIRST_DELAY = 3_000
/** The wait between two looks grows to at most this, in milliseconds */
export const POLL_MAX_DELAY = 30_000
/**
 * Milliseconds after which the page stops waiting for a check: system cron
 * usually runs every five minutes, and the check itself may take a few more.
 */
export const POLL_LIMIT = 15 * 60_000

/** Why "Check now" did not produce a new status */
export type RefreshProblem = 'rate-limited' | 'no-cron' | 'failed' | 'slow'

/**
 * The operating system page's data. Loading only reads what the background
 * jobs stored. A check runs the package manager, so "Check now" only queues
 * it for the next cron run; the status is then polled until it changes.
 */
export function useOsStatus() {
	const data = ref<OsPayload | null>(null)
	const loading = ref(false)
	const refreshing = ref(false)
	const error = ref(false)
	const refreshError = ref<RefreshProblem | null>(null)

	let disposed = false
	let timer: ReturnType<typeof setTimeout> | undefined
	let wake: (() => void) | undefined
	onScopeDispose(() => {
		disposed = true
		clearTimeout(timer)
		wake?.()
	})

	const url = (path = '') => generateUrl('/apps/serverinfo/os' + path)

	async function fetchPayload(): Promise<OsPayload> {
		const response = await axios.get<OsPayload>(url(), { timeout: REQUEST_TIMEOUT })
		return response.data
	}

	async function load() {
		loading.value = true
		error.value = false
		try {
			data.value = await fetchPayload()
		} catch {
			error.value = true
		} finally {
			loading.value = false
		}
		// Another admin or another tab may have asked for a check.
		if (data.value?.refresh.running && !refreshing.value) {
			void poll()
		}
	}

	async function refresh() {
		if (refreshing.value) {
			return
		}
		refreshing.value = true
		refreshError.value = null
		try {
			await axios.post(url('/refresh'), null, { timeout: REQUEST_TIMEOUT })
		} catch (caught) {
			const response = axios.isAxiosError(caught) ? caught.response : undefined
			const reason = response?.data?.reason
			// A check that is already running is as good as a new one.
			if (response?.status !== 409 || reason !== 'running') {
				refreshing.value = false
				refreshError.value = response?.status === 429
					? 'rate-limited'
					: (response?.status === 409 && reason === 'cron' ? 'no-cron' : 'failed')
				return
			}
		}
		await poll()
	}

	/**
	 * Polls the status until it was collected again, the check is gone
	 * without a result, or POLL_LIMIT is reached.
	 */
	async function poll() {
		refreshing.value = true
		const before = data.value?.status?.collectedAt ?? null
		const started = Date.now()
		let delay = POLL_FIRST_DELAY
		let idle = 0
		try {
			while (!disposed) {
				if (Date.now() - started >= POLL_LIMIT) {
					refreshError.value = 'slow'
					return
				}
				await new Promise<void>((resolve) => {
					wake = resolve
					timer = setTimeout(resolve, delay)
				})
				if (disposed) {
					return
				}
				delay = Math.min(POLL_MAX_DELAY, Math.round(delay * 1.5))

				let payload: OsPayload
				try {
					payload = await fetchPayload()
				} catch {
					continue
				}
				data.value = payload
				if ((payload.status?.collectedAt ?? null) !== before) {
					return
				}
				// The queued job leaves the queue just before it takes the
				// collection lock, so a single idle answer can be that moment.
				idle = payload.refresh.running ? 0 : idle + 1
				if (idle >= 2) {
					refreshError.value = 'failed'
					return
				}
			}
		} finally {
			refreshing.value = false
		}
	}

	return { data, loading, refreshing, error, refreshError, load, refresh }
}
