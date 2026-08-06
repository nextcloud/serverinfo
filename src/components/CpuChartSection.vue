<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<SectionHeading :icon="Chip" :title="t('serverinfo', 'Load')" />
	<div id="cpuSection" class="infobox">
		<div class="chart-wrapper">
			<Line v-if="cpuload !== false" :data="chartData" :options="chartOptions" />
		</div>
	</div>
	<p>
		<span id="cpubox" class="cpubox">&nbsp;&nbsp;</span>&nbsp;&nbsp;
		<em>{{ footerText }}</em>
	</p>
</template>

<script setup lang="ts">
import type { TooltipItem } from 'chart.js'

import { t } from '@nextcloud/l10n'
import { CategoryScale, Chart, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js'
import { computed, shallowRef, watch } from 'vue'
import { Line } from 'vue-chartjs'
import Chip from 'vue-material-design-icons/Chip.vue'
import SectionHeading from './SectionHeading.vue'
import { primaryColor, withAlpha } from '../utils.ts'

const props = defineProps<{
	cpuload: number[] | false
	cpunum: number
	tick: number
}>()

Chart.register(CategoryScale, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip)

const MAX_POINTS = 60

/**
 *
 */
function passiveColor(): string {
	return 'rgb(148, 148, 148)'
}

const datasetStyle = {
	borderColor: passiveColor(),
	backgroundColor: withAlpha(primaryColor(), 0.4),
	fill: true,
	pointRadius: 0,
	borderWidth: 1,
	tension: 0.2,
}

// Keep the chart data non-deeply-reactive: Chart.js stores back-references on the
// data it receives, and a reactive() proxy makes Vue recurse over those cycles
// ("Maximum call stack size exceeded"). We swap in a fresh plain object each tick
// instead — new label/dataset references are also what vue-chartjs needs to detect
// a change and re-render.
const chartData = shallowRef({
	labels: Array(MAX_POINTS).fill('') as string[],
	datasets: [{ ...datasetStyle, data: Array(MAX_POINTS).fill(null) as (number | null)[] }],
})

const chartOptions = {
	animation: false,
	responsive: true,
	maintainAspectRatio: false,
	scales: {
		x: { display: false },
		y: {
			min: 0,
			max: 100,
			ticks: { color: passiveColor(), callback: (v: number | string) => v + ' %' },
			grid: { display: false },
		},
	},
	interaction: {
		mode: 'index' as const,
		intersect: false,
	},
	plugins: {
		legend: { display: false },
		tooltip: {
			callbacks: { label: (ctx: TooltipItem<'line'>) => ctx.parsed.y.toFixed(1) + ' %' },
		},
	},
}

const footerText = computed(() => {
	if (props.cpuload === false || props.cpunum <= 0) {
		return t('serverinfo', 'CPU info not available')
	}
	const pct = props.cpuload.map((l) => ((l / props.cpunum) * 100).toFixed(1))
	const load = props.cpuload.map((l) => l.toFixed(2))
	return t('serverinfo', 'Load average: {percentage} % ({load}) last minute', {
		percentage: pct[0],
		load: load[0],
	})
})

watch(() => props.tick, () => {
	const cpuload = props.cpuload
	if (cpuload === false || props.cpunum <= 0 || cpuload.length === 0) {
		return
	}
	const labels = [...chartData.value.labels.slice(1), new Date().toLocaleTimeString()]
	const data = [...chartData.value.datasets[0].data.slice(1), cpuload[0] / props.cpunum * 100]
	chartData.value = {
		labels,
		datasets: [{ ...datasetStyle, data }],
	}
})
</script>

<style scoped lang="scss">
	.chart-wrapper {
		//position:relative;
		height:200px;
		width: 100%;
	}
</style>
