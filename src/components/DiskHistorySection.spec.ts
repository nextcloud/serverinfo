/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DiskHistorySection from './DiskHistorySection.vue'
import { clearHistoryCache } from '../composables/useHistory.ts'

vi.mock('vue-chartjs', async () => ({ Line: (await import('../testing/chartStub.ts')).LineStub }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

const GB = 1024 ** 3

describe('DiskHistorySection', () => {
	beforeEach(() => {
		vi.mocked(axios.get).mockReset()
		clearHistoryCache()
	})

	it('charts the used space and says how much it grew', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: { range: '7d', resolution: 3600, from: 0, now: 7200, node: 'web1', series: { t: [0, 3600, 7200], diskFree: [60 * GB, null, 58 * GB], diskTotal: [100 * GB, null, 100 * GB] } },
		})

		const wrapper = mount(DiskHistorySection)
		await flushPromises()

		expect(vi.mocked(axios.get).mock.calls[0][1]).toEqual({ params: { range: '7d' } })
		const canvas = wrapper.find('canvas')
		expect(canvas.attributes('aria-label')).toBe('Disk usage of the data directory over time')
		expect(JSON.parse(canvas.attributes('data-points')!)).toEqual([[40 * GB, null, 42 * GB], [100 * GB, null, 100 * GB]])
		expect(wrapper.text()).toContain('42.0 GB of 100.0 GB used, 2.0 GB more than at the start of the range')
	})

	it('says when nothing was collected yet', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: { range: '7d', resolution: 3600, from: 0, now: 7200, node: null, series: { t: [], diskFree: [], diskTotal: [] } },
		})

		const wrapper = mount(DiskHistorySection)
		await flushPromises()

		expect(wrapper.text()).toContain('History starts collecting now; the first points appear after about 5 minutes.')
	})
})
