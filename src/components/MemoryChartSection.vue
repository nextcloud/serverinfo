<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<SectionHeading :icon="Memory" :title="t('serverinfo', 'Memory')" />
	<div id="memorySection" class="infobox">
		<div class="chart-wrapper">
			<Line v-if="hasMemory" :data="chartData" :options="chartOptions" />
		</div>
	</div>
	<p>
		<span id="rambox" class="rambox">&nbsp;&nbsp;</span>&nbsp;&nbsp;
		<em>{{ memText }}</em>
	</p>
	<p>
		<span id="swapbox" class="swapbox">&nbsp;&nbsp;</span>&nbsp;&nbsp;
		<em>{{ swapText }}</em>
	</p>
</template>

<script setup lang="ts">
import type { TooltipItem } from 'chart.js'

import { t } from '@nextcloud/l10n'
import { CategoryScale, Chart, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js'
import { computed, ref, shallowRef, watch } from 'vue'
import { Line } from 'vue-chartjs'
import Memory from 'vue-material-design-icons/Memory.vue'
import SectionHeading from './SectionHeading.vue'
import { formatBytes, primaryColor, withAlpha } from '../utils.ts'

const props = defineProps<{
	memTotal: number | 'N/A'
	memFree: number | 'N/A'
	swapTotal: number | 'N/A'
	swapFree: number | 'N/A'
	tick: number
}>()

Chart.register(CategoryScale, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip)

const MAX_POINTS = 60

const hasMemory = computed(() => props.memTotal !== 'N/A' && props.memFree !== 'N/A')
const hasSwap = computed(() => props.swapTotal !== 'N/A' && props.swapFree !== 'N/A')

/**
 *
 */
function passiveColor(): string {
	return 'rgb(148, 148, 148)'
}

const ramDataset = {
	label: t('serverinfo', 'RAM Usage:'),
	borderColor: passiveColor(),
	backgroundColor: withAlpha(primaryColor(), 0.4),
	fill: true,
	pointRadius: 0,
	borderWidth: 1,
	tension: 0.2,
}

const swapDataset = {
	label: t('serverinfo', 'SWAP Usage:'),
	borderColor: 'rgba(100,100,100,0.8)',
	backgroundColor: 'rgba(100,100,100,0.2)',
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
	datasets: [
		{ ...ramDataset, data: Array(MAX_POINTS).fill(null) as (number | null)[] },
		{ ...swapDataset, data: Array(MAX_POINTS).fill(null) as (number | null)[] },
	],
})

const maxGB = ref(1)

const chartOptions = computed(() => ({
	animation: false,
	responsive: true,
	maintainAspectRatio: false,
	interaction: {
		mode: 'index' as const,
		intersect: false,
	},
	scales: {
		x: { display: false },
		y: {
			min: 0,
			max: maxGB.value,
			ticks: { color: passiveColor(), callback: (v: number | string) => v + ' GB' },
			grid: { display: false },
		},
	},
	plugins: {
		legend: { display: false },
		tooltip: {
			callbacks: { label: (ctx: TooltipItem<'line'>) => (ctx.dataset.label ?? '') + ' ' + ctx.parsed.y.toFixed(2) + ' GB' },
		},
	},
}))

const memText = computed(() => {
	if (!hasMemory.value) {
		return t('serverinfo', 'RAM info not available')
	}
	const total = (props.memTotal as number) * 1024 * 1024
	const used = ((props.memTotal as number) - (props.memFree as number)) * 1024 * 1024
	return t('serverinfo', 'RAM: Total: {memTotalBytes}/Current usage: {memUsageBytes}', {
		memTotalBytes: formatBytes(total),
		memUsageBytes: formatBytes(used),
	})
})

const swapText = computed(() => {
	if (!hasSwap.value) {
		return t('serverinfo', 'SWAP info not available')
	}
	const total = (props.swapTotal as number) * 1024 * 1024
	const used = ((props.swapTotal as number) - (props.swapFree as number)) * 1024 * 1024
	return t('serverinfo', 'SWAP: Total: {swapTotalBytes}/Current usage: {swapUsageBytes}', {
		swapTotalBytes: formatBytes(total),
		swapUsageBytes: formatBytes(used),
	})
})

watch(() => props.tick, () => {
	if (!hasMemory.value) {
		return
	}

	const memTotalGB = (props.memTotal as number) / 1024
	const swapTotalGB = hasSwap.value ? (props.swapTotal as number) / 1024 : 0
	maxGB.value = Math.ceil(Math.max(memTotalGB, swapTotalGB))

	const labels = [...chartData.value.labels.slice(1), new Date().toLocaleTimeString()]

	const memUsageGB = ((props.memTotal as number) - (props.memFree as number)) / 1024
	const ramData = [...chartData.value.datasets[0].data.slice(1), memUsageGB]

	const swapUsageGB = hasSwap.value
		? ((props.swapTotal as number) - (props.swapFree as number)) / 1024
		: null
	const swapData = [...chartData.value.datasets[1].data.slice(1), swapUsageGB]

	chartData.value = {
		labels,
		datasets: [
			{ ...ramDataset, data: ramData },
			{ ...swapDataset, data: swapData },
		],
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
