/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { onUnmounted, ref } from 'vue'

export interface ThermalZone {
	zone: string
	type: string
	temp: number
}

export interface LiveData {
	cpu: { load: number[] | false }
	memory: { total: number, free: number, swap_total: number, swap_free: number }
	servertime: string
	uptime: string
	thermalzones: ThermalZone[]
}

export function useLiveData() {
	const data = ref<LiveData | null>(null)
	const tick = ref(0)

	let timeoutId: ReturnType<typeof setTimeout> | null = null
	let stopped = false

	async function poll() {
		try {
			const response = await axios.get(generateUrl('/apps/serverinfo/update'))
			const live = response.data as LiveData
			// The API says "no CPU data" as either false or an empty array. Collapse
			// the two here so consumers only ever have to check for false — an
			// unchecked load[0] otherwise renders NaN.
			if (Array.isArray(live.cpu?.load) && live.cpu.load.length === 0) {
				live.cpu.load = false
			}
			data.value = live
			tick.value++
		} catch {
			// Keep previous values on error
		} finally {
			if (!stopped) {
				timeoutId = setTimeout(poll, 2000)
			}
		}
	}

	timeoutId = setTimeout(poll, 0)

	onUnmounted(() => {
		stopped = true
		if (timeoutId !== null) {
			clearTimeout(timeoutId)
			timeoutId = null
		}
	})

	return { data, tick }
}
