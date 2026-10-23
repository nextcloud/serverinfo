<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section os-health">
		<SectionHeading :icon="HeartPulse" :title="t('serverinfo', 'System health')" />

		<ul class="os-checklist">
			<li class="os-checklist__item">
				<OsLevelIcon :level="failedConcern?.level ?? 'unknown'" />
				<div class="os-checklist__body">
					<template v-if="status.failedUnits === null">
						<template v-if="initSystem === 'systemd'">
							<strong>{{ t('serverinfo', 'System services could not be checked') }}</strong>
							<span class="os-muted">{{ t('serverinfo', 'The web server could not ask systemd about them.') }}</span>
						</template>
						<template v-else>
							<strong>{{ t('serverinfo', 'System services are not checked') }}</strong>
							<span class="os-muted">{{ t('serverinfo', 'Failed services are only checked on systems that run systemd.') }}</span>
						</template>
					</template>
					<template v-else-if="status.failedUnits.length === 0">
						<strong>{{ t('serverinfo', 'All system services are running') }}</strong>
					</template>
					<template v-else>
						<strong>{{ n('serverinfo', '%n system service has failed', '%n system services have failed', status.failedUnits.length) }}</strong>
						<ul class="os-health__units">
							<li v-for="unit in status.failedUnits" :key="unit">
								<code>{{ unit }}</code>
								<span v-if="stackUnits.includes(unit)" class="os-tag os-tag--primary">{{ t('serverinfo', 'Nextcloud stack') }}</span>
							</li>
						</ul>
						<OsCommand command="systemctl --failed" :label="t('serverinfo', 'See the details with')" />
					</template>
				</div>
			</li>

			<li class="os-checklist__item">
				<OsLevelIcon :level="timeConcern?.level ?? 'unknown'" />
				<div class="os-checklist__body">
					<template v-if="status.timeSynchronized === null">
						<strong>{{ t('serverinfo', 'Time synchronization could not be checked') }}</strong>
					</template>
					<template v-else-if="status.timeSynchronized">
						<strong>{{ t('serverinfo', 'The system clock is synchronized') }}</strong>
						<span v-if="status.timeSource" class="os-muted">{{ status.timeSource }}</span>
					</template>
					<template v-else>
						<strong>{{ t('serverinfo', 'The system clock is not synchronized') }}</strong>
						<span class="os-muted">{{ t('serverinfo', 'Two-factor codes, federation and object storage requests fail when the clock drifts. Enable NTP, for example with systemd-timesyncd or chrony.') }}</span>
						<OsCommand v-if="initSystem === 'systemd'" command="sudo timedatectl set-ntp true" />
					</template>
				</div>
			</li>

			<li class="os-checklist__item">
				<OsLevelIcon :level="accessControlLevel" />
				<div class="os-checklist__body">
					<strong>{{ t('serverinfo', 'Mandatory access control') }}</strong>
					<span class="os-muted">{{ accessControlText }}</span>
					<span v-if="php?.confinement" class="os-muted">
						<!-- TRANSLATORS: Followed by an AppArmor profile or SELinux context name -->
						{{ t('serverinfo', 'The web server runs as') }} <code>{{ php.confinement }}</code>
					</span>
				</div>
			</li>

			<li v-if="status.livePatches !== null" class="os-checklist__item">
				<OsLevelIcon :level="status.livePatches.length ? 'ok' : 'info'" />
				<div class="os-checklist__body">
					<strong>{{ status.livePatches.length ? t('serverinfo', 'Live kernel patching is active') : t('serverinfo', 'No live kernel patches are loaded') }}</strong>
					<span v-if="status.livePatches.length" class="os-muted">{{ status.livePatches.join(', ') }}</span>
					<span v-else class="os-muted">{{ t('serverinfo', 'Kernel security fixes take effect with the next reboot.') }}</span>
				</div>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import type { OsConcern, OsLevel, OsPayload, OsStatus } from '../../types.ts'

import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import HeartPulse from 'vue-material-design-icons/HeartPulse.vue'
import SectionHeading from '../SectionHeading.vue'
import OsCommand from './OsCommand.vue'
import OsLevelIcon from './OsLevelIcon.vue'

const props = defineProps<{
	status: OsStatus
	php: OsPayload['php']
	initSystem: OsPayload['initSystem']
	failedConcern: OsConcern | null
	timeConcern: OsConcern | null
}>()

const stackUnits = computed(() => {
	const stack = props.failedConcern?.facts.stack
	return Array.isArray(stack) ? stack : []
})

const accessControlLevel = computed<OsLevel>(() => {
	if (props.status.selinux === 'enforcing' || props.status.apparmor === 'enabled') {
		return 'ok'
	}
	return props.status.selinux === null && props.status.apparmor === null ? 'unknown' : 'info'
})

const accessControlText = computed(() => {
	const parts: string[] = []
	if (props.status.selinux === 'enforcing') {
		parts.push(t('serverinfo', 'SELinux is enforcing.'))
	} else if (props.status.selinux === 'permissive') {
		parts.push(t('serverinfo', 'SELinux is permissive: violations are logged, not blocked.'))
	}
	if (props.status.apparmor === 'enabled') {
		parts.push(t('serverinfo', 'AppArmor is enabled.'))
	}
	if (parts.length === 0) {
		return props.status.selinux === null && props.status.apparmor === null
			? t('serverinfo', 'Neither SELinux nor AppArmor could be checked.')
			: t('serverinfo', 'Neither SELinux nor AppArmor is active.')
	}
	return parts.join(' ')
})
</script>

<style scoped>
.os-health__units {
	display: flex;
	flex-wrap: wrap;
	gap: 6px 12px;
	margin: 6px 0 8px;
	padding: 0;
	list-style: none;
}

.os-health__units li {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}
</style>
