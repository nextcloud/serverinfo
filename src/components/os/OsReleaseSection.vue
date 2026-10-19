<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section os-release">
		<SectionHeading :icon="CalendarClock" :title="t('serverinfo', 'Release and maintenance')" />

		<NcNoteCard v-if="status.environment.kind === 'application-container' || status.environment.kind === 'snap'" type="info">
			<p>
				{{ status.environment.kind === 'snap'
					? t('serverinfo', 'Nextcloud runs as a snap. Its packages are updated together with the snap, and updates and reboots of the machine are not visible from inside it.')
					: t('serverinfo', 'Nextcloud runs in a container. Its packages are updated by pulling a new image and recreating the container, and updates and reboots of the host are not visible from inside it.') }}
			</p>
			<p v-if="status.environment.kind !== 'snap'">
				{{ t('serverinfo', 'With Nextcloud All-in-One, updating the containers in the AIO interface does this.') }}
			</p>
		</NcNoteCard>

		<div class="row os-release__grid">
			<dl class="os-facts">
				<dt>{{ t('serverinfo', 'Distribution') }}</dt>
				<dd>{{ status.release?.prettyName ?? t('serverinfo', 'Unknown') }}</dd>

				<template v-if="status.release?.codename">
					<dt>{{ t('serverinfo', 'Code name') }}</dt>
					<dd>{{ status.release.codename }}</dd>
				</template>

				<dt>{{ t('serverinfo', 'Kernel') }}</dt>
				<dd><code>{{ status.kernel }}</code> <span class="os-muted">{{ status.architecture }}</span></dd>

				<dt>{{ t('serverinfo', 'Runs on') }}</dt>
				<dd>{{ environmentLabel(status.environment) }}</dd>

				<dt>{{ t('serverinfo', 'Security support') }}</dt>
				<dd class="os-release__support">
					<OsLevelIcon :level="supportConcern?.level ?? 'unknown'" :size="18" />
					<span>{{ supportText }}</span>
				</dd>
			</dl>

			<dl v-if="managesPackages" class="os-facts">
				<dt>{{ t('serverinfo', 'Automatic updates') }}</dt>
				<dd class="os-release__support">
					<OsLevelIcon :level="automaticLevel" :size="18" />
					<span>{{ automaticText }}</span>
				</dd>

				<template v-if="status.listsRefreshedAt">
					<dt>{{ t('serverinfo', 'Package lists') }}</dt>
					<dd>
						<!-- TRANSLATORS: Followed by a relative time such as "4 hours ago" -->
						{{ t('serverinfo', 'Refreshed') }}
						<NcDateTime :timestamp="status.listsRefreshedAt * 1000" ignoreSeconds />
					</dd>
				</template>

				<template v-if="status.lastUpgradeAt">
					<dt>{{ t('serverinfo', 'Last upgrade') }}</dt>
					<dd><NcDateTime :timestamp="status.lastUpgradeAt * 1000" ignoreSeconds /></dd>
				</template>

				<dt>{{ t('serverinfo', 'Held packages') }}</dt>
				<dd>
					<template v-if="status.held === null">
						{{ t('serverinfo', 'Unknown') }}
					</template>
					<template v-else-if="status.held.length === 0">
						{{ t('serverinfo', 'None') }}
					</template>
					<ul v-else class="os-release__held">
						<li v-for="item in status.held" :key="item.name" :title="item.mechanism">
							<code>{{ item.name }}{{ item.constraint ?? '' }}</code>
						</li>
					</ul>
				</dd>
			</dl>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { OsConcern, OsLevel, OsStatus } from '../../types.ts'

import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import SectionHeading from '../SectionHeading.vue'
import OsLevelIcon from './OsLevelIcon.vue'
import { environmentLabel, factNumber, formatDay, PROGRAMMES } from '../../osUtils.ts'

const props = defineProps<{
	status: OsStatus
	supportConcern: OsConcern | null
	automaticConcern: OsConcern | null
}>()

const managesPackages = computed(() => props.status.environment.kind === 'host' || props.status.environment.kind === 'system-container')

const supportText = computed(() => {
	const facts = props.supportConcern?.facts ?? {}
	const support = props.status.support
	const programme = PROGRAMMES[support.extended ?? ''] ?? ''
	const daysLeft = factNumber(facts.daysLeft)
	const ends = formatDay(typeof facts.ends === 'string' ? facts.ends : null)

	switch (facts.state) {
		case 'rolling':
			return t('serverinfo', 'Rolling release: always supported while it is kept up to date')
		case 'ended':
			// TRANSLATORS: {date} is a date such as "31 May 2029"
			return t('serverinfo', 'Ended on {date}. This release no longer receives security updates.', { date: ends })
		case 'extended-available':
			return t('serverinfo', 'Standard support ended on {date}. Security updates continue until {extendedDate} only with {programme}.', {
				date: formatDay(support.securityEnds),
				extendedDate: formatDay(support.extendedEnds),
				programme,
			})
		case 'extended':
			return t('serverinfo', 'Covered by {programme} until {date}', { programme, date: ends })
		case 'supported':
			if (daysLeft !== null && daysLeft < 365) {
				return n('serverinfo', 'Until {date}, in %n day', 'Until {date}, in %n days', daysLeft, { date: ends })
			}
			return t('serverinfo', 'Until {date}', { date: ends })
		default:
			return props.status.support.dataDate
				// TRANSLATORS: {date} is when the support data bundled with the app was collected
				? t('serverinfo', 'Unknown: this release is not in the support data from {date}', { date: formatDay(props.status.support.dataDate) })
				: t('serverinfo', 'Unknown')
	}
})

const automaticLevel = computed<OsLevel>(() => props.automaticConcern?.level ?? 'unknown')

const automaticText = computed(() => {
	const automatic = props.status.automaticUpdates
	const tool = automatic.mechanism ?? ''
	if (automatic.state === 'enabled') {
		return automatic.scope === 'security'
			// TRANSLATORS: {tool} is a program such as unattended-upgrades or dnf-automatic
			? t('serverinfo', 'Security updates are installed automatically by {tool}', { tool })
			: t('serverinfo', 'Updates are installed automatically by {tool}', { tool })
	}
	if (automatic.state === 'disabled') {
		return automatic.scope === 'download'
			? t('serverinfo', 'Updates are downloaded automatically, but not installed')
			: t('serverinfo', 'Off: updates are only installed by hand')
	}
	return t('serverinfo', 'Unknown')
})
</script>

<style scoped>
.os-release__grid {
	align-items: start;
	margin-block-start: 8px;
}

.os-release__support {
	display: flex;
	align-items: flex-start;
	gap: 6px;
}

.os-release__held {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}
</style>
