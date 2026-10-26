/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CpuChartSection from './CpuChartSection.vue'
import MemoryChartSection from './MemoryChartSection.vue'
import { clearHistoryCache } from '../composables/useHistory.ts'

vi.mock('vue-chartjs', async () => ({ Line: (await import('../testing/chartStub.ts')).LineStub }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

const history = {
	range: '7d',
	resolution: 3600,
	from: 0,
	now: 7200,
	node: 'web1',
	series: { t: [0, 3600, 7200], load5: [1, 2, 3], load1Max: [2, 4, 8], memUsed: [1024, 2048, 3072], memUsedMax: [2048, 4096, 4096], swapUsed: [0, 512, 0] },
}

/**
 * @param wrapper the mounted section
 * @param label the range to choose
 */
async function choose(wrapper: ReturnType<typeof mount>, label: string) {
	await wrapper.findAll('button').find((button) => button.text() === label)!.trigger('click')
	await flushPromises()
}

describe('chart ranges', () => {
	beforeEach(() => {
		vi.mocked(axios.get).mockReset()
		vi.mocked(axios.get).mockResolvedValue({ data: history })
		clearHistoryCache()
	})

	it('shows the live load until another range is chosen', async () => {
		const wrapper = mount(CpuChartSection, { props: { cpuload: [1, 1, 1], cpunum: 4, tick: 0 } })
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.find('[aria-pressed="true"]').text()).toBe('Live')
		expect(wrapper.find('canvas').attributes('aria-label')).toBeUndefined()

		await choose(wrapper, '7 days')

		const canvas = wrapper.find('canvas')
		expect(canvas.attributes('aria-label')).toBe('CPU load over time')
		expect(JSON.parse(canvas.attributes('data-labels')!)).toEqual(['Average load', 'Peak load'])
		expect(JSON.parse(canvas.attributes('data-points')!)).toEqual([[25, 50, 75], [50, 100, 200]])
		expect(wrapper.text()).toContain('Average load 50 %, highest 200 %')

		await choose(wrapper, 'Live')
		expect(wrapper.find('canvas').attributes('aria-label')).toBeUndefined()
	})

	it('shows the memory history in GB', async () => {
		const wrapper = mount(MemoryChartSection, { props: { memTotal: 8192, memFree: 4096, swapTotal: 1024, swapFree: 1024, tick: 0 } })

		await choose(wrapper, '7 days')

		const canvas = wrapper.find('canvas')
		expect(canvas.attributes('aria-label')).toBe('Memory usage over time')
		expect(JSON.parse(canvas.attributes('data-labels')!)).toEqual(['RAM usage', 'Peak RAM usage', 'Swap usage'])
		expect(JSON.parse(canvas.attributes('data-points')!)[0]).toEqual([1, 2, 3])
		expect(wrapper.text()).toContain('RAM used: average 2.00 GB, highest 4.00 GB')
	})
})
