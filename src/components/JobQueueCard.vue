<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconQueue from 'vue-material-design-icons/FormatListBulleted.vue'
import IconRunning from 'vue-material-design-icons/PlayCircleOutline.vue'
import IconStuck from 'vue-material-design-icons/AlertOutline.vue'
import IconWaiting from 'vue-material-design-icons/Pause.vue'
import IconLog from 'vue-material-design-icons/FileDocumentOutline.vue'
import IconCog from 'vue-material-design-icons/CogOutline.vue'
import QuickActions from './QuickActions.vue'
import SectionCard from './SectionCard.vue'
import StatusPill from './StatusPill.vue'
import type { HealthStatus, JobQueueStats } from '../types.ts'

const props = defineProps<{
	jobs: JobQueueStats
	logUrl: string
	settingsUrl: string
}>()

const status = computed<HealthStatus>(() => {
	if (props.jobs.stuck > 0) {
		return 'critical'
	}
	if (props.jobs.reserved > 50) {
		return 'warning'
	}
	return 'ok'
})

const statusLabel = computed(() => {
	if (props.jobs.stuck > 0) {
		return t('serverinfo', '{n} stuck', { n: props.jobs.stuck })
	}
	if (props.jobs.reserved > 50) {
		return t('serverinfo', 'High load')
	}
	return t('serverinfo', 'Healthy')
})

const shortClassName = (cls: string): string => {
	const parts = cls.split('\\')
	return parts[parts.length - 1] || cls
}

const namespacePrefix = (cls: string): string => {
	const parts = cls.split('\\')
	if (parts.length <= 1) {
		return ''
	}
	return parts.slice(0, -1).join('\\') + '\\'
}
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconQueue :size="18" />
				<span>{{ t('serverinfo', 'Job queue') }}</span>
			</div>
		</template>
		<template #actions>
			<StatusPill :status="status" :label="statusLabel" />
		</template>

		<div :class="$style.kpis">
			<div :class="$style.kpi">
				<div :class="[$style.kpiIcon, $style.kpiTotal]">
					<IconQueue :size="16" />
				</div>
				<div :class="$style.kpiText">
					<div :class="$style.kpiValue">{{ jobs.total.toLocaleString() }}</div>
					<div :class="$style.kpiLabel">{{ t('serverinfo', 'Total') }}</div>
				</div>
			</div>

			<div :class="$style.kpi">
				<div :class="[$style.kpiIcon, $style.kpiRunning]">
					<IconRunning :size="16" />
				</div>
				<div :class="$style.kpiText">
					<div :class="$style.kpiValue">{{ jobs.reserved.toLocaleString() }}</div>
					<div :class="$style.kpiLabel">{{ t('serverinfo', 'Running now') }}</div>
				</div>
			</div>

			<div :class="$style.kpi">
				<div :class="[$style.kpiIcon, $style.kpiPending]">
					<IconWaiting :size="16" />
				</div>
				<div :class="$style.kpiText">
					<div :class="$style.kpiValue">{{ Math.max(0, jobs.total - jobs.reserved).toLocaleString() }}</div>
					<div :class="$style.kpiLabel">{{ t('serverinfo', 'Waiting') }}</div>
				</div>
			</div>

			<div v-if="jobs.stuck > 0" :class="$style.kpi">
				<div :class="[$style.kpiIcon, $style.kpiStuck]">
					<IconStuck :size="16" />
				</div>
				<div :class="$style.kpiText">
					<div :class="$style.kpiValue">{{ jobs.stuck.toLocaleString() }}</div>
					<div :class="$style.kpiLabel">{{ t('serverinfo', 'Stuck > 12h') }}</div>
				</div>
			</div>
		</div>

		<QuickActions :actions="[
			{ id: 'log', label: t('serverinfo', 'View log'), icon: IconLog, href: logUrl },
			{ id: 'jobs', label: t('serverinfo', 'Job settings'), icon: IconCog, href: settingsUrl },
		]" />

		<div v-if="jobs.topClasses.length > 0" :class="$style.classes">
			<div :class="$style.classesLabel">{{ t('serverinfo', 'Top job classes') }}</div>
			<ul :class="$style.classList">
				<li v-for="cls in jobs.topClasses" :key="cls.class" :class="$style.classRow">
					<div :class="$style.className" :title="cls.class">
						<span :class="$style.classNs">{{ namespacePrefix(cls.class) }}</span><span :class="$style.classBase">{{ shortClassName(cls.class) }}</span>
					</div>
					<div :class="$style.classCount">{{ cls.count.toLocaleString() }}</div>
				</li>
			</ul>
		</div>
	</SectionCard>
</template>

<style module lang="scss">
.kpis {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 8px;
}

.kpi {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.kpiIcon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 8px;
	flex-shrink: 0;
}

.kpiTotal {
	background-color: color-mix(in srgb, var(--color-primary-element) 18%, transparent);
	color: var(--color-primary-element);
}

.kpiRunning {
	background-color: color-mix(in srgb, var(--color-success) 22%, transparent);
	color: color-mix(in srgb, var(--color-success) 35%, var(--color-main-text));
}

.kpiPending {
	background-color: color-mix(in srgb, var(--color-text-maxcontrast) 16%, transparent);
	color: var(--color-text-maxcontrast);
}

.kpiStuck {
	background-color: color-mix(in srgb, var(--color-error) 20%, transparent);
	color: color-mix(in srgb, var(--color-error) 35%, var(--color-main-text));
}

.kpiText {
	min-width: 0;
}

.kpiValue {
	font-size: 1.15em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	line-height: 1.1;
}

.kpiLabel {
	font-size: 0.72em;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	font-weight: 600;
	margin-top: 1px;
}

.classes {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
}

.classesLabel {
	font-size: 0.7em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.classList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.classRow {
	display: grid;
	grid-template-columns: 1fr auto;
	gap: 12px;
	align-items: baseline;
	font-size: 0.85em;
}

.className {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.92em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}

.classNs {
	color: var(--color-text-maxcontrast);
}

.classBase {
	color: var(--color-main-text);
	font-weight: 600;
}

.classCount {
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	font-weight: 600;
}
</style>
