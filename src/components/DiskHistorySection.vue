<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section disk-history">
		<SectionHeading :icon="ChartAreaspline" :title="t('serverinfo', 'Disk usage over time')" />
		<!-- TRANSLATORS: Accessible name of the buttons choosing the time range of the disk usage chart -->
		<RangeSwitch v-model="range" :label="t('serverinfo', 'Disk usage chart range')" :options="historyRanges()" />
		<HistoryChart
			:payload="payload"
			:failed="failed"
			:datasets="datasets"
			:label="t('serverinfo', 'Disk usage of the data directory over time')"
			:summary="summary"
			unit="bytes" />
	</div>
</template>

<script setup lang="ts">
import type { HistoryPayload, HistoryRange } from '../types.ts'
import type { HistoryDataset } from './HistoryChart.vue'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import ChartAreaspline from 'vue-material-design-icons/ChartAreaspline.vue'
import HistoryChart from './HistoryChart.vue'
import RangeSwitch from './RangeSwitch.vue'
import SectionHeading from './SectionHeading.vue'
import { useHistory } from '../composables/useHistory.ts'
import { historyRanges, summarize } from '../history.ts'
import { cssColor, formatBytes, primaryColor, withAlpha } from '../utils.ts'

const range = ref<HistoryRange>('7d')
const { data, failed } = useHistory(range)

// The history stores what is free, the space Nextcloud can still use; the
// chart shows the rest of the file system as used.
const payload = computed((): HistoryPayload | null => {
	if (data.value === null) {
		return null
	}
	const { diskFree, diskTotal } = data.value.series
	return {
		...data.value,
		series: {
			...data.value.series,
			diskUsed: data.value.series.t.map((_, i) => {
				const free = diskFree?.[i] ?? null
				const total = diskTotal?.[i] ?? null
				return free === null || total === null ? null : total - free
			}),
		},
	}
})

const datasets = computed((): HistoryDataset[] => [
	{
		// TRANSLATORS: Chart legend entry for the used disk space over time
		label: t('serverinfo', 'Used'),
		metric: 'diskUsed',
		borderColor: cssColor('--color-text-maxcontrast', 'rgb(148, 148, 148)'),
		backgroundColor: withAlpha(primaryColor(), 0.4),
		fill: true,
	},
	{
		// TRANSLATORS: Chart legend entry for the size of the file system over time
		label: t('serverinfo', 'Size'),
		metric: 'diskTotal',
		borderColor: cssColor('--color-text-maxcontrast', 'rgb(148, 148, 148)'),
		dashed: true,
	},
])

const summary = computed(() => {
	const used = summarize(payload.value?.series.diskUsed ?? [])
	const total = summarize(payload.value?.series.diskTotal ?? [])
	if (used === null || total === null) {
		return ''
	}
	const change = used.latest - used.first
	const values = { used: formatBytes(used.latest), total: formatBytes(total.latest), change: formatBytes(Math.abs(change)) }
	return change >= 0
		// TRANSLATORS: Text below the disk usage chart, e.g. "120.5 GB of 500.0 GB used, 3.2 GB more than at the start of the range"
		? t('serverinfo', '{used} of {total} used, {change} more than at the start of the range', values)
		// TRANSLATORS: Text below the disk usage chart, e.g. "120.5 GB of 500.0 GB used, 3.2 GB less than at the start of the range"
		: t('serverinfo', '{used} of {total} used, {change} less than at the start of the range', values)
})
</script>
