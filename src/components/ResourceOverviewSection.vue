<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- TRANSLATORS: Section heading above the progress bars for CPU, memory and swap usage, noun -->
	<SectionHeading :icon="Gauge" :title="t('serverinfo', 'Resource usage')" />
	<div class="resource-usage">
		<div v-for="row in rows" :key="row.label" class="resource-usage__row">
			<div class="resource-usage__header">
				<span>{{ row.label }}</span>
				<span class="info">{{ row.detail }}</span>
			</div>
			<NcProgressBar
				:value="row.percentage"
				size="medium"
				:color="row.percentage >= WARNING_AT ? 'var(--color-warning)' : undefined"
				:error="row.percentage >= CRITICAL_AT" />
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import Gauge from 'vue-material-design-icons/Gauge.vue'
import SectionHeading from './SectionHeading.vue'
import { formatMegabytes } from '../utils.ts'

const props = defineProps<{
	cpuload: number[] | false
	cpunum: number
	memTotal: number | 'N/A'
	memFree: number | 'N/A'
	swapTotal: number | 'N/A'
	swapFree: number | 'N/A'
}>()

const WARNING_AT = 75
const CRITICAL_AT = 90

/**
 * A usage bar with its "used of total" caption, or null when the metric is
 * unavailable — a container without swap, a host that reports no load.
 *
 * @param label row label
 * @param used amount in use, in megabytes
 * @param total amount available, in megabytes
 */
function memoryRow(label: string, used: number | 'N/A', total: number | 'N/A') {
	if (used === 'N/A' || total === 'N/A' || total <= 0) {
		return null
	}
	return {
		label,
		// TRANSLATORS: Caption next to a usage bar; both placeholders are formatted sizes, e.g. "3.2 GB of 16 GB"
		detail: t('serverinfo', '{used} of {total}', {
			used: formatMegabytes(used),
			total: formatMegabytes(total),
		}),
		percentage: Math.round((used / total) * 100),
	}
}

const rows = computed(() => {
	const memTotal = props.memTotal
	const swapTotal = props.swapTotal

	return [
		props.cpuload === false || props.cpunum <= 0
			? null
			: {
					// TRANSLATORS: Label of the usage bar showing processor load
					label: t('serverinfo', 'CPU'),
					detail: ((props.cpuload[0] / props.cpunum) * 100).toFixed(1) + ' %',
					// Load can exceed the thread count, so the bar caps where the caption does not.
					percentage: Math.min(100, Math.round((props.cpuload[0] / props.cpunum) * 100)),
				},
		memoryRow(
			// TRANSLATORS: Label of the usage bar showing RAM consumption
			t('serverinfo', 'Memory'),
			memTotal === 'N/A' || props.memFree === 'N/A' ? 'N/A' : memTotal - props.memFree,
			memTotal,
		),
		memoryRow(
			// TRANSLATORS: Label of the usage bar showing swap space consumption
			t('serverinfo', 'Swap'),
			swapTotal === 'N/A' || props.swapFree === 'N/A' ? 'N/A' : swapTotal - props.swapFree,
			swapTotal,
		),
	].filter((row) => row !== null)
})
</script>

<style scoped>
.resource-usage {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}

.resource-usage__header {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 4px;
}
</style>
