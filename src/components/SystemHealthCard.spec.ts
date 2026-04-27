/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SystemHealthCard from './SystemHealthCard.vue'
import type { DiskInfo, SystemInfo } from '../types.ts'

const baseSystem = (overrides: Partial<SystemInfo> = {}): SystemInfo => ({
	cpuload: [0.4, 0.4, 0.4],
	cpunum: 4,
	mem_total: 16_000_000,
	mem_free: 12_000_000,
	swap_total: 0,
	swap_free: 0,
	freespace: null,
	...overrides,
})

const disk = (overrides: Partial<DiskInfo> = {}): DiskInfo => ({
	device: '/dev/sda1',
	fs: 'ext4',
	mount: '/',
	used: 100_000,
	available: 900_000,
	percent: '10%',
	...overrides,
})

const mountCard = (props: Partial<{
	hostname: string
	system: SystemInfo
	disks: DiskInfo[]
	uptimeSeconds: number
	failed: boolean
}> = {}) => mount(SystemHealthCard, {
	props: {
		hostname: 'host.local',
		system: baseSystem(),
		disks: [],
		uptimeSeconds: 3600,
		failed: false,
		...props,
	},
})

describe('SystemHealthCard', () => {
	it('renders a healthy system as ok with no issue pills', () => {
		const wrapper = mountCard()
		const text = wrapper.text()
		expect(text).toContain('host.local')
		expect(text).toContain('All systems nominal')
		expect(text).not.toContain('Critical')
		expect(text).not.toContain('Warning')
	})

	it('flags a warning for memory above 70%', () => {
		// mem_used / mem_total = 80%
		const wrapper = mountCard({
			system: baseSystem({ mem_free: 3_200_000 }),
		})
		expect(wrapper.text()).toContain('Warning')
		expect(wrapper.text()).toMatch(/Memory at 80%/)
	})

	it('flags a critical issue for memory above 90%', () => {
		const wrapper = mountCard({
			system: baseSystem({ mem_free: 800_000 }),
		})
		expect(wrapper.text()).toContain('Critical')
		expect(wrapper.text()).toMatch(/Memory at 95%/)
	})

	it('flags a critical issue for a disk above 90% used', () => {
		const wrapper = mountCard({
			disks: [
				disk({ used: 950, available: 50, mount: '/data' }),
			],
		})
		expect(wrapper.text()).toContain('Critical')
		expect(wrapper.text()).toMatch(/\/data at 95%/)
	})

	it('flags a critical issue when live data is unavailable', () => {
		const wrapper = mountCard({ failed: true })
		expect(wrapper.text()).toContain('Live data unavailable')
		expect(wrapper.text()).toContain('Critical')
	})

	it('hides the uptime line when uptime is unavailable', () => {
		const wrapper = mountCard({ uptimeSeconds: -1 })
		expect(wrapper.text()).not.toMatch(/^Up /m)
		expect(wrapper.text()).not.toContain('–')
	})

	it('formats day-scale uptime', () => {
		const oneDayTwoHoursThreeMinutes = 86400 + 2 * 3600 + 3 * 60
		const wrapper = mountCard({ uptimeSeconds: oneDayTwoHoursThreeMinutes })
		expect(wrapper.text()).toMatch(/Up 1d 2h 3m/)
	})

	it('escalates overall status to the worst issue level', () => {
		const wrapper = mountCard({
			system: baseSystem({ mem_free: 4_000_000 }), // 75% mem → warning
			disks: [disk({ used: 950, available: 50 })], // 95% → critical
		})
		expect(wrapper.text()).toContain('Critical')
	})

	it('does not warn on idle swap', () => {
		// Allocating 0 swap, 0 used should not produce a swap warning.
		const wrapper = mountCard({
			system: baseSystem({ swap_total: 1000, swap_free: 1000 }),
		})
		expect(wrapper.text()).not.toMatch(/Swap at/)
	})
})
