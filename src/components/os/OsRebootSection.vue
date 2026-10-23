<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section os-reboot">
		<SectionHeading :icon="Restart" :title="t('serverinfo', 'Reboot and restarts')" />

		<ul class="os-checklist">
			<!-- Reboot -->
			<li v-if="showReboot" class="os-checklist__item">
				<OsLevelIcon :level="rebootConcern?.level ?? 'unknown'" />
				<div class="os-checklist__body">
					<template v-if="status.rebootReasons === null">
						<strong>{{ t('serverinfo', 'Whether a reboot is required could not be determined') }}</strong>
					</template>
					<template v-else-if="status.rebootReasons.length === 0">
						<strong>{{ rebootConcern?.title }}</strong>
						<span v-if="status.bootTime" class="os-muted">
							<!-- TRANSLATORS: Followed by a relative time such as "3 days ago" -->
							{{ t('serverinfo', 'Last boot') }}
							<NcDateTime :timestamp="status.bootTime * 1000" ignoreSeconds />
						</span>
					</template>
					<template v-else>
						<strong>{{ rebootConcern?.title }}</strong>
						<span v-if="rebootConcern?.detail" class="os-muted">{{ rebootConcern.detail }}</span>
						<ul v-if="specifics.length" class="os-reasons">
							<li v-for="specific in specifics" :key="specific">
								<code>{{ specific }}</code>
							</li>
						</ul>
						<template v-if="status.environment.kind === 'host'">
							<OsCommand v-if="commands.reboot" :command="commands.reboot" />
							<p v-else class="os-muted">
								{{ t('serverinfo', 'Reboot the machine to apply the updates.') }}
							</p>
						</template>
						<p v-else class="os-muted">
							{{ t('serverinfo', 'This system runs in a container: restart the container to apply the update.') }}
						</p>
					</template>
				</div>
			</li>

			<!-- PHP itself -->
			<li class="os-checklist__item">
				<OsLevelIcon :level="phpLevel" />
				<div class="os-checklist__body">
					<template v-if="php === null">
						<strong>{{ t('serverinfo', 'The PHP processes could not be checked') }}</strong>
						<span class="os-muted">{{ t('serverinfo', '/proc/self/maps is not readable for the web server.') }}</span>
					</template>
					<template v-else-if="php.outdatedLibraries.length === 0">
						<strong>{{ t('serverinfo', 'PHP runs with current libraries') }}</strong>
						<span class="os-muted">{{ t('serverinfo', 'No library used by the PHP serving Nextcloud was replaced since it started.') }}</span>
					</template>
					<template v-else>
						<strong>{{ t('serverinfo', 'PHP is running outdated libraries') }}</strong>
						<span class="os-muted">
							{{ t('serverinfo', 'These were updated on disk, but PHP still has the old versions loaded:') }}
							<code>{{ php.outdatedLibraries.slice(0, 8).join(', ') }}</code>
						</span>
						<OsCommand v-if="commands.php" :command="commands.php" />
					</template>
				</div>
			</li>

			<!-- Other services, from the root helper -->
			<li v-if="managesPackages" class="os-checklist__item">
				<OsLevelIcon :level="servicesLevel" />
				<div class="os-checklist__body">
					<template v-if="status.restartServices === null">
						<strong>{{ t('serverinfo', 'Other services are not checked') }}</strong>
						<span class="os-muted">
							{{ t('serverinfo', 'Which other services still run replaced code is only visible to root.') }}
							<NcButton variant="tertiary-no-background" class="os-inline-button" @click="$emit('helper')">
								{{ t('serverinfo', 'Set up the root helper') }}
							</NcButton>
						</span>
					</template>
					<template v-else-if="status.restartServices.length === 0">
						<strong>{{ t('serverinfo', 'No service needs a restart') }}</strong>
					</template>
					<template v-else>
						<strong>{{ n('serverinfo', '%n service needs a restart', '%n services need a restart', status.restartServices.length) }}</strong>
						<ul class="os-services">
							<li v-for="service in sortedServices" :key="service.unit">
								<OsCommand
									v-if="commands.services[service.unit]"
									:command="commands.services[service.unit]!"
									:label="service.stack ? t('serverinfo', 'Nextcloud stack') : undefined" />
								<template v-else>
									<code>{{ service.unit }}</code>
									<span v-if="service.stack" class="os-tag os-tag--primary">{{ t('serverinfo', 'Nextcloud stack') }}</span>
								</template>
							</li>
						</ul>
					</template>
				</div>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import type { OsConcern, OsLevel, OsPayload, OsStatus } from '../../types.ts'

import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import Restart from 'vue-material-design-icons/Restart.vue'
import SectionHeading from '../SectionHeading.vue'
import OsCommand from './OsCommand.vue'
import OsLevelIcon from './OsLevelIcon.vue'

const props = defineProps<{
	status: OsStatus
	php: OsPayload['php']
	commands: OsPayload['commands']
	rebootConcern: OsConcern | null
	restartConcern: OsConcern | null
}>()

defineEmits<{ helper: [] }>()

const managesPackages = computed(() => props.status.environment.kind === 'host' || props.status.environment.kind === 'system-container')
const showReboot = computed(() => managesPackages.value)

/** What the reasons are about: the newer kernel, the updated packages */
const specifics = computed(() => (props.status.rebootReasons ?? [])
	.map((reason) => [reason.detail, ...reason.packages].filter((part) => part).join(', '))
	.filter((specific) => specific !== ''))

const phpLevel = computed<OsLevel>(() => {
	if (props.php === null) {
		return 'unknown'
	}
	return props.php.outdatedLibraries.length === 0 ? 'ok' : (props.restartConcern?.level ?? 'warning')
})

const servicesLevel = computed<OsLevel>(() => {
	const services = props.status.restartServices
	if (services === null) {
		return 'unknown'
	}
	return services.length === 0 ? 'ok' : (props.restartConcern?.level ?? 'info')
})

const sortedServices = computed(() => [...(props.status.restartServices ?? [])]
	.sort((a, b) => Number(b.stack) - Number(a.stack) || a.unit.localeCompare(b.unit)))

</script>

<style scoped>
.os-reasons {
	margin: 4px 0 8px;
	padding-inline-start: 20px;
	list-style: disc;
}

.os-services {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
}

.os-inline-button {
	display: inline-flex;
	vertical-align: middle;
}
</style>
