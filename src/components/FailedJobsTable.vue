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
					<th class="failed-jobs__count">
						<!-- TRANSLATORS: Table column holding how often a background job failed or crashed, noun -->
						{{ t('serverinfo', 'Failures') }}
					</th>
					<th>
						<!-- TRANSLATORS: Table column holding when a background job failed most recently -->
						{{ t('serverinfo', 'Latest failure') }}
					</th>
					<th>
						<!-- TRANSLATORS: Table column holding the buttons that act on a background job, noun -->
						<span class="hidden-visually">{{ t('serverinfo', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="failure in failures" :key="failure.className">
					<td :title="failure.className">
						{{ jobName(failure.className) }}
					</td>
					<td class="failed-jobs__count">
						{{ failure.failures }}
					</td>
					<td>
						<NcDateTime
							:timestamp="failure.lastFailedAt * 1000"
							:format="TIMESTAMP_FORMAT"
							:relativeTime="false" />
					</td>
					<td class="failed-jobs__actions">
						<!-- Offered only where it is clear which job is meant: a class can
							 have many queued jobs, one per argument -->
						<!-- TRANSLATORS: Spoken label of the "Run at next cron" button. {job} is the name of a background job. -->
						<NcButton
							v-if="failure.jobId !== null"
							size="small"
							:aria-label="t('serverinfo', 'Run {job} at the next cron run', { job: jobName(failure.className) })"
							@click="$emit('action', { action: 'run', id: failure.jobId, className: failure.className })">
							<!-- TRANSLATORS: Button that makes a background job due so that the next cron run starts it -->
							{{ t('serverinfo', 'Run at next cron') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup lang="ts">
import type { JobActionTarget } from '../composables/useJobQueue.ts'
import type { FailedJobClass } from '../types.ts'

import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import { jobName } from '../utils.ts'

defineProps<{ failures: FailedJobClass[] }>()

defineEmits<{ action: [target: JobActionTarget] }>()

// Failure times are compared against each other more often than against now
const TIMESTAMP_FORMAT: Intl.DateTimeFormatOptions = { dateStyle: 'short', timeStyle: 'medium' }
</script>

<style scoped>
.failed-jobs__count {
	text-align: end;
}

.failed-jobs__actions {
	text-align: end;
}
</style>
