/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import type { LiveUpdate, SystemInfo, ThermalZoneInfo } from '../types.ts'

const POLL_INTERVAL_MS = 800
const MAX_HISTORY = 60

/**
 * Polls the serverinfo update endpoint and exposes live system data
 * plus a rolling history of CPU and memory samples for charts, live
 * thermal-zone readings, and live system uptime.
 *
 * @param updateUrl - URL of the page#update endpoint
 * @param initialSystem - Initial system snapshot from server-rendered state
 * @param initialThermalZones - Initial thermal zones from server-rendered state
 */
export function useLiveData(
	updateUrl: string,
	initialSystem: SystemInfo,
	initialThermalZones: ThermalZoneInfo[],
) {
	const system = shallowRef<SystemInfo>(initialSystem)
	const thermalZones = shallowRef<ThermalZoneInfo[]>(initialThermalZones)
	const cpuHistory = ref<number[]>([])
	const memHistory = ref<number[]>([])
	const swapHistory = ref<number[]>([])
	const lastUpdate = ref<number>(Date.now())
	const failed = ref(false)
	const uptimeSeconds = ref<number>(0)

	let timer: ReturnType<typeof setTimeout> | undefined
	let cancelled = false

	const pushHistory = (next: SystemInfo): void => {
		if (Array.isArray(next.cpuload) && next.cpuload.length > 0 && next.cpunum > 0) {
			const load1m = Number(next.cpuload[0]) || 0
			const usagePercent = Math.min(100, (load1m / next.cpunum) * 100)
			cpuHistory.value = appendCapped(cpuHistory.value, usagePercent, MAX_HISTORY)
		}

		if (next.mem_total > 0) {
			const used = Math.max(0, next.mem_total - next.mem_free)
			memHistory.value = appendCapped(memHistory.value, (used / next.mem_total) * 100, MAX_HISTORY)
		}
		if (next.swap_total > 0) {
			const usedSwap = Math.max(0, next.swap_total - next.swap_free)
			swapHistory.value = appendCapped(swapHistory.value, (usedSwap / next.swap_total) * 100, MAX_HISTORY)
		} else {
			swapHistory.value = appendCapped(swapHistory.value, 0, MAX_HISTORY)
		}
	}

	const poll = async (): Promise<void> => {
		try {
			const { data } = await axios.get<LiveUpdate>(updateUrl)
			if (cancelled) {
				return
			}
			system.value = data.system
			if (Array.isArray(data.thermalzones)) {
				thermalZones.value = data.thermalzones
			}
			if (typeof data.uptime === 'number') {
				uptimeSeconds.value = data.uptime
			}
			lastUpdate.value = Date.now()
			failed.value = false
			pushHistory(data.system)
		} catch {
			failed.value = true
		} finally {
			if (!cancelled) {
				timer = setTimeout(poll, POLL_INTERVAL_MS)
			}
		}
	}

	onMounted(() => {
		pushHistory(initialSystem)
		poll()
	})

	onBeforeUnmount(() => {
		cancelled = true
		if (timer !== undefined) {
			clearTimeout(timer)
		}
	})

	return {
		system,
		thermalZones,
		cpuHistory,
		memHistory,
		swapHistory,
		lastUpdate,
		failed,
		uptimeSeconds,
	}
}

/**
 * Append a value to an array, dropping the oldest entries past the cap.
 *
 * @param arr - The source array
 * @param value - The value to append
 * @param cap - Maximum length to keep
 */
function appendCapped(arr: number[], value: number, cap: number): number[] {
	const next = arr.length >= cap ? arr.slice(arr.length - cap + 1) : arr.slice()
	next.push(value)
	return next
}
