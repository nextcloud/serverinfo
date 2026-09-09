<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="server-info-wrapper">
		<NcNoteCard v-if="staticError" type="error" class="server-info-notice">
			<!-- TRANSLATORS: Shown when the server information could not be fetched at all -->
			<p>{{ t('serverinfo', 'The server information could not be loaded.') }}</p>
			<NcButton @click="reloadStatic">
				<!-- TRANSLATORS: Button that fetches the server information again after it failed -->
				{{ t('serverinfo', 'Try again') }}
			</NcButton>
		</NcNoteCard>
		<NcNoteCard v-else-if="isStale" type="warning" class="server-info-notice">
			<template v-if="liveUpdatedAt">
				<!-- TRANSLATORS: Warning that the numbers on screen have stopped refreshing. {time} is a clock time. -->
				{{ t('serverinfo', 'These values stopped updating at {time}: the server is not responding.', { time: liveUpdatedAt.toLocaleTimeString() }) }}
			</template>
			<template v-else>
				<!-- TRANSLATORS: Warning shown when the live values never arrived at all -->
				{{ t('serverinfo', 'The live values could not be loaded: the server is not responding.') }}
			</template>
		</NcNoteCard>

		<SettingsNavigation v-model="view" />

		<!-- v-show rather than v-if: switching pages must not throw away the
		     chart history useLiveData has been accumulating since page load. -->
		<StatusView
			v-show="view === 'status'"
			:staticData="staticData"
			:staticError="staticError"
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

import { t } from '@nextcloud/l10n'
import { computed, onUnmounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
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

const { data: staticData, error: staticError, reload: reloadStatic } = useStaticData()
const { data: liveData, tick, failures: liveFailures, lastUpdated: liveUpdatedAt } = useLiveData<LiveData>('/apps/serverinfo/update')
const { data: periodicData, failures: periodicFailures } = useLiveData<PeriodicData>('/apps/serverinfo/periodic', 60000)

// One failed request is usually a blip worth riding out; two in a row means the
// figures on screen have quietly stopped being current, and saying nothing
// would leave a monitoring page looking healthy while the server is not.
const isStale = computed(() => liveFailures.value >= 2 || periodicFailures.value >= 2)
</script>

<style scoped>
.server-info-notice {
	margin: 12px 0;
}
</style>
