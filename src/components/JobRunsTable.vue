<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="server-info-table">
		<table>
			<thead>
				<tr>
					<th class="status-cell">
						<!-- TRANSLATORS: Table column holding whether a background job succeeded, failed or crashed, noun -->
						<span class="hidden-visually">{{ t('serverinfo', 'Status') }}</span>
					</th>
					<th>
						<!-- TRANSLATORS: Table column holding the name of a background job, noun -->
						{{ t('serverinfo', 'Job') }}
					</th>
					<th class="when-cell">
						<!-- TRANSLATORS: Table column holding the date and time a background job ran, noun -->
						{{ t('serverinfo', 'When') }}
					</th>
					<th class="duration-cell">
						<!-- TRANSLATORS: Table column holding how long a background job took to run, noun -->
						{{ t('serverinfo', 'Duration') }}
					</th>
					<th class="memory-cell">
						<!-- TRANSLATORS: Table column holding the most memory a background job used while running, noun -->
						{{ t('serverinfo', 'Peak memory') }}
					</th>
					<th class="info-cell">
						<!-- TRANSLATORS: Table column holding a button that opens the details of a job run, noun -->
						<span class="hidden-visually">{{ t('serverinfo', 'Details') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="run in runs" :key="run.runId">
					<td class="status-cell">
						<JobStatusIndicator :status="run.status" dotOnly />
					</td>
					<td :title="run.className">
						{{ jobName(run.className) }}
					</td>
					<td>
						<NcDateTime
							:timestamp="run.startedAt * 1000"
							:format="TIMESTAMP_FORMAT"
							:relativeTime="false" />
					</td>
					<td>{{ formatDuration(run.duration) }}</td>
					<td>{{ formatKilobytes(run.memoryPeak) }}</td>
					<td class="info-cell">
						<NcButton
							variant="tertiary"
							size="small"
							:aria-label="detailsLabel(run)"
							:title="detailsLabel(run)"
							@click="$emit('select', run)">
							<template #icon>
								<InformationOutline :size="20" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup lang="ts">
import type { JobRun } from '../types.ts'

import { getCanonicalLocale, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import JobStatusIndicator from './JobStatusIndicator.vue'
import { formatDuration, formatKilobytes, jobName } from '../utils.ts'

defineProps<{ runs: JobRun[] }>()

defineEmits<{ select: [run: JobRun] }>()

// Run times are compared against each other more often than against now
const TIMESTAMP_FORMAT: Intl.DateTimeFormatOptions = { dateStyle: 'short', timeStyle: 'medium' }

/**
 * Every row shows the same icon, and the same job class can appear twice, so the
 * name alone would leave two buttons indistinguishable.
 *
 * @param run the row the button belongs to
 */
function detailsLabel(run: JobRun): string {
	// TRANSLATORS: Label of a button that opens the details of a background job run. {job} is the name of the job, {time} when it ran.
	return t('serverinfo', 'Details about {job} from {time}', {
		job: jobName(run.className),
		time: new Date(run.startedAt * 1000).toLocaleString(getCanonicalLocale(), TIMESTAMP_FORMAT),
	})
}
</script>

<style scoped>
/* Fixed layout so the failures and latest runs tables line up column for column
   despite holding different rows. */
table {
	table-layout: fixed;
}

.status-cell {
	width: 32px;
}

.when-cell {
	width: 180px;
}

.duration-cell {
	width: 90px;
}

.memory-cell {
	width: 110px;
}

/* A normal-size button would set a 44px row height across three tables */
.info-cell {
	width: 44px;
	padding: 0;
}
</style>
