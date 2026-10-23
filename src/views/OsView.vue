<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="os-view">
		<SectionSkeleton v-if="loading && !data" />

		<div v-else-if="error && !data" class="section">
			<NcNoteCard type="error">
				<p>{{ t('serverinfo', 'The operating system status could not be loaded.') }}</p>
				<NcButton @click="load">
					{{ t('serverinfo', 'Try again') }}
				</NcButton>
			</NcNoteCard>
		</div>

		<template v-else-if="data">
			<div class="section os-view__top">
				<NcEmptyContent
					v-if="!status"
					:name="t('serverinfo', 'Not checked yet')"
					:description="t('serverinfo', 'The operating system is checked once an hour by the background jobs when they run from system cron. You can also check it right now.')">
					<template #icon>
						<ServerSecurity />
					</template>
					<template #action>
						<NcButton variant="primary" :disabled="refreshing || !data.refresh.systemCron" @click="refresh">
							<template #icon>
								<NcLoadingIcon v-if="refreshing" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							{{ refreshing ? t('serverinfo', 'Checking…') : t('serverinfo', 'Check now') }}
						</NcButton>
					</template>
				</NcEmptyContent>

				<NcNoteCard v-else-if="status.state === 'restricted'" type="info">
					{{ t('serverinfo', 'The Monitoring app runs in restricted mode, so the operating system is not inspected.') }}
				</NcNoteCard>

				<NcNoteCard v-else-if="status.state === 'unsupported'" type="info">
					{{ t('serverinfo', 'Only Linux systems are checked. This server runs {os}.', { os: status.kernel }) }}
				</NcNoteCard>

				<template v-else>
					<OsVerdictCard
						:level="data.verdict?.level ?? 'unknown'"
						:status="status"
						:concerns="problems(data)"
						:refreshing="refreshing"
						:canRefresh="data.refresh.systemCron"
						@refresh="refresh"
						@settings="settingsOpen = true" />

					<NcNoteCard v-if="data.outdated" type="info" class="os-view__notice">
						{{ t('serverinfo', 'Packages were installed or removed since the last check, so some of this may already be resolved.') }}
						<NcButton variant="tertiary-no-background" :disabled="refreshing || !data.refresh.systemCron" @click="refresh">
							{{ t('serverinfo', 'Check now') }}
						</NcButton>
					</NcNoteCard>
				</template>

				<NcNoteCard v-if="refreshing" type="info" class="os-view__notice">
					{{ t('serverinfo', 'The check runs with the next background jobs, usually within a few minutes. This page updates when it is done.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="(!status || status.state === 'ok') && (!data.refresh.systemCron || refreshError === 'no-cron')" type="info" class="os-view__notice">
					{{ t('serverinfo', '"Check now" needs background jobs to run from system cron. Until then, run "occ serverinfo:os-status --refresh" on the server.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="refreshError === 'rate-limited'" type="warning" class="os-view__notice">
					{{ t('serverinfo', 'The operating system was checked several times in the last minutes. Try again a little later.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="refreshError === 'slow'" type="warning" class="os-view__notice">
					{{ t('serverinfo', 'The check has not finished yet. Check that the background jobs run, then reload this page.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="refreshError === 'failed'" type="error" class="os-view__notice">
					{{ t('serverinfo', 'The check failed. The Nextcloud log has the details.') }}
				</NcNoteCard>
			</div>

			<template v-if="status && status.state === 'ok'">
				<OsUpdatesSection
					v-if="managesPackages"
					:status="status"
					:concern="concernOf(data, 'updates')"
					:now="data.now"
					@helper="helperOpen = true" />

				<div class="section">
					<div class="row os-view__columns">
						<OsRebootSection
							class="os-view__column"
							:status="status"
							:php="data.php"
							:rebootConcern="concernOf(data, 'reboot')"
							:restartConcern="concernOf(data, 'restart')"
							@helper="helperOpen = true" />
						<OsHealthSection
							class="os-view__column"
							:status="status"
							:php="data.php"
							:failedConcern="concernOf(data, 'failed-units')"
							:timeConcern="concernOf(data, 'time-sync')" />
					</div>
				</div>

				<OsReleaseSection
					:status="status"
					:supportConcern="concernOf(data, 'support')"
					:automaticConcern="concernOf(data, 'automatic-updates')" />

				<OsHelperSection
					v-if="managesPackages"
					:helper="status.helper"
					@setup="helperOpen = true" />

				<div v-if="status.diagnostics.length" class="section">
					<details class="os-view__diagnostics">
						<summary>
							{{ n('serverinfo', '%n diagnostic message', '%n diagnostic messages', status.diagnostics.length) }}
						</summary>
						<ul>
							<li v-for="message in status.diagnostics" :key="message">
								{{ message }}
							</li>
						</ul>
					</details>
				</div>
			</template>
		</template>

		<OsSettingsDialog
			v-if="settingsOpen && data"
			:thresholds="data.thresholds"
			:notifications="data.notifications"
			@saved="data = $event"
			@close="settingsOpen = false" />
		<OsHelperDialog
			v-if="helperOpen && data"
			:helper="data.helper"
			@close="helperOpen = false" />
	</div>
</template>

<script setup lang="ts">
import { n, t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ServerSecurity from 'vue-material-design-icons/ServerSecurity.vue'
import OsHealthSection from '../components/os/OsHealthSection.vue'
import OsHelperDialog from '../components/os/OsHelperDialog.vue'
import OsHelperSection from '../components/os/OsHelperSection.vue'
import OsRebootSection from '../components/os/OsRebootSection.vue'
import OsReleaseSection from '../components/os/OsReleaseSection.vue'
import OsSettingsDialog from '../components/os/OsSettingsDialog.vue'
import OsUpdatesSection from '../components/os/OsUpdatesSection.vue'
import OsVerdictCard from '../components/os/OsVerdictCard.vue'
import SectionSkeleton from '../components/SectionSkeleton.vue'
import { useOsStatus } from '../composables/useOsStatus.ts'
import { concernOf, problems } from '../osUtils.ts'

const props = defineProps<{ active: boolean }>()

const { data, loading, refreshing, error, refreshError, load, refresh } = useOsStatus()

const settingsOpen = ref(false)
const helperOpen = ref(false)

const status = computed(() => data.value?.status ?? null)
const managesPackages = computed(() => status.value?.environment.kind === 'host' || status.value?.environment.kind === 'system-container')

// Kept mounted like the other pages, so the first time it becomes visible is
// the trigger to load it.
watch(() => props.active, (active) => {
	if (active && data.value === null && !loading.value) {
		load()
	}
}, { immediate: true })
</script>

<style scoped>
.os-view__notice {
	margin-block-start: 12px;
}

.os-view__columns {
	align-items: start;
}

/* The sections bring their own .section padding and divider; inside the
   two-column row the surrounding section already has both. */
.os-view__column.section {
	padding: 0;
	border: none;
}

.os-view__diagnostics summary {
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.os-view__diagnostics ul {
	margin: 8px 0 0;
	padding-inline-start: 20px;
	list-style: disc;
}
</style>
