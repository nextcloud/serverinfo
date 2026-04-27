<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconLinux from 'vue-material-design-icons/Linux.vue'
import IconShield from 'vue-material-design-icons/ShieldCheck.vue'
import IconRestart from 'vue-material-design-icons/Restart.vue'
import SectionCard from './SectionCard.vue'
import StatusPill from './StatusPill.vue'
import type { HealthStatus, OsUpdatesInfo } from '../types.ts'

const props = defineProps<{
	updates: OsUpdatesInfo
}>()

const status = computed<HealthStatus>(() => {
	if (props.updates.securityUpdates > 0 || props.updates.rebootRequired) return 'critical'
	if (props.updates.updatesAvailable > 0) return 'warning'
	return 'ok'
})

const statusLabel = computed(() => {
	if (!props.updates.supported) return t('serverinfo', 'Not detected')
	if (props.updates.rebootRequired) return t('serverinfo', 'Reboot needed')
	if (props.updates.securityUpdates > 0) return t('serverinfo', '{n} security', { n: props.updates.securityUpdates })
	if (props.updates.updatesAvailable > 0) return t('serverinfo', '{n} updates', { n: props.updates.updatesAvailable })
	return t('serverinfo', 'Up to date')
})
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconLinux :size="18" />
				<span>{{ t('serverinfo', 'Operating system updates') }}</span>
			</div>
		</template>
		<template #actions>
			<StatusPill :status="status" :label="statusLabel" />
		</template>

		<div :class="$style.distroLine">
			{{ updates.distro || t('serverinfo', 'Operating system unknown') }}
		</div>

		<p v-if="!updates.supported" :class="$style.note">
			{{ t('serverinfo', 'Could not detect any update-tracking files. On Debian/Ubuntu, install the "update-notifier-common" package so the dashboard can read /var/lib/update-notifier/updates-available.') }}
		</p>

		<template v-else>
			<div :class="$style.kpis">
				<div :class="[$style.kpi, updates.updatesAvailable > 0 && $style.kpi_warning]">
					<div :class="$style.kpiValue">{{ updates.updatesAvailable.toLocaleString() }}</div>
					<div :class="$style.kpiLabel">{{ t('serverinfo', 'Updates available') }}</div>
				</div>
				<div :class="[$style.kpi, updates.securityUpdates > 0 && $style.kpi_critical]">
					<IconShield :size="16" :class="$style.kpiIcon" />
					<div :class="$style.kpiValue">{{ updates.securityUpdates.toLocaleString() }}</div>
					<div :class="$style.kpiLabel">{{ t('serverinfo', 'Security') }}</div>
				</div>
				<div :class="[$style.kpi, updates.rebootRequired && $style.kpi_critical]">
					<IconRestart :size="16" :class="$style.kpiIcon" />
					<div :class="$style.kpiValue">{{ updates.rebootRequired ? t('serverinfo', 'Yes') : t('serverinfo', 'No') }}</div>
					<div :class="$style.kpiLabel">{{ t('serverinfo', 'Reboot needed') }}</div>
				</div>
			</div>

			<p v-if="updates.summary" :class="$style.summary">
				{{ updates.summary }}
			</p>

			<div v-if="updates.rebootRequired && updates.rebootPackages.length > 0">
				<div :class="$style.subLabel">{{ t('serverinfo', 'Packages requiring reboot') }}</div>
				<div :class="$style.tags">
					<span v-for="pkg in updates.rebootPackages.slice(0, 30)" :key="pkg" :class="$style.tag">
						{{ pkg }}
					</span>
				</div>
			</div>

			<p v-if="updates.source" :class="$style.source">
				{{ t('serverinfo', 'Source:') }} <code>{{ updates.source }}</code>
			</p>
		</template>
	</SectionCard>
</template>

<style module lang="scss">
.distroLine {
	font-family: var(--font-face-monospace, monospace);
	color: var(--color-main-text);
	font-size: 0.92em;
	font-weight: 600;
}

.note {
	margin: 0;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	line-height: 1.4;
}

.kpis {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 8px;
}

.kpi {
	position: relative;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	border-left: 3px solid var(--color-success);
}

.kpi_warning { border-left-color: var(--color-warning); }
.kpi_critical { border-left-color: var(--color-error); }

.kpiIcon {
	position: absolute;
	right: 10px;
	top: 10px;
	color: var(--color-text-maxcontrast);
}

.kpiValue {
	font-size: 1.4em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	line-height: 1.1;
}

.kpiLabel {
	font-size: 0.72em;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
	font-weight: 600;
	margin-top: 2px;
}

.summary {
	margin: 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.subLabel {
	font-size: 0.7em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	margin-bottom: 6px;
}

.tags {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.tag {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 999px;
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 0.78em;
	font-family: var(--font-face-monospace, monospace);
	border: 1px solid var(--color-border);
}

.source {
	margin: 0;
	font-size: 0.72em;
	color: var(--color-text-maxcontrast);

	code {
		font-family: var(--font-face-monospace, monospace);
	}
}
</style>
