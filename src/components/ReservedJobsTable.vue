<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="server-info-table">
		<table>
			<thead>
				<tr>
					<th>
						<!-- TRANSLATORS: Table column holding the name of a background job, noun -->
						{{ t('serverinfo', 'Job') }}
					</th>
					<th>
						<!-- TRANSLATORS: Table column holding since when a background job has been reserved by cron -->
						{{ t('serverinfo', 'Reserved since') }}
					</th>
					<th>
						<!-- TRANSLATORS: Table column holding when a background job last finished running -->
						{{ t('serverinfo', 'Last run') }}
					</th>
					<th>
						<!-- TRANSLATORS: Table column holding the buttons that act on a background job, noun -->
						<span class="hidden-visually">{{ t('serverinfo', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="job in jobs" :key="job.id">
					<td :title="job.class">
						{{ jobName(job.class) }}
						<span v-if="isStuck(job)" class="reserved-jobs__stuck">
							<!-- TRANSLATORS: Marks a background job whose reservation is older than 12 hours -->
							{{ t('serverinfo', 'stuck') }}
						</span>
					</td>
					<td>
						<NcDateTime :timestamp="job.reservedAt * 1000" />
					</td>
					<td>
						<NcDateTime v-if="job.lastRun > 0" :timestamp="job.lastRun * 1000" />
						<template v-else>
							<!-- TRANSLATORS: A background job that has not run yet -->
							{{ t('serverinfo', 'Never') }}
						</template>
					</td>
					<td>
						<div v-if="isStuck(job)" class="reserved-jobs__actions">
							<!-- TRANSLATORS: Spoken label of the "Release reservation" button. {job} is the name of a background job. -->
							<NcButton
								size="small"
								:aria-label="t('serverinfo', 'Release the reservation of {job}', { job: jobName(job.class) })"
								@click="$emit('action', target('release', job))">
								<!-- TRANSLATORS: Button that clears the leftover reservation of a stuck background job -->
								{{ t('serverinfo', 'Release reservation') }}
							</NcButton>
							<!-- TRANSLATORS: Spoken label of the "Run at next cron" button. {job} is the name of a background job. -->
							<NcButton
								size="small"
								:aria-label="t('serverinfo', 'Run {job} at the next cron run', { job: jobName(job.class) })"
								@click="$emit('action', target('run', job))">
								<!-- TRANSLATORS: Button that makes a background job due so that the next cron run starts it -->
								{{ t('serverinfo', 'Run at next cron') }}
							</NcButton>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup lang="ts">
import type { JobAction, JobActionTarget } from '../composables/useJobQueue.ts'
import type { ReservedJob } from '../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import { jobName } from '../utils.ts'

const props = defineProps<{
	jobs: ReservedJob[]
	/** Server clock, so the threshold never depends on the browser's */
	now: number
	/** Seconds after which a reservation counts as stuck */
	stuckAfter: number
}>()

defineEmits<{ action: [target: JobActionTarget] }>()

/**
 * Only a stuck job offers actions: a job reserved more recently may be
 * running, and the server refuses to touch it.
 *
 * @param job a row of the table
 */
function isStuck(job: ReservedJob): boolean {
	return job.reservedAt < props.now - props.stuckAfter
}

/**
 * @param action what the button does
 * @param job the row the button belongs to
 */
function target(action: JobAction, job: ReservedJob): JobActionTarget {
	return { action, id: job.id, className: job.class }
}
</script>

<style scoped>
.reserved-jobs__actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 6px;
}

.reserved-jobs__stuck {
	margin-inline-start: 6px;
	color: var(--color-warning-text, var(--color-warning));
	font-weight: bold;
}
</style>
