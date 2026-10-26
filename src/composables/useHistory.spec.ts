/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { HistoryPayload, HistoryRange } from '../types.ts'

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'
import { clearHistoryCache, useHistory } from './useHistory.ts'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

function response(range: HistoryRange): { data: HistoryPayload } {
	return {
		data: { range, resolution: 3600, from: 0, now: 7200, node: 'web1', series: { t: [3600], load1: [0.5] } },
	}
}

/**
 * Lets the pending request settle.
 */
async function settle() {
	await Promise.resolve()
	await nextTick()
	await Promise.resolve()
}

describe('useHistory', () => {
	beforeEach(() => {
		vi.mocked(axios.get).mockReset()
		clearHistoryCache()
	})

	it('loads nothing while the live values are shown', async () => {
		const range = ref<'live' | HistoryRange>('live')
		const { data } = effectScope().run(() => useHistory(range))!
		await settle()

		expect(axios.get).not.toHaveBeenCalled()
		expect(data.value).toBeNull()
	})

	it('loads the chosen range once for every chart showing it', async () => {
		vi.mocked(axios.get).mockResolvedValue(response('7d'))
		const range = ref<'live' | HistoryRange>('live')
		const cpu = effectScope().run(() => useHistory(range))!
		const memory = effectScope().run(() => useHistory(ref<'live' | HistoryRange>('7d')))!

		range.value = '7d'
		await settle()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(vi.mocked(axios.get).mock.calls[0][1]).toEqual({ params: { range: '7d' } })
		expect(cpu.data.value?.range).toBe('7d')
		expect(memory.data.value?.range).toBe('7d')
		expect(cpu.loading.value).toBe(false)
	})

	it('reports a failure and asks again next time', async () => {
		vi.mocked(axios.get).mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(response('30d'))
		const range = ref<'live' | HistoryRange>('30d')
		const { data, failed } = effectScope().run(() => useHistory(range))!
		await settle()

		expect(failed.value).toBe(true)
		expect(data.value).toBeNull()

		range.value = 'live'
		await settle()
		range.value = '30d'
		await settle()

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(failed.value).toBe(false)
		expect(data.value?.range).toBe('30d')
	})
})
