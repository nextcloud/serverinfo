/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { HistoryPayload } from '../types.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import HistoryChart from './HistoryChart.vue'

vi.mock('vue-chartjs', async () => ({ Line: (await import('../testing/chartStub.ts')).LineStub }))

function payload(t: number[]): HistoryPayload {
	return {
		range: '24h',
		resolution: 300,
		from: 0,
		now: 900,
		node: 'web1',
		series: { t, load5: t.map(() => 0.5) },
	}
}

function props(overrides = {}) {
	return {
		payload: payload([0, 300]),
		failed: false,
		datasets: [{ label: 'Average load', metric: 'load5', scale: 50, borderColor: 'grey' }],
		label: 'CPU load over time',
		summary: 'Average load 25 %, highest 25 %',
		unit: 'percent' as const,
		...overrides,
	}
}

describe('HistoryChart', () => {
	it('names the chart and spells out what it shows', () => {
		const wrapper = mount(HistoryChart, { props: props() })

		expect(wrapper.find('canvas').attributes('aria-label')).toBe('CPU load over time')
		expect(wrapper.text()).toContain('Average load 25 %, highest 25 %')
		expect(JSON.parse(wrapper.find('canvas').attributes('data-points')!)).toEqual([[25, 25, null, null]])
	})

	it('says when the history has no points yet', () => {
		const wrapper = mount(HistoryChart, { props: props({ payload: payload([]) }) })

		expect(wrapper.find('canvas').exists()).toBe(false)
		expect(wrapper.text()).toContain('History starts collecting now; the first points appear after about 5 minutes.')
	})

	it('says when loading failed', () => {
		const wrapper = mount(HistoryChart, { props: props({ payload: null, failed: true }) })

		expect(wrapper.text()).toContain('The history could not be loaded.')
	})
})
