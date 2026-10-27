<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="background-jobs-view">
		<BackgroundJobsSection
			v-if="periodicData"
			:cron="periodicData.cron"
			:jobs="periodicData.backgroundJobs"
			:queue="queue"
			:queueError="queueError"
			@action="pending = $event" />
		<SectionSkeleton v-else />

		<JobActionDialog
			v-if="pending"
			:target="pending"
			@close="pending = null"
			@confirm="confirm" />
	</div>
</template>

<script setup lang="ts">
import type { JobActionTarget } from '../composables/useJobQueue.ts'
import type { PeriodicData } from '../types.ts'

import { ref, watch } from 'vue'
import BackgroundJobsSection from '../components/BackgroundJobsSection.vue'
import JobActionDialog from '../components/JobActionDialog.vue'
import SectionSkeleton from '../components/SectionSkeleton.vue'
import { useJobQueue } from '../composables/useJobQueue.ts'

const props = defineProps<{
	periodicData: PeriodicData | null
	active: boolean
}>()

const { data: queue, error: queueError, loading, reload, act } = useJobQueue()
const pending = ref<JobActionTarget | null>(null)

// The page is kept mounted when another tab is shown, so the queue is read
// when this tab becomes visible and again with each minute poll while it is.
// It is shown inside the section that waits for the poll, so it waits too.
watch(() => [props.active, props.periodicData] as const, ([active, periodicData]) => {
	if (active && periodicData !== null && !loading.value) {
		reload()
	}
}, { immediate: true })

/**
 * Closes the dialog first: the password prompt opens on top of it otherwise.
 */
async function confirm() {
	const target = pending.value
	pending.value = null
	if (target) {
		await act(target)
	}
}
</script>
