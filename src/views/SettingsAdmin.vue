<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="server-info-wrapper">
		<SettingsNavigation v-model="view" />

		<!-- v-show rather than v-if: switching pages must not throw away the
		     chart history useLiveData has been accumulating since page load. -->
		<StatusView
			v-show="view === 'status'"
			:staticData="staticData"
			:liveData="liveData"
			:tick="tick" />
		<BackgroundJobsView
			v-show="view === 'background-jobs'"
			:periodicData="periodicData" />
	</div>
</template>

<script setup lang="ts">
import type { LiveData, PeriodicData, SettingsView } from '../types.ts'

import { ref } from 'vue'
import SettingsNavigation from '../components/SettingsNavigation.vue'
import BackgroundJobsView from './BackgroundJobsView.vue'
import StatusView from './StatusView.vue'
import { useLiveData } from '../composables/useLiveData.ts'
import { useStaticData } from '../composables/useStaticData.ts'

defineOptions({ name: 'ServerInfo' })

const view = ref<SettingsView>('status')

const { data: staticData } = useStaticData()
const { data: liveData, tick } = useLiveData<LiveData>('/apps/serverinfo/update')
const { data: periodicData } = useLiveData<PeriodicData>('/apps/serverinfo/periodic', 60000)
</script>
