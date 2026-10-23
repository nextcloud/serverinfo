<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section os-helper">
		<SectionHeading :icon="ShieldLock" :title="t('serverinfo', 'Root helper')" />

		<div class="os-checklist__item">
			<OsLevelIcon :level="level" />
			<div class="os-checklist__body">
				<strong>{{ title }}</strong>
				<span class="os-muted">{{ description }}</span>
				<ul v-if="helper.errors.length" class="os-helper__errors">
					<li v-for="error in helper.errors" :key="error">
						{{ error }}
					</li>
				</ul>
			</div>
			<NcButton class="os-helper__button" :variant="helper.state === 'absent' ? 'secondary' : 'tertiary'" @click="$emit('setup')">
				<template #icon>
					<Console :size="20" />
				</template>
				{{ helper.state === 'absent' ? t('serverinfo', 'Set up') : t('serverinfo', 'Setup instructions') }}
			</NcButton>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { OsLevel, OsStatus } from '../../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import Console from 'vue-material-design-icons/Console.vue'
import ShieldLock from 'vue-material-design-icons/ShieldLock.vue'
import SectionHeading from '../SectionHeading.vue'
import OsLevelIcon from './OsLevelIcon.vue'
import { PLAIN } from '../../osUtils.ts'

const props = defineProps<{
	helper: OsStatus['helper']
}>()

defineEmits<{ setup: [] }>()

const level = computed<OsLevel>(() => {
	switch (props.helper.state) {
		case 'ok':
			return props.helper.outdated || props.helper.errors.length ? 'info' : 'ok'
		case 'absent':
			return 'unknown'
		case 'insecure':
			return 'warning'
		default:
			return 'info'
	}
})

const title = computed(() => {
	switch (props.helper.state) {
		case 'ok':
			return props.helper.outdated
				? t('serverinfo', 'Active, but a newer version is available')
				: t('serverinfo', 'Active')
		case 'absent':
			return t('serverinfo', 'Not set up')
		case 'stale':
			return t('serverinfo', 'The report is out of date')
		case 'clock-skew':
			return t('serverinfo', 'The report is dated in the future')
		case 'previous-boot':
			return t('serverinfo', 'The report is from before the last reboot')
		case 'insecure':
			return t('serverinfo', 'The report is ignored because others could change it')
		case 'unreadable':
			return t('serverinfo', 'The report cannot be read')
		case 'other-host':
			return t('serverinfo', 'The report is from a different machine')
		case 'unsupported':
			return t('serverinfo', 'The report is in a format this app does not know')
		default:
			return t('serverinfo', 'The report is not valid')
	}
})

const description = computed(() => {
	const path = props.helper.path
	switch (props.helper.state) {
		case 'ok':
			return props.helper.outdated
				? t('serverinfo', 'The helper installed on this system is older than the one shipped with this app. Install it again to update it.')
				: t('serverinfo', 'A script run by root reports which services need a restart and keeps the package lists fresh.')
		case 'absent':
			return t('serverinfo', 'Optional. A small script run by root adds what the web server cannot see: which services need a restart to load updated libraries. It also keeps the package lists fresh.')
		case 'stale':
			return t('serverinfo', 'The helper has not written a report for more than a day, so it is not used. Check that its cron job still runs.')
		case 'clock-skew':
			return t('serverinfo', 'The server clock was set back since the helper wrote it, so it is not used. Check that the system clock is synchronized.')
		case 'previous-boot':
			return t('serverinfo', 'Its findings do not apply after a reboot. It is used again after the helper\'s next run.')
		case 'insecure':
			return t('serverinfo', '{path} or its directory is not owned by root or can be written by other users, which would let them forge it.', { path }, PLAIN)
		case 'unreadable':
			return t('serverinfo', '{path} exists, but the web server may not read it. open_basedir may exclude it.', { path }, PLAIN)
		case 'other-host':
			return t('serverinfo', '{path} names a different host. Run the helper on this machine.', { path }, PLAIN)
		case 'unsupported':
			return t('serverinfo', 'Install the helper again from this version of the app.')
		default:
			return t('serverinfo', '{path} is not a report the helper wrote. Run the helper again.', { path }, PLAIN)
	}
})
</script>

<style scoped>
.os-helper .os-checklist__item {
	align-items: center;
}

/* The explanation beside it is long; the button keeps its label whole. */
.os-helper__button {
	flex: 0 0 auto;
}

.os-helper__errors {
	margin: 4px 0 0;
	padding-inline-start: 20px;
	list-style: disc;
	color: var(--color-text-maxcontrast);
}
</style>
