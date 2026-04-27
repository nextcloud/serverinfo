/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'
import { computed, type ComputedRef, type Ref } from 'vue'
import type { DiskInfo, SystemInfo } from '../types.ts'

export interface WeatherReport {
	emoji: string
	text: string
}

const adjective = (pct: number, kind: 'cpu' | 'mem' | 'disk'): string => {
	if (pct >= 95) {
		return kind === 'cpu' ? t('serverinfo', 'thunderstorm') : t('serverinfo', 'flooded')
	}
	if (pct >= 85) {
		return kind === 'cpu' ? t('serverinfo', 'stormy') : t('serverinfo', 'overcast')
	}
	if (pct >= 70) {
		return kind === 'cpu' ? t('serverinfo', 'cloudy') : t('serverinfo', 'humid')
	}
	if (pct >= 40) {
		return t('serverinfo', 'partly cloudy')
	}
	if (pct >= 15) {
		return t('serverinfo', 'sunny')
	}
	return t('serverinfo', 'crisp and clear')
}

const overallEmoji = (worst: number): string => {
	if (worst >= 95) return '⛈️'
	if (worst >= 85) return '🌩️'
	if (worst >= 70) return '⛅'
	if (worst >= 40) return '🌤️'
	if (worst >= 15) return '☀️'
	return '✨'
}

/**
 * Build a tongue-in-cheek "weather report" from current load metrics.
 * Updates reactively as the source values change.
 */
export function useWeather(
	system: Ref<SystemInfo>,
	disks: Ref<DiskInfo[]>,
): ComputedRef<WeatherReport> {
	return computed<WeatherReport>(() => {
		const sys = system.value

		let cpuPct = 0
		if (Array.isArray(sys.cpuload) && sys.cpuload.length > 0 && sys.cpunum > 0) {
			cpuPct = Math.min(100, ((Number(sys.cpuload[0]) || 0) / sys.cpunum) * 100)
		}

		let memPct = 0
		if (sys.mem_total > 0) {
			memPct = ((sys.mem_total - sys.mem_free) / sys.mem_total) * 100
		}

		let worstDiskPct = 0
		for (const d of disks.value) {
			const total = d.used + d.available
			if (total > 0) {
				worstDiskPct = Math.max(worstDiskPct, (d.used / total) * 100)
			}
		}

		const worst = Math.max(cpuPct, memPct, worstDiskPct)

		const text = t('serverinfo', '{cpuW} CPU, {memW} memory, {diskW} disk.', {
			cpuW: adjective(cpuPct, 'cpu'),
			memW: adjective(memPct, 'mem'),
			diskW: adjective(worstDiskPct, 'disk'),
		})

		return {
			emoji: overallEmoji(worst),
			text,
		}
	})
}
