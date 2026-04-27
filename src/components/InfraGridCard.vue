<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later

  A condensed grid card combining four short panels for low-volume info:
  - Slowest jobs
  - DB largest tables
  - External storages
  - App store reachability
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconJobs from 'vue-material-design-icons/Speedometer.vue'
import IconDb from 'vue-material-design-icons/Database.vue'
import IconCloud from 'vue-material-design-icons/CloudOutline.vue'
import IconStore from 'vue-material-design-icons/Store.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconCross from 'vue-material-design-icons/Close.vue'
import SectionCard from './SectionCard.vue'
import { formatBytes } from '../composables/useFormat.ts'
import type { AppStoreCheck, DbHealth, ExternalStorages, SlowJob } from '../types.ts'

const props = defineProps<{
	slowestJobs: SlowJob[]
	dbHealth: DbHealth
	externalStorages: ExternalStorages
	appStore: AppStoreCheck
}>()

const fmtSeconds = (s: number): string => {
	if (s >= 60) return `${(s / 60).toFixed(1)}m`
	return `${s}s`
}

const shortClass = (c: string): string => {
	const parts = c.split('\\')
	return parts[parts.length - 1] || c
}

const formatTimeAgo = (ts: number): string => {
	if (!ts) return ''
	const ago = Math.max(0, Date.now() / 1000 - ts)
	if (ago < 60) return t('serverinfo', 'just now')
	if (ago < 3600) return t('serverinfo', '{n} min ago', { n: Math.round(ago / 60) })
	if (ago < 86400) return t('serverinfo', '{n} h ago', { n: Math.round(ago / 3600) })
	return t('serverinfo', '{n} d ago', { n: Math.round(ago / 86400) })
}

const appStoreLabel = computed(() => formatTimeAgo(props.appStore.checkedAt))
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconJobs :size="18" />
				<span>{{ t('serverinfo', 'Infrastructure') }}</span>
			</div>
		</template>

		<div :class="$style.grid">
			<!-- Slowest jobs -->
			<div :class="$style.panel">
				<div :class="$style.panelHead">
					<IconJobs :size="14" />
					<span>{{ t('serverinfo', 'Slowest jobs') }}</span>
				</div>
				<ul v-if="slowestJobs.length > 0" :class="$style.list">
					<li v-for="j in slowestJobs.slice(0, 5)" :key="j.class" :class="$style.row">
						<span :class="$style.label" :title="j.class">{{ shortClass(j.class) }}</span>
						<span :class="$style.value">avg {{ fmtSeconds(j.avgSeconds) }}</span>
					</li>
				</ul>
				<p v-else :class="$style.empty">{{ t('serverinfo', 'No execution data yet.') }}</p>
			</div>

			<!-- Largest tables -->
			<div :class="$style.panel">
				<div :class="$style.panelHead">
					<IconDb :size="14" />
					<span>{{ t('serverinfo', 'Largest tables') }}</span>
				</div>
				<ul v-if="dbHealth.largestTables.length > 0" :class="$style.list">
					<li v-for="tbl in dbHealth.largestTables.slice(0, 5)" :key="tbl.name" :class="$style.row">
						<span :class="$style.label" :title="tbl.name">{{ tbl.name }}</span>
						<span :class="$style.value">{{ formatBytes(tbl.sizeBytes) }}</span>
					</li>
				</ul>
				<p v-else :class="$style.empty">
					{{ dbHealth.driver === 'sqlite'
						? t('serverinfo', 'Per-table size is not exposed for SQLite.')
						: t('serverinfo', 'Could not query table sizes.') }}
				</p>
			</div>

			<!-- External storages -->
			<div :class="$style.panel">
				<div :class="$style.panelHead">
					<IconCloud :size="14" />
					<span>{{ t('serverinfo', 'External storages') }}</span>
				</div>
				<p v-if="!externalStorages.installed" :class="$style.empty">
					{{ t('serverinfo', 'files_external app not installed.') }}
				</p>
				<p v-else-if="externalStorages.count === 0" :class="$style.empty">
					{{ t('serverinfo', 'No external storages configured.') }}
				</p>
				<ul v-else :class="$style.list">
					<li v-for="m in externalStorages.mounts.slice(0, 5)" :key="m.name + m.backend" :class="$style.row">
						<span :class="$style.label" :title="m.name">{{ m.name }}</span>
						<span :class="$style.tag">{{ m.backend }}</span>
					</li>
				</ul>
			</div>

			<!-- App store reachability -->
			<div :class="$style.panel">
				<div :class="$style.panelHead">
					<IconStore :size="14" />
					<span>{{ t('serverinfo', 'App store') }}</span>
				</div>
				<div :class="$style.appStore">
					<div :class="[$style.appStoreStatus, appStore.reachable ? $style.appStoreOk : $style.appStoreBad]">
						<component :is="appStore.reachable ? IconCheck : IconCross" :size="14" />
						<span>{{ appStore.reachable ? t('serverinfo', 'Reachable') : t('serverinfo', 'Unreachable') }}</span>
					</div>
					<div :class="$style.appStoreMeta">
						<span v-if="appStore.statusCode > 0">HTTP {{ appStore.statusCode }}</span>
						<span v-if="appStore.latencyMs > 0">{{ appStore.latencyMs }} ms</span>
					</div>
					<div :class="$style.appStoreMeta">
						<span :class="$style.checkedAt">{{ t('serverinfo', 'Checked {when}', { when: appStoreLabel }) }}</span>
					</div>
				</div>
			</div>
		</div>
	</SectionCard>
</template>

<style module lang="scss">
.grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 10px;
}

.panel {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.panelHead {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.72em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.row {
	display: flex;
	justify-content: space-between;
	gap: 10px;
	font-size: 0.85em;
	align-items: baseline;
}

.label {
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}

.value {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	flex-shrink: 0;
}

.tag {
	font-size: 0.78em;
	padding: 1px 7px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 14%, transparent);
	color: var(--color-primary-element);
	font-weight: 600;
	flex-shrink: 0;
}

.empty {
	margin: 0;
	font-size: 0.82em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.appStore {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.85em;
}

.appStoreStatus {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-weight: 700;
}

.appStoreOk {
	color: color-mix(in srgb, var(--color-success) 35%, var(--color-main-text));
}

.appStoreBad {
	color: color-mix(in srgb, var(--color-error) 35%, var(--color-main-text));
}

.appStoreMeta {
	display: flex;
	gap: 12px;
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.checkedAt {
	font-style: italic;
}
</style>
