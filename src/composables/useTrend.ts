/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, type ComputedRef, type Ref } from 'vue'

export type TrendDirection = 'up' | 'down' | 'flat'

export interface Trend {
	direction: TrendDirection
	delta: number
	deltaPercent: number
	hasEnoughData: boolean
}

/**
 * Compute a trend over a rolling history. Compares the most recent N
 * samples against the N samples before that. Returns direction + magnitude.
 *
 * @param history - rolling history array (oldest → newest)
 * @param windowSize - number of samples per comparison window
 * @param threshold - minimum absolute delta to register as up/down (vs flat)
 */
export function useTrend(
	history: Ref<number[]>,
	windowSize = 10,
	threshold = 0.5,
): ComputedRef<Trend> {
	return computed<Trend>(() => {
		const h = history.value
		if (h.length < windowSize * 2) {
			return { direction: 'flat', delta: 0, deltaPercent: 0, hasEnoughData: false }
		}
		const recent = h.slice(-windowSize)
		const previous = h.slice(-windowSize * 2, -windowSize)
		const recentAvg = recent.reduce((s, v) => s + v, 0) / recent.length
		const previousAvg = previous.reduce((s, v) => s + v, 0) / previous.length
		const delta = recentAvg - previousAvg
		const deltaPercent = previousAvg > 0 ? (delta / previousAvg) * 100 : 0
		let direction: TrendDirection = 'flat'
		if (Math.abs(delta) >= threshold) {
			direction = delta > 0 ? 'up' : 'down'
		}
		return { direction, delta, deltaPercent, hasEnoughData: true }
	})
}
