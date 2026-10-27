<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section background-jobs">
		<!-- TRANSLATORS: Section heading above the state of the background job runner, noun -->
		<SectionHeading :icon="Update" :title="t('serverinfo', 'Background jobs')" />

		<div class="row row--tiles">
			<!-- TRANSLATORS: Tile label above how the server runs background jobs: system cron, webcron or AJAX -->
			<StatTile
				:label="t('serverinfo', 'Mode')"
				:value="modeText"
				:status="cron.mode === 'ajax' ? 'warning' : undefined" />
			<!-- TRANSLATORS: Tile label above how long ago the background job runner last started -->
			<StatTile :label="t('serverinfo', 'Last run')" :status="cronStatus">
				<!-- Relative on purpose: "3 minutes ago" answers "is cron alive?" -->
				<NcDateTime v-if="cron.lastRun > 0" :timestamp="cron.lastRun * 1000" />
				<template v-else>
					<!-- TRANSLATORS: Value of the "Last run" tile when background jobs have never run on this server -->
					{{ t('serverinfo', 'Never') }}
				</template>
			</StatTile>
			<template v-if="queue">
				<!-- TRANSLATORS: Tile label above how many background jobs are in the queue -->
				<StatTile :label="t('serverinfo', 'Queued')" :value="queue.queue.total" />
				<!-- TRANSLATORS: Tile label above how many background jobs cron has reserved, which usually means they are running -->
				<StatTile :label="t('serverinfo', 'Reserved now')" :value="queue.queue.reserved" />
				<!-- TRANSLATORS: Tile label above how many background jobs have been reserved for more than 12 hours -->
				<StatTile
					:label="t('serverinfo', 'Stuck')"
					:value="queue.queue.stuck"
					:status="queue.queue.stuck > 0 ? 'warning' : undefined" />
				<!-- TRANSLATORS: Tile label above how long ago the queued background job that waited longest since its last run ran -->
				<StatTile :label="t('serverinfo', 'Oldest last run')">
					<NcDateTime v-if="queue.queue.oldestLastRun > 0" :timestamp="queue.queue.oldestLastRun * 1000" />
					<template v-else>
						<!-- TRANSLATORS: Value of the "Oldest last run" tile when no queued background job has run yet -->
						{{ t('serverinfo', 'Never') }}
					</template>
				</StatTile>
			</template>
		</div>

		<NcNoteCard v-if="queueError" type="warning">
			<!-- TRANSLATORS: Shown when the state of the background job queue could not be read -->
			{{ t('serverinfo', 'The background job queue could not be read.') }}
		</NcNoteCard>

		<template v-if="queue && queue.queue.reservedJobs.length > 0">
			<!-- TRANSLATORS: Heading above a table of the background jobs that cron has reserved, longest reserved first -->
			<h3>{{ t('serverinfo', 'Reserved jobs') }}</h3>
			<p v-if="queue.queue.reserved > queue.queue.reservedJobs.length" class="background-jobs__note">
				<!-- TRANSLATORS: Shown under the "Reserved jobs" heading when not all are listed. %n is how many are listed. -->
				{{ n('serverinfo', 'The %n job reserved longest is listed.', 'The %n jobs reserved longest are listed.', queue.queue.reservedJobs.length) }}
			</p>
			<ReservedJobsTable
				:jobs="queue.queue.reservedJobs"
				:now="queue.now"
				:stuckAfter="queue.stuckAfter"
				@action="$emit('action', $event)" />
		</template>

		<template v-if="queue">
			<h3>
				<!-- TRANSLATORS: Heading above a table of the background jobs that failed or crashed, one row per job. %n is how many days of job runs are kept. -->
				{{ n('serverinfo', 'Failures by job (last %n day)', 'Failures by job (last %n days)', jobs.retentionDays) }}
			</h3>
			<NcNoteCard v-if="queue.failures.length === 0" type="success">
				<!-- TRANSLATORS: Shown when nothing failed. %n is how many days of job runs are kept. -->
				{{ n('serverinfo', 'No background job failed in the last %n day.', 'No background job failed in the last %n days.', jobs.retentionDays) }}
			</NcNoteCard>
			<FailedJobsTable v-else :failures="queue.failures" @action="$emit('action', $event)" />
		</template>

		<!-- TRANSLATORS: Heading above a table of the background jobs that ran most recently, noun -->
		<h3>{{ t('serverinfo', 'Latest runs') }}</h3>
		<NcNoteCard v-if="jobs.recent.length === 0" type="info">
			<!-- TRANSLATORS: Shown when no background job has finished yet, e.g. on a fresh installation -->
			{{ t('serverinfo', 'No background job has run yet.') }}
		</NcNoteCard>
		<JobRunsTable v-else :runs="jobs.recent" @select="select" />

		<template v-if="queue && queue.queue.topClasses.length > 0">
			<!-- TRANSLATORS: Heading above a table of the kinds of background jobs with the most jobs in the queue -->
			<h3>{{ t('serverinfo', 'Most queued jobs') }}</h3>
			<div class="server-info-table">
				<table>
					<thead>
						<tr>
							<th>
								<!-- TRANSLATORS: Table column holding the name of a background job, noun -->
								{{ t('serverinfo', 'Job') }}
							</th>
							<th class="background-jobs__count">
								<!-- TRANSLATORS: Table column holding how many jobs of one kind are in the queue -->
								{{ t('serverinfo', 'Queued') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in queue.queue.topClasses" :key="row.class">
							<td :title="row.class">
								{{ jobName(row.class) }}
							</td>
							<td class="background-jobs__count">
								{{ row.count }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<!-- TRANSLATORS: Heading above a table of the background jobs that take the longest to run, noun -->
		<h3>{{ t('serverinfo', 'Slowest jobs') }}</h3>
		<NcNoteCard v-if="jobs.slowest.length === 0" type="info">
			<!-- TRANSLATORS: Shown before the slowest jobs have been collected for the first time -->
			{{ t('serverinfo', 'The slow jobs statistics are not available yet. They are collected by a background job and appear after its next run.') }}
		</NcNoteCard>
		<SlowJobsTable v-else :jobs="jobs.slowest" />

		<JobRunDialog
			v-if="selectedRun"
			:run="selectedRun"
			@close="selectedRun = null" />
	</div>
</template>

<script setup lang="ts">
import type { JobActionTarget } from '../composables/useJobQueue.ts'
import type { JobQueue, JobRun, PeriodicData } from '../types.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Update from 'vue-material-design-icons/Update.vue'
import FailedJobsTable from './FailedJobsTable.vue'
import JobRunDialog from './JobRunDialog.vue'
import JobRunsTable from './JobRunsTable.vue'
import ReservedJobsTable from './ReservedJobsTable.vue'
import SectionHeading from './SectionHeading.vue'
import SlowJobsTable from './SlowJobsTable.vue'
import StatTile from './StatTile.vue'
import { jobName } from '../utils.ts'

const props = defineProps<{
	cron: PeriodicData['cron']
	jobs: PeriodicData['backgroundJobs']
	/** Null until the queue has been read */
	queue: JobQueue | null
	queueError: boolean
}>()

defineEmits<{ action: [target: JobActionTarget] }>()

const selectedRun = ref<JobRun | null>(null)

/**
 * Copies the row: the minute poll replaces the list and would otherwise blank
 * an open dialog.
 *
 * @param run the row that was clicked
 */
function select(run: JobRun) {
	selectedRun.value = { ...run }
}

// Measured against the server clock the payload carries, never the browser's.
const secondsSince = computed(() => (props.cron.lastRun > 0 ? Math.max(0, props.cron.now - props.cron.lastRun) : -1))

const cronStatus = computed(() => {
	const age = secondsSince.value
	if (age < 0) {
		return 'critical'
	}
	// System cron runs every 5 minutes, so it can be held to a tighter schedule
	// than the modes that wait for a page visit.
	if (props.cron.mode === 'cron') {
		return age > 3600 ? 'critical' : age > 900 ? 'warning' : 'ok'
	}
	return age > 7200 ? 'critical' : age > 3600 ? 'warning' : 'ok'
})

const modeText = computed(() => {
	switch (props.cron.mode) {
		case 'cron':
			// TRANSLATORS: Background jobs are run by the operating system's cron daemon
			return t('serverinfo', 'System cron')
		case 'webcron':
			// TRANSLATORS: Background jobs are triggered by an external service calling a URL
			return t('serverinfo', 'Webcron')
		default:
			// TRANSLATORS: Background jobs are run by page visits, the slowest and least reliable of the three modes
			return t('serverinfo', 'AJAX (not recommended)')
	}
})

</script>

<style scoped>
.background-jobs h3 {
	font-weight: bold;
	margin: 16px 0 8px;
}

.background-jobs__note {
	color: var(--color-text-maxcontrast);
	margin-block-end: 8px;
}

.background-jobs__count {
	text-align: end;
}

</style>
