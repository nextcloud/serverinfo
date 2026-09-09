<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<SectionHeading :icon="Chip" :title="t('serverinfo', 'Load')" />
	<p v-if="loadAverage === null">
		<!-- TRANSLATORS: Shown instead of the CPU load chart when the server does not report CPU information -->
		<em>{{ t('serverinfo', 'CPU info not available') }}</em>
	</p>
	<template v-else>
		<div class="row row--tiles">
			<!-- TRANSLATORS: Tile label above the current CPU load as a percentage -->
			<StatTile v-if="usage !== null" :label="t('serverinfo', 'Current usage')" :value="`${usage} %`" />
			<!-- TRANSLATORS: Tile label above the number of CPU threads (logical cores) the server has -->
			<StatTile v-if="cpunum > 0" :label="t('serverinfo', 'Threads')" :value="cpunum" />
			<!-- TRANSLATORS: Tile label above the Unix load average, three numbers for the last 1/5/15 minutes -->
			<StatTile :label="t('serverinfo', 'Load average')" :value="loadAverage" />
		</div>
		<div v-if="usage !== null" id="cpuSection" class="infobox">
			<div class="chart-wrapper">
				<Line :data="chartData" :options="chartOptions" />
			</div>
		</div>
		<p v-else>
			<!-- TRANSLATORS: Shown under the load average when the server does not report how many CPU threads it has -->
			<em>{{ t('serverinfo', 'The number of CPU threads is unknown, so the load cannot be shown as a percentage.') }}</em>
		</p>
	</template>
</template>

<script setup lang="ts">
import type { TooltipItem } from 'chart.js'

import { t } from '@nextcloud/l10n'
import { CategoryScale, Chart, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js'
import { computed, shallowRef, watch } from 'vue'
import { Line } from 'vue-chartjs'
import Chip from 'vue-material-design-icons/Chip.vue'
import SectionHeading from './SectionHeading.vue'
import StatTile from './StatTile.vue'
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

// The load average comes from sys_getloadavg() and is real even on a host where
// the thread count could not be read. Keep showing it rather than discarding the
// one usable number along with the percentage it cannot produce.
const loadAverage = computed(() => (props.cpuload === false || props.cpuload.length === 0)
	? null
	: props.cpuload.map((load) => load.toFixed(2)).join(' / '))

// A percentage needs a divisor, and -1 threads is the API saying it has none.
const usage = computed(() => (props.cpuload === false || props.cpuload.length === 0 || props.cpunum <= 0)
	? null
	: ((props.cpuload[0] / props.cpunum) * 100).toFixed(1))

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
