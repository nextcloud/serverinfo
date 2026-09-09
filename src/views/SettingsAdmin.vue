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
		<BackgroundJobsView
			v-show="view === 'background-jobs'"
			:periodicData="periodicData" />
	</div>
</template>

<script setup lang="ts">
import type { LiveData, PeriodicData, SettingsView } from '../types.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import SettingsNavigation from '../components/SettingsNavigation.vue'
import BackgroundJobsView from './BackgroundJobsView.vue'
import StatusView from './StatusView.vue'
import { useLiveData } from '../composables/useLiveData.ts'
import { useStaticData } from '../composables/useStaticData.ts'

defineOptions({ name: 'ServerInfo' })

const view = ref<SettingsView>('status')

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
