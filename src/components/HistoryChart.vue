<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="history-chart">
		<p v-if="failed" class="history-chart__note">
			<!-- TRANSLATORS: Shown instead of a history chart when loading it failed -->
			<em>{{ t('serverinfo', 'The history could not be loaded.') }}</em>
		</p>
		<p v-else-if="!payload" class="history-chart__note">
			<!-- TRANSLATORS: Shown while a history chart is being loaded -->
			<em>{{ t('serverinfo', 'Loading history …') }}</em>
		</p>
		<p v-else-if="payload.series.t.length === 0" class="history-chart__note">
			<!-- TRANSLATORS: Shown instead of a history chart before the background job stored the first sample -->
			<em>{{ t('serverinfo', 'History starts collecting now; the first points appear after about 5 minutes.') }}</em>
		</p>
		<template v-else>
			<div class="chart-wrapper">
				<Line
					:data="chartData"
					:options="chartOptions"
					:aria-label="label" />
			</div>
			<p v-if="summary" class="history-chart__summary">
				{{ summary }}
			</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { ScriptableContext, TooltipItem } from 'chart.js'
import type { HistoryUnit } from '../history.ts'
import type { HistoryPayload } from '../types.ts'

import { t } from '@nextcloud/l10n'
import { CategoryScale, Chart, Filler, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js'
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import { alignSeries, formatBucket, formatUnit } from '../history.ts'
import { cssColor } from '../utils.ts'

export interface HistoryDataset {
	/** Name in the legend and tooltip */
	label: string
	/** Series of the payload to plot */
	metric: string
	/** Factor from the stored value to the chart's unit, 1 when omitted */
	scale?: number
	borderColor: string
	backgroundColor?: string
	fill?: boolean
	dashed?: boolean
}

interface Props {
	payload: HistoryPayload | null
	failed: boolean
	datasets: HistoryDataset[]
	/** Accessible name of the chart */
	label: string
	/** Text alternative to the chart, also shown below it */
	summary: string
	unit: HistoryUnit
	yMax?: number
}

const props = defineProps<Props>()

Chart.register(CategoryScale, Filler, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip)

/**
 * A sample between two gaps has no neighbour to draw a line to, so it is
 * drawn as a dot rather than not at all.
 *
 * @param context the point being drawn
 */
function isolatedPointRadius(context: ScriptableContext<'line'>): number {
	const data = context.dataset.data as Array<number | null>
	const i = context.dataIndex
	return data[i] !== null && (data[i - 1] ?? null) === null && (data[i + 1] ?? null) === null ? 2 : 0
}

const chartData = computed(() => {
	const payload = props.payload
	if (!payload) {
		return { labels: [], datasets: [] }
	}

	let labels: string[] = []
	const datasets = props.datasets.map((dataset) => {
		const { times, values } = alignSeries(payload, dataset.metric)
		labels = times.map((time) => formatBucket(time, payload.range))
		return {
			label: dataset.label,
			data: values.map((value) => value === null ? null : value * (dataset.scale ?? 1)),
			borderColor: dataset.borderColor,
			backgroundColor: dataset.backgroundColor ?? dataset.borderColor,
			fill: dataset.fill ?? false,
			borderDash: dataset.dashed ? [4, 4] : [],
			borderWidth: 1,
			pointRadius: isolatedPointRadius,
			tension: 0.2,
			spanGaps: false,
		}
	})
	return { labels, datasets }
})

const chartOptions = computed(() => {
	const textColor = cssColor('--color-text-maxcontrast', 'rgb(148, 148, 148)')
	return {
		animation: false as const,
		responsive: true,
		maintainAspectRatio: false,
		interaction: { mode: 'index' as const, intersect: false },
		scales: {
			x: {
				ticks: { color: textColor, maxTicksLimit: 6, maxRotation: 0, autoSkip: true },
				grid: { display: false },
			},
			y: {
				min: 0,
				max: props.yMax,
				ticks: { color: textColor, callback: (value: number | string) => formatUnit(Number(value), props.unit) },
				grid: { display: false },
			},
		},
		plugins: {
			legend: {
				display: props.datasets.length > 1,
				position: 'bottom' as const,
				labels: { color: textColor },
			},
			tooltip: {
				callbacks: { label: (ctx: TooltipItem<'line'>) => (ctx.dataset.label ?? '') + ': ' + formatUnit(ctx.parsed.y, props.unit) },
			},
		},
	}
})
</script>

<style scoped lang="scss">
.chart-wrapper {
	height: 200px;
	width: 100%;
}

.history-chart__note {
	display: flex;
	align-items: center;
	min-height: 200px;
	color: var(--color-text-maxcontrast);
}

.history-chart__summary {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
