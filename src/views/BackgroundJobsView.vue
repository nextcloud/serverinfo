<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="background-jobs-view">
		<BackgroundJobsSection
			v-if="periodicData"
			:cron="periodicData.cron"
			:jobs="periodicData.backgroundJobs" />
		<SectionSkeleton v-else />
	</div>
</template>

<script setup lang="ts">
import type { PeriodicData } from '../types.ts'

import BackgroundJobsSection from '../components/BackgroundJobsSection.vue'
import SectionSkeleton from '../components/SectionSkeleton.vue'

defineProps<{
	periodicData: PeriodicData | null
}>()
</script>
