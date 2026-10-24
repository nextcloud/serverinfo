<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="title"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<p class="job-action__job" :title="target.className">
			{{ jobName(target.className) }}
		</p>
		<p v-if="target.action === 'release'">
			<!-- TRANSLATORS: Explains what releasing the reservation of a stuck background job does -->
			{{ t('serverinfo', 'Cron reserves a job while it runs and removes the reservation when the job ends. This one has held its reservation for more than 12 hours, usually because the run crashed. Cron already ignores reservations that old; releasing it clears the leftover reservation. The job then runs whenever it is due.') }}
		</p>
		<p v-else>
			<!-- TRANSLATORS: Explains what scheduling a background job for the next cron run does -->
			{{ t('serverinfo', 'The job is marked as due and put first in line, and the next cron run starts it. Nothing runs right now. A job that is not time-sensitive waits for the maintenance window if one is configured.') }}
		</p>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('serverinfo', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('confirm')">
				{{ title }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { JobActionTarget } from '../composables/useJobQueue.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { jobName } from '../utils.ts'

const props = defineProps<{ target: JobActionTarget }>()

defineEmits<{ close: [], confirm: [] }>()

const title = computed(() => props.target.action === 'release'
	// TRANSLATORS: Action that clears the leftover reservation of a stuck background job
	? t('serverinfo', 'Release reservation')
	// TRANSLATORS: Action that makes a background job due so that the next cron run starts it
	: t('serverinfo', 'Run at next cron'))
</script>

<style scoped>
.job-action__job {
	font-weight: bold;
	margin-block-end: 8px;
}
</style>
