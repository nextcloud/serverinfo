/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OsPayload } from '../types.ts'

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { NOW, payload, status } from '../testing/osPayload.ts'
import { POLL_LIMIT, REQUEST_TIMEOUT, useOsStatus } from './useOsStatus.ts'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), isAxiosError: (e: { isAxiosError?: boolean }) => e?.isAxiosError === true },
}))

const OLD = payload()
const checked = (running = false): OsPayload => ({ ...payload({ status: status({ collectedAt: NOW + 60 }) }), refresh: { systemCron: true, running } })
const waiting = (running = true): OsPayload => ({ ...OLD, refresh: { systemCron: true, running } })
const rejection = (status: number, data: unknown = {}) => ({ isAxiosError: true, response: { status, data } })

/**
 * Runs the composable with the old status loaded.
 */
async function start() {
	vi.mocked(axios.get).mockResolvedValueOnce({ data: OLD })
	const scope = effectScope()
	const state = scope.run(() => useOsStatus())!
	await state.load()
	return { scope, state }
}

describe('useOsStatus', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.mocked(axios.get).mockReset()
		vi.mocked(axios.post).mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('queues a check and polls until the status was collected again', async () => {
		const { state } = await start()
		vi.mocked(axios.post).mockResolvedValue({ status: 202, data: { reason: 'queued' } })
		vi.mocked(axios.get).mockResolvedValueOnce({ data: waiting() }).mockResolvedValueOnce({ data: checked() })

		const done = state.refresh()
		await vi.advanceTimersByTimeAsync(0)
		expect(state.refreshing.value).toBe(true)
		await vi.runAllTimersAsync()
		await done

		expect(state.refreshing.value).toBe(false)
		expect(state.refreshError.value).toBeNull()
		expect(state.data.value?.status?.collectedAt).toBe(NOW + 60)
		expect(axios.get).toHaveBeenCalledTimes(3)
		expect(vi.mocked(axios.post).mock.calls[0][2]).toEqual({ timeout: REQUEST_TIMEOUT })
		expect(vi.mocked(axios.get).mock.calls[0][1]).toEqual({ timeout: REQUEST_TIMEOUT })
	})

	it('waits for a check that is already running', async () => {
		const { state } = await start()
		vi.mocked(axios.post).mockRejectedValue(rejection(409, { reason: 'running' }))
		vi.mocked(axios.get).mockResolvedValueOnce({ data: checked() })

		const done = state.refresh()
		await vi.runAllTimersAsync()
		await done

		expect(state.refreshError.value).toBeNull()
		expect(state.data.value?.status?.collectedAt).toBe(NOW + 60)
	})

	it.each([
		[rejection(429), 'rate-limited'],
		[rejection(409, { reason: 'cron' }), 'no-cron'],
		[rejection(500), 'failed'],
		[new Error('timeout'), 'failed'],
	])('does not poll when the check was not queued (%#)', async (caught, problem) => {
		const { state } = await start()
		vi.mocked(axios.post).mockRejectedValue(caught)

		await state.refresh()

		expect(state.refreshing.value).toBe(false)
		expect(state.refreshError.value).toBe(problem)
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('reports a failure when the check ended without a new status', async () => {
		const { state } = await start()
		vi.mocked(axios.post).mockResolvedValue({ status: 202, data: {} })
		vi.mocked(axios.get).mockResolvedValue({ data: waiting(false) })

		const done = state.refresh()
		await vi.runAllTimersAsync()
		await done

		expect(state.refreshError.value).toBe('failed')
		expect(axios.get).toHaveBeenCalledTimes(3)
	})

	it('keeps polling through failed requests, but not forever', async () => {
		const { state } = await start()
		vi.mocked(axios.post).mockResolvedValue({ status: 202, data: {} })
		vi.mocked(axios.get).mockRejectedValueOnce(new Error('timeout')).mockResolvedValue({ data: waiting() })

		const done = state.refresh()
		await vi.advanceTimersByTimeAsync(POLL_LIMIT + 60_000)
		await done

		expect(state.refreshing.value).toBe(false)
		expect(state.refreshError.value).toBe('slow')
		const polls = vi.mocked(axios.get).mock.calls.length - 1
		expect(polls).toBeGreaterThan(20)
		expect(polls).toBeLessThan(60)
	})

	it('follows a check another admin started', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({ data: waiting() }).mockResolvedValueOnce({ data: checked() })
		const scope = effectScope()
		const state = scope.run(() => useOsStatus())!

		await state.load()
		expect(state.refreshing.value).toBe(true)
		await vi.runAllTimersAsync()

		expect(state.refreshing.value).toBe(false)
		expect(state.data.value?.status?.collectedAt).toBe(NOW + 60)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('stops polling when the page goes away', async () => {
		const { scope, state } = await start()
		vi.mocked(axios.post).mockResolvedValue({ status: 202, data: {} })
		vi.mocked(axios.get).mockResolvedValue({ data: waiting() })

		const done = state.refresh()
		await vi.advanceTimersByTimeAsync(0)
		scope.stop()
		await vi.advanceTimersByTimeAsync(POLL_LIMIT)
		await done

		expect(state.refreshing.value).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(1)
	})
})
