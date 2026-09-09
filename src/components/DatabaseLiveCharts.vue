<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="db-live">
		<section v-for="box in boxes" :key="box.key" class="db-live__box">
			<header class="db-live__head">
				<span class="db-live__label">{{ box.label }}</span>
				<span class="db-live__value">{{ box.value }}</span>
			</header>
			<div class="db-live__chart">
				<Line :data="box.data" :options="box.options" />
			</div>
		</section>
	</div>
</template>

<script setup lang="ts">
import type { ChartOptions } from 'chart.js'

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { CategoryScale, Chart, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js'
import { computed, onUnmounted, ref, shallowRef, watch } from 'vue'
import { Line } from 'vue-chartjs'
import { primaryColor, withAlpha } from '../utils.ts'

const props = defineProps<{ active: boolean }>()

Chart.register(CategoryScale, Filler, LinearScale, LineController, LineElement, PointElement, Tooltip)

/** Matches the Status page's charts, which keep a minute of two-second samples. */
const MAX_POINTS = 30
const INTERVAL_MS = 3000

// Grown rather than pre-filled with blanks: a half-empty fixed-width series
// squashes the first samples into a sliver against the right edge.
const connections = shallowRef<(number | null)[]>([])
const queries = shallowRef<(number | null)[]>([])
const latency = shallowRef<(number | null)[]>([])

const connectionsNow = ref<string>('—')
const queriesNow = ref<string>('—')
const latencyNow = ref<string>('—')
const throughputLabel = ref(t('serverinfo', 'Queries per second'))

let timeoutId: ReturnType<typeof setTimeout> | null = null
let stopped = false
// Throughput is published as a cumulative counter, so a rate needs two samples.
let previousCounter: number | null = null
let previousAt: number | null = null

const colour = computed(() => primaryColor())

/**
 * Appends a sample, keeping the series at a fixed length so the chart scrolls.
 *
 * @param series the ref holding the samples
 * @param value the new sample, or null when it could not be measured
 */
function push(series: typeof connections, value: number | null) {
	const next = [...series.value, value]
	series.value = next.length > MAX_POINTS ? next.slice(next.length - MAX_POINTS) : next
}

async function poll() {
	timeoutId = null
	try {
		const { data } = await axios.get(generateUrl('/apps/serverinfo/database/live'))

		push(latency, data.latencyMs ?? null)
		latencyNow.value = data.latencyMs !== null ? `${data.latencyMs} ms` : '—'

		if (data.available) {
			push(connections, data.connections.used)
			connectionsNow.value = `${data.connections.used} / ${data.connections.max}`

			const now = Date.now()
			if (previousCounter !== null && previousAt !== null) {
				const seconds = (now - previousAt) / 1000
				const delta = data.throughput.counter - previousCounter
				// A counter that went backwards means the server restarted.
				const rate = seconds > 0 && delta >= 0 ? delta / seconds : null
				push(queries, rate === null ? null : Math.round(rate))
				queriesNow.value = rate === null ? '—' : Math.round(rate).toLocaleString()
			}
			previousCounter = data.throughput.counter
			previousAt = now
			throughputLabel.value = data.throughput.label === 'Transactions/s'
				// TRANSLATORS: Label of the live chart counting PostgreSQL transactions
				? t('serverinfo', 'Transactions per second')
				// TRANSLATORS: Label of the live chart counting MySQL/MariaDB queries
				: t('serverinfo', 'Queries per second')
		}
	} catch {
		// A failed sample leaves a gap in the line rather than a false zero.
		push(connections, null)
		push(queries, null)
		push(latency, null)
	} finally {
		schedule()
	}
}

function schedule() {
	if (stopped || !props.active || document.hidden || timeoutId !== null) {
		return
	}

	timeoutId = setTimeout(poll, INTERVAL_MS)
}

function stop() {
	if (timeoutId !== null) {
		clearTimeout(timeoutId)
		timeoutId = null
	}
}

// Only polls while this page is the one on screen and the tab is visible:
// three live series are not worth a request every three seconds for nobody.
watch(() => [props.active, document.hidden], () => {
	if (props.active && !document.hidden) {
		if (timeoutId === null) {
			poll()
		}
	} else {
		stop()
	}
}, { immediate: true })

document.addEventListener('visibilitychange', () => {
	if (document.hidden) {
		stop()
	} else if (props.active && timeoutId === null) {
		poll()
	}
})

onUnmounted(() => {
	stopped = true
	stop()
})

/**
 * Chart options shared by the three boxes: no axes, no legend, just the shape.
 *
 * @param unit appended to the tooltip value
 */
function optionsFor(unit: string): ChartOptions<'line'> {
	return {
		animation: false,
		responsive: true,
		maintainAspectRatio: false,
		scales: {
			x: { display: false },
			y: { display: false, beginAtZero: true },
		},
		plugins: {
			legend: { display: false },
			tooltip: {
				displayColors: false,
				callbacks: { label: (ctx) => `${ctx.parsed.y ?? '—'} ${unit}` },
			},
		},
		elements: { point: { radius: 0, hitRadius: 8 } },
	}
}

/**
 * @param data the samples to draw
 */
function dataFor(data: (number | null)[]) {
	return {
		labels: data.map(() => ''),
		datasets: [{
			data,
			borderColor: colour.value,
			backgroundColor: withAlpha(colour.value, 0.15),
			borderWidth: 2,
			fill: true,
			tension: 0.3,
			spanGaps: false,
		}],
	}
}

const boxes = computed(() => [
	{
		key: 'connections',
		// TRANSLATORS: Label of the live chart showing how many database connections are open
		label: t('serverinfo', 'Current connections'),
		value: connectionsNow.value,
		data: dataFor(connections.value),
		options: optionsFor(t('serverinfo', 'connections')),
	},
	{
		key: 'queries',
		label: throughputLabel.value,
		value: queriesNow.value,
		data: dataFor(queries.value),
		options: optionsFor('/s'),
	},
	{
		key: 'latency',
		// TRANSLATORS: Label of the live chart showing how long a round trip to the database takes
		label: t('serverinfo', 'Database latency'),
		value: latencyNow.value,
		data: dataFor(latency.value),
		options: optionsFor('ms'),
	},
])
</script>

<style scoped>
.db-live {
	/* Three equal boxes on one row, folding to one column when the settings
	   pane is too narrow to keep them readable. */
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 10px;
	margin-block-end: 12px;
}

.db-live__box {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	background: var(--color-main-background);
}

.db-live__head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 12px;
}

.db-live__label {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.db-live__value {
	font-weight: bold;
	font-size: 1.1rem;
	white-space: nowrap;
}

.db-live__chart {
	block-size: 70px;
	margin-block-start: 8px;
}
</style>
