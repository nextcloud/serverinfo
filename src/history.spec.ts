/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { HistoryPayload } from './types.ts'

import { describe, expect, it } from 'vitest'
import { alignSeries, chartRanges, formatUnit, summarize } from './history.ts'

function payload(t: number[], load1: Array<number | null>): HistoryPayload {
	return {
		range: '24h',
		resolution: 300,
		from: 1000,
		now: 2600,
		node: 'web1',
		series: { t, load1 },
	}
}

describe('alignSeries', () => {
	it('puts the points on a regular axis and leaves gaps where nothing was sampled', () => {
		const { times, values } = alignSeries(payload([1200, 1500, 2400], [1, null, 3]), 'load1')

		expect(times).toEqual([1200, 1500, 1800, 2100, 2400])
		expect(values).toEqual([1, null, null, null, 3])
	})

	it('treats a metric the response lacks as never sampled', () => {
		expect(alignSeries(payload([1200], [1]), 'diskFree').values).toEqual([null, null, null, null, null])
	})
})

describe('summarize', () => {
	it('ignores missing values', () => {
		expect(summarize([null, 2, 6, null, 4])).toEqual({ average: 4, peak: 6, first: 2, latest: 4 })
	})

	it('has nothing to say without values', () => {
		expect(summarize([null, null])).toBeNull()
	})
})

describe('formatUnit', () => {
	it('rounds to what the axis can show', () => {
		expect(formatUnit(12.345, 'percent')).toBe('12.3 %')
		expect(formatUnit(3.14159, 'gigabytes')).toBe('3.14 GB')
		expect(formatUnit(1536, 'bytes')).toBe('1.5 KB')
	})
})

describe('chartRanges', () => {
	it('starts with the live values', () => {
		expect(chartRanges().map((range) => range.id)).toEqual(['live', '24h', '7d', '30d', '1y'])
	})
})
