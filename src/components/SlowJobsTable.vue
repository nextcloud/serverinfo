<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="server-info-table">
		<table>
			<thead>
				<tr>
					<th class="job-cell">
						<!-- TRANSLATORS: Table column holding the name of a background job, noun -->
						{{ t('serverinfo', 'Job') }}
					</th>
					<th class="count-cell">
						<!-- TRANSLATORS: Table column holding how often a background job ran, noun -->
						{{ t('serverinfo', 'Runs') }}
					</th>
					<th class="count-cell">
						<!-- TRANSLATORS: Table column holding the average time a background job took to run, noun -->
						{{ t('serverinfo', 'Average') }}
					</th>
					<th class="count-cell">
						<!-- TRANSLATORS: Table column holding the longest time a background job took to run, noun -->
						{{ t('serverinfo', 'Longest') }}
					</th>
					<th class="memory-cell">
						<!-- TRANSLATORS: Table column holding the most memory a background job used while running, noun -->
						{{ t('serverinfo', 'Peak memory') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="job in jobs" :key="job.className">
					<td class="job-cell" :title="job.className">
						{{ jobName(job.className) }}
					</td>
					<td class="count-cell">
						{{ job.runs }}
					</td>
					<td class="count-cell">
						{{ formatDuration(job.avgDuration) }}
					</td>
					<td class="count-cell">
						{{ formatDuration(job.maxDuration) }}
					</td>
					<td class="memory-cell">
						{{ formatKilobytes(job.memoryPeak) }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup lang="ts">
import type { SlowJob } from '../types.ts'

import { t } from '@nextcloud/l10n'
import { formatDuration, formatKilobytes, jobName } from '../utils.ts'

defineProps<{ jobs: SlowJob[] }>()
</script>

<style scoped>
/* Widths chosen so Job and Peak memory start where they do in JobRunsTable: the
   indent stands in for its status dot, the memory column absorbs its button. */
table {
	table-layout: fixed;
}

.job-cell {
	padding-inline-start: 38px;
}

.count-cell {
	width: 80px;
}

.memory-cell {
	width: 154px;
}
</style>
