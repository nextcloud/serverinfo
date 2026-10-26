/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { HistoryPayload, HistoryRange } from './types.ts'

import { t } from '@nextcloud/l10n'
import { formatBytes } from './utils.ts'

/** How a chart's values are shown */
export type HistoryUnit = 'percent' | 'gigabytes' | 'bytes'

/**
 * @param value value in the chart's unit
 * @param unit the chart's unit
 */
export function formatUnit(value: number, unit: HistoryUnit): string {
	switch (unit) {
		case 'percent':
			return `${Math.round(value * 10) / 10} %`
		case 'gigabytes':
			return `${Math.round(value * 100) / 100} GB`
		case 'bytes':
			return formatBytes(value)
	}
}

/**
 * Labels of the range switch, in display order.
 */
export function historyRanges(): Array<{ id: HistoryRange, label: string }> {
	return [
		// TRANSLATORS: Chart range: the last 24 hours
		{ id: '24h', label: t('serverinfo', '24 h') },
		// TRANSLATORS: Chart range: the last 7 days
		{ id: '7d', label: t('serverinfo', '7 days') },
		// TRANSLATORS: Chart range: the last 30 days
		{ id: '30d', label: t('serverinfo', '30 days') },
		// TRANSLATORS: Chart range: the last 365 days
		{ id: '1y', label: t('serverinfo', '1 year') },
	]
}

/**
 * Labels of the range switch on charts that also have live values.
 */
export function chartRanges(): Array<{ id: 'live' | HistoryRange, label: string }> {
	return [
		// TRANSLATORS: Chart range: the values of the last two minutes, updated every few seconds
		{ id: 'live', label: t('serverinfo', 'Live') },
		...historyRanges(),
	]
}

/**
 * Puts the points on a regular time axis from the range start to now, so
 * that time without samples shows as a gap instead of being drawn over.
 *
 * @param payload the history response
 * @param metric name of the series to align
 */
export function alignSeries(payload: HistoryPayload, metric: string): { times: number[], values: Array<number | null> } {
	const { resolution, from, now, series } = payload
	const byTime = new Map<number, number | null>()
	series.t.forEach((time, i) => byTime.set(time, series[metric]?.[i] ?? null))

	const times: number[] = []
	const values: Array<number | null> = []
	const first = Math.min(Math.ceil(from / resolution) * resolution, series.t[0] ?? Infinity)
	for (let time = first; time <= now; time += resolution) {
		times.push(time)
		values.push(byTime.get(time) ?? null)
	}
	return { times, values }
}

/**
 * Formats a bucket start for the axis: hours for a day, days for longer
 * ranges. Daily buckets are UTC days, so they are labelled in UTC.
 *
 * @param time Unix timestamp
 * @param range the range shown
 */
export function formatBucket(time: number, range: HistoryRange): string {
	const date = new Date(time * 1000)
	switch (range) {
		case '24h':
			return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
		case '7d':
			return date.toLocaleString(undefined, { weekday: 'short', hour: '2-digit', minute: '2-digit' })
		case '30d':
			return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
		case '1y':
			return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' })
	}
}

/**
 * Average, highest and most recent of the known values, null without any.
 *
 * @param values one series, null where nothing was sampled
 */
export function summarize(values: Array<number | null>): { average: number, peak: number, first: number, latest: number } | null {
	const known = values.filter((value): value is number => value !== null)
	if (known.length === 0) {
		return null
	}
	return {
		average: known.reduce((sum, value) => sum + value, 0) / known.length,
		peak: Math.max(...known),
		first: known[0],
		latest: known[known.length - 1],
	}
}
