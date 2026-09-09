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
		<DatabaseView v-show="view === 'database'" :active="view === 'database'" />
		<BackgroundJobsView
			v-show="view === 'background-jobs'"
			:periodicData="periodicData" />
	</div>
</template>

<script setup lang="ts">
import type { LiveData, PeriodicData, SettingsView } from '../types.ts'

import { onUnmounted, ref, watch } from 'vue'
import SettingsNavigation from '../components/SettingsNavigation.vue'
import BackgroundJobsView from './BackgroundJobsView.vue'
import DatabaseView from './DatabaseView.vue'
import StatusView from './StatusView.vue'
import { useLiveData } from '../composables/useLiveData.ts'
import { useStaticData } from '../composables/useStaticData.ts'

defineOptions({ name: 'ServerInfo' })

const VIEWS: SettingsView[] = ['status', 'database', 'background-jobs']

/**
 * Reads the page out of the URL fragment so that a link can point straight at
 * one — the setup check on Settings > Overview links to #database. Anything we
 * do not recognise leaves the default alone rather than showing a blank page.
 */
function viewFromHash(): SettingsView {
	const fromHash = window.location.hash.replace(/^#/, '') as SettingsView

	return VIEWS.includes(fromHash) ? fromHash : 'status'
}

const view = ref<SettingsView>(viewFromHash())

// replaceState, not a hash assignment: switching pages should not fill the
// browser's back button with entries inside one settings section.
watch(view, (current) => {
	window.history.replaceState(null, '', `#${current}`)
})

/**
 * Keeps the page in step with the fragment when the browser changes it, for
 * instance on back/forward. Named so it can be removed again.
 */
function onHashChange() {
	view.value = viewFromHash()
}

window.addEventListener('hashchange', onHashChange)

onUnmounted(() => {
	window.removeEventListener('hashchange', onHashChange)
})

const { data: staticData } = useStaticData()
const { data: liveData, tick } = useLiveData<LiveData>('/apps/serverinfo/update')
const { data: periodicData } = useLiveData<PeriodicData>('/apps/serverinfo/periodic', 60000)
</script>
