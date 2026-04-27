/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { LiveUpdate, SystemInfo, ThermalZoneInfo } from '../types.ts'
import { useLiveData } from './useLiveData.ts'

const axiosMock = vi.hoisted(() => ({
	get: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

const baseSystem = (overrides: Partial<SystemInfo> = {}): SystemInfo => ({
	cpuload: [0.5, 0.4, 0.3],
	cpunum: 4,
	mem_total: 16_000_000,
	mem_free: 8_000_000,
	swap_total: 2_000_000,
	swap_free: 2_000_000,
	freespace: null,
	...overrides,
})

const baseZone = (overrides: Partial<ThermalZoneInfo> = {}): ThermalZoneInfo => ({
	zone: 'thermal_zone0',
	type: 'cpu',
	temp: 42,
	...overrides,
})

interface Harness extends ReturnType<typeof useLiveData> {}

const HostComponent = defineComponent({
	props: {
		updateUrl: { type: String, required: true },
		initialSystem: { type: Object, required: true },
		initialZones: { type: Array, required: true },
	},
	setup(props, { expose }) {
		const live = useLiveData(
			props.updateUrl,
			props.initialSystem as SystemInfo,
			props.initialZones as ThermalZoneInfo[],
		)
		expose(live)
		return () => h('div')
	},
})

const mountHarness = (
	system: SystemInfo,
	zones: ThermalZoneInfo[] = [],
) => mount(HostComponent, {
	props: { updateUrl: '/update', initialSystem: system, initialZones: zones },
}) as unknown as { vm: Harness, unmount: () => void }

const flushMicrotasks = () => Promise.resolve().then(() => Promise.resolve())

describe('serverinfo - useLiveData', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('seeds rolling history from the initial snapshot before the first poll', async () => {
		// Pending forever — onMounted seeds history before awaiting axios.
		axiosMock.get.mockReturnValue(new Promise(() => {}))
		const wrapper = mountHarness(baseSystem())
		await nextTick()

		expect(wrapper.vm.cpuHistory.length).toBe(1)
		expect(wrapper.vm.memHistory.length).toBe(1)
		expect(wrapper.vm.swapHistory.length).toBe(1)
		// 0.5 / 4 cores * 100 = 12.5%
		expect(wrapper.vm.cpuHistory[0]).toBeCloseTo(12.5)
		// (16M - 8M) / 16M * 100 = 50%
		expect(wrapper.vm.memHistory[0]).toBeCloseTo(50)
		// swap unused → 0%
		expect(wrapper.vm.swapHistory[0]).toBe(0)

		wrapper.unmount()
	})

	it('updates system, thermals, uptime and history on a successful poll', async () => {
		const update: LiveUpdate = {
			system: baseSystem({
				cpuload: [1, 0.5, 0.25],
				mem_free: 4_000_000,
				swap_free: 1_000_000,
			}),
			thermalzones: [baseZone({ temp: 71 })],
			uptime: 3661,
		}
		// Resolve every poll with the same data so the next scheduled poll
		// (fired by setTimeout in real time) won't flip `failed` to true.
		axiosMock.get.mockResolvedValue({ data: update })
		const wrapper = mountHarness(baseSystem(), [baseZone({ temp: 30 })])

		// Wait for the initial poll's promise chain to settle.
		await flushMicrotasks()
		await nextTick()

		expect(wrapper.vm.failed).toBe(false)
		expect(wrapper.vm.system.mem_free).toBe(4_000_000)
		expect(wrapper.vm.thermalZones[0].temp).toBe(71)
		expect(wrapper.vm.uptimeSeconds).toBe(3661)

		// History should now have two entries: seed + first poll
		expect(wrapper.vm.cpuHistory.length).toBe(2)
		// Second sample: 1.0 / 4 cores * 100 = 25%
		expect(wrapper.vm.cpuHistory.at(-1)).toBeCloseTo(25)
		// Memory: (16M - 4M) / 16M * 100 = 75%
		expect(wrapper.vm.memHistory.at(-1)).toBeCloseTo(75)
		// Swap: (2M - 1M) / 2M * 100 = 50%
		expect(wrapper.vm.swapHistory.at(-1)).toBeCloseTo(50)

		wrapper.unmount()
	})

	it('flags failure on errors and clears it on the next successful poll', async () => {
		// First poll rejects, second resolves cleanly.
		axiosMock.get
			.mockRejectedValueOnce(new Error('boom'))
			.mockResolvedValueOnce({
				data: { system: baseSystem(), thermalzones: [], uptime: 100 },
			})
			// Park further calls forever so timer-driven follow-ups don't interfere.
			.mockReturnValue(new Promise(() => {}))

		vi.useFakeTimers()
		const wrapper = mountHarness(baseSystem())
		await flushMicrotasks() // initial poll() runs — rejects
		await nextTick()
		expect(wrapper.vm.failed).toBe(true)

		await vi.advanceTimersByTimeAsync(2000) // run setTimeout → 2nd poll → resolves
		await flushMicrotasks()
		await nextTick()
		expect(wrapper.vm.failed).toBe(false)

		wrapper.unmount()
	})

	it('caps history at 90 samples', async () => {
		axiosMock.get.mockResolvedValue({
			data: { system: baseSystem(), thermalzones: [], uptime: 0 },
		})
		vi.useFakeTimers()
		const wrapper = mountHarness(baseSystem())
		await flushMicrotasks()
		await nextTick()

		// Drive the timer 95 times so we appended ~96 samples (seed + 95 polls).
		for (let i = 0; i < 95; i++) {
			await vi.advanceTimersByTimeAsync(2000)
			await flushMicrotasks()
		}
		await nextTick()

		expect(wrapper.vm.cpuHistory.length).toBe(90)
		expect(wrapper.vm.memHistory.length).toBe(90)
		expect(wrapper.vm.swapHistory.length).toBe(90)

		wrapper.unmount()
	})

	it('stops polling after unmount', async () => {
		axiosMock.get.mockResolvedValue({
			data: { system: baseSystem(), thermalzones: [], uptime: 0 },
		})
		vi.useFakeTimers()
		const wrapper = mountHarness(baseSystem())
		await flushMicrotasks()
		await nextTick()
		const callsBefore = axiosMock.get.mock.calls.length

		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(20_000)
		await flushMicrotasks()
		expect(axiosMock.get.mock.calls.length).toBe(callsBefore)
	})

	it('skips CPU history when cpuload is unavailable', async () => {
		axiosMock.get.mockReturnValue(new Promise(() => {}))
		const wrapper = mountHarness(baseSystem({ cpuload: false }))
		await nextTick()

		expect(wrapper.vm.cpuHistory.length).toBe(0)
		// Memory should still seed since mem_total > 0
		expect(wrapper.vm.memHistory.length).toBe(1)

		wrapper.unmount()
	})
})
