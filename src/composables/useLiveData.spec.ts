/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Ref } from 'vue'

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, nextTick, ref } from 'vue'
import { LIVE_INTERVAL_MS, useLiveData } from './useLiveData.ts'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

let hidden = false

/**
 * Mounts a component that polls /update, shown or not as `active` says.
 *
 * @param active whether the values are on screen
 */
function start(active: Ref<boolean> = ref(true)) {
	let state!: ReturnType<typeof useLiveData<{ value: number }>>
	const wrapper = mount(defineComponent({
		setup() {
			state = useLiveData<{ value: number }>('/apps/serverinfo/update', LIVE_INTERVAL_MS, active)
			return () => null
		},
	}))
	return { wrapper, state, active }
}

function setHidden(value: boolean) {
	hidden = value
	document.dispatchEvent(new Event('visibilitychange'))
}

describe('useLiveData', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.mocked(axios.get).mockReset()
		vi.mocked(axios.get).mockResolvedValue({ data: { value: 1 } })
		hidden = false
		Object.defineProperty(document, 'hidden', { configurable: true, get: () => hidden })
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('polls right away and then every five seconds', async () => {
		const { state, wrapper } = start()

		await vi.advanceTimersByTimeAsync(0)
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(state.tick.value).toBe(1)

		await vi.advanceTimersByTimeAsync(LIVE_INTERVAL_MS - 1)
		expect(axios.get).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(1)
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(LIVE_INTERVAL_MS).toBe(5000)

		wrapper.unmount()
	})

	it('does not poll while the values are not shown, and polls at once when they are', async () => {
		const { active, wrapper } = start(ref(false))

		await vi.advanceTimersByTimeAsync(60_000)
		expect(axios.get).not.toHaveBeenCalled()

		active.value = true
		await nextTick()
		expect(axios.get).toHaveBeenCalledTimes(1)

		wrapper.unmount()
	})

	it('pauses when another page is shown and resumes with a fresh request', async () => {
		const { active, wrapper } = start()
		await vi.advanceTimersByTimeAsync(0)
		expect(axios.get).toHaveBeenCalledTimes(1)

		active.value = false
		await nextTick()
		await vi.advanceTimersByTimeAsync(60_000)
		expect(axios.get).toHaveBeenCalledTimes(1)

		active.value = true
		await nextTick()
		expect(axios.get).toHaveBeenCalledTimes(2)

		await vi.advanceTimersByTimeAsync(LIVE_INTERVAL_MS)
		expect(axios.get).toHaveBeenCalledTimes(3)

		wrapper.unmount()
	})

	it('does not start a second request while one is under way', async () => {
		let answer!: () => void
		vi.mocked(axios.get).mockReturnValueOnce(new Promise((resolve) => {
			answer = () => resolve({ data: { value: 2 } })
		}))
		const { active, wrapper } = start()
		await vi.advanceTimersByTimeAsync(0)

		active.value = false
		await nextTick()
		active.value = true
		await nextTick()
		expect(axios.get).toHaveBeenCalledTimes(1)

		answer()
		await vi.advanceTimersByTimeAsync(LIVE_INTERVAL_MS)
		expect(axios.get).toHaveBeenCalledTimes(2)

		wrapper.unmount()
	})

	it('pauses while the browser tab is hidden', async () => {
		const { wrapper } = start()
		await vi.advanceTimersByTimeAsync(0)

		setHidden(true)
		await vi.advanceTimersByTimeAsync(60_000)
		expect(axios.get).toHaveBeenCalledTimes(1)

		setHidden(false)
		expect(axios.get).toHaveBeenCalledTimes(2)

		wrapper.unmount()
	})

	it('does not resume on a visible tab while the values are not shown', async () => {
		const { wrapper } = start(ref(false))

		setHidden(true)
		setHidden(false)
		await vi.advanceTimersByTimeAsync(60_000)
		expect(axios.get).not.toHaveBeenCalled()

		wrapper.unmount()
	})

	it('backs off while requests fail and keeps the previous values', async () => {
		const { state, wrapper } = start()
		await vi.advanceTimersByTimeAsync(0)
		vi.mocked(axios.get).mockRejectedValue(new Error('down'))

		await vi.advanceTimersByTimeAsync(LIVE_INTERVAL_MS)
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(state.failures.value).toBe(1)
		expect(state.data.value).toEqual({ value: 1 })

		await vi.advanceTimersByTimeAsync(2 * LIVE_INTERVAL_MS - 1)
		expect(axios.get).toHaveBeenCalledTimes(2)
		await vi.advanceTimersByTimeAsync(1)
		expect(axios.get).toHaveBeenCalledTimes(3)

		wrapper.unmount()
	})

	it('stops when the component goes away', async () => {
		const { wrapper } = start()
		await vi.advanceTimersByTimeAsync(0)

		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(60_000)
		expect(axios.get).toHaveBeenCalledTimes(1)
	})
})
