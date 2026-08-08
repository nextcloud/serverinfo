<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="jobName(run.className)"
		size="normal"
		@closing="$emit('close')">
		<dl class="job-details">
			<!-- TRANSLATORS: Label of the fully qualified PHP class name of a background job, noun -->
			<dt>{{ t('serverinfo', 'Class') }}</dt>
			<dd class="job-details__class">
				{{ run.className }}
			</dd>

			<!-- TRANSLATORS: Label of whether a background job succeeded, failed or crashed, noun -->
			<dt>{{ t('serverinfo', 'Status') }}</dt>
			<dd><JobStatusIndicator :status="run.status" /></dd>

			<!-- TRANSLATORS: Label of the moment a background job began running, noun -->
			<dt>{{ t('serverinfo', 'Started') }}</dt>
			<dd>
				<NcDateTime
					:timestamp="run.startedAt * 1000"
					:format="TIMESTAMP_FORMAT"
					:relativeTime="false" />
			</dd>

			<!-- TRANSLATORS: Label of how long a background job took to run, noun -->
			<dt>{{ t('serverinfo', 'Duration') }}</dt>
			<dd>{{ formatDuration(run.duration) }}</dd>

			<!-- TRANSLATORS: Label of the most memory a background job used while running, noun -->
			<dt>{{ t('serverinfo', 'Peak memory') }}</dt>
			<dd>{{ formatKilobytes(run.memoryPeak) }}</dd>

			<!-- TRANSLATORS: Label of the identifier of a single background job run, noun -->
			<dt>{{ t('serverinfo', 'Run ID') }}</dt>
			<dd>{{ run.runId }}</dd>

			<!-- TRANSLATORS: Label of which server of a cluster ran the background job, noun -->
			<dt>{{ t('serverinfo', 'Server ID') }}</dt>
			<dd>{{ run.serverId }}</dd>

			<!-- TRANSLATORS: Label of the operating system process number the job ran under, noun -->
			<dt>{{ t('serverinfo', 'Process ID') }}</dt>
			<dd>{{ run.pid }}</dd>
		</dl>
	</NcDialog>
</template>

<script setup lang="ts">
import type { JobRun } from '../types.ts'

import { t } from '@nextcloud/l10n'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import JobStatusIndicator from './JobStatusIndicator.vue'
import { formatDuration, formatKilobytes, jobName } from '../utils.ts'

// A copy taken when the row was clicked, not a reference into the polled list
defineProps<{ run: JobRun }>()

defineEmits<{ close: [] }>()

// Exact values only, so no relative times here either
const TIMESTAMP_FORMAT: Intl.DateTimeFormatOptions = { dateStyle: 'long', timeStyle: 'medium' }
</script>

<style scoped>
.job-details {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 8px 16px;
	padding: 0;
	margin: 0;
}

/* Core styles definition lists for prose (dt{width:130px}) */
.job-details dt {
	width: auto;
	padding: 0;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.job-details dd {
	min-width: 0;
	padding: 0;
	margin: 0;
	overflow-wrap: break-word;
}

.job-details__class {
	font-family: var(--font-face-monospace, monospace);
	user-select: all;
}
</style>
