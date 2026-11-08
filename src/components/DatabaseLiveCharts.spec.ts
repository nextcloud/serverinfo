/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import DatabaseLiveCharts from './DatabaseLiveCharts.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

// Chart.js needs a canvas, which the test DOM does not have.
vi.mock('vue-chartjs', () => ({
	Line: { render: () => null },
}))

const SAMPLE = {
	available: true,
	latencyMs: 0.4,
	connections: { used: 3, max: 100 },
	throughput: { counter: 1000, label: 'Queries/s' },
	threadsRunning: 1,
}

/**
 * @param active whether the Database page is the one shown
 */
function start(active: boolean) {
	return mount(DatabaseLiveCharts, {
		props: { active },
	})
}

describe('DatabaseLiveCharts', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.mocked(axios.get).mockReset()
		vi.mocked(axios.get).mockResolvedValue({ data: SAMPLE })
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('does not poll while another page is shown', async () => {
		const wrapper = start(false)

		await vi.advanceTimersByTimeAsync(60_000)
		expect(axios.get).not.toHaveBeenCalled()

		wrapper.unmount()
	})

	it('polls at once when the page is shown and stops when it is left', async () => {
		const wrapper = start(false)

		await wrapper.setProps({ active: true })
		expect(axios.get).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(3000)
		expect(axios.get).toHaveBeenCalledTimes(2)

		await wrapper.setProps({ active: false })
		await vi.advanceTimersByTimeAsync(60_000)
		expect(axios.get).toHaveBeenCalledTimes(2)

		wrapper.unmount()
	})

	it('does not start a second request while one is under way', async () => {
		let answer!: () => void
		vi.mocked(axios.get).mockReturnValueOnce(new Promise((resolve) => {
			answer = () => resolve({ data: SAMPLE })
		}))
		const wrapper = start(true)
		expect(axios.get).toHaveBeenCalledTimes(1)

		await wrapper.setProps({ active: false })
		await wrapper.setProps({ active: true })
		expect(axios.get).toHaveBeenCalledTimes(1)

		answer()
		await nextTick()
		await vi.advanceTimersByTimeAsync(3000)
		expect(axios.get).toHaveBeenCalledTimes(2)

		wrapper.unmount()
	})
})
