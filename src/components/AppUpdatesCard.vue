<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconUpdate from 'vue-material-design-icons/PackageUp.vue'
import IconCheck from 'vue-material-design-icons/CheckCircleOutline.vue'
import IconArrow from 'vue-material-design-icons/ArrowRight.vue'
import SectionCard from './SectionCard.vue'
import StatusPill from './StatusPill.vue'
import type { AppsInfo } from '../types.ts'

const props = defineProps<{
	apps: AppsInfo
	manageUrl: string
}>()

const hasUpdates = computed(() => props.apps.numUpdatesAvailable > 0)

const titleCaseId = (id: string): string => {
	return id
		.replace(/[_-]+/g, ' ')
		.replace(/\b\w/g, (c) => c.toUpperCase())
}
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconUpdate :size="18" />
				<span>{{ t('serverinfo', 'App updates') }}</span>
			</div>
		</template>
		<template #actions>
			<StatusPill
				:status="hasUpdates ? 'warning' : 'ok'"
				:label="hasUpdates
					? t('serverinfo', '{n} available', { n: apps.numUpdatesAvailable })
					: t('serverinfo', 'Up to date')" />
		</template>

		<div :class="$style.summary">
			<div :class="[$style.bigBlock, hasUpdates ? $style.bigBlock_active : $style.bigBlock_clean]">
				<div :class="$style.bigNumber">{{ apps.numUpdatesAvailable }}</div>
				<div :class="$style.bigLabel">
					{{ t('serverinfo', 'Update(s) waiting', { n: apps.numUpdatesAvailable }) }}
				</div>
			</div>
			<div :class="$style.meta">
				<div :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('serverinfo', 'Installed') }}</span>
					<span :class="$style.metaValue">{{ apps.numInstalled.toLocaleString() }}</span>
				</div>
				<div v-if="!hasUpdates" :class="$style.cleanRow">
					<IconCheck :size="14" />
					<span>{{ t('serverinfo', 'All apps current') }}</span>
				</div>
			</div>
		</div>

		<ul v-if="hasUpdates" :class="$style.updateList">
			<li v-for="update in apps.appUpdates" :key="update.id" :class="$style.updateRow">
				<span :class="$style.updateName">{{ titleCaseId(update.id) }}</span>
				<span :class="$style.updateVersion">→ {{ update.version }}</span>
			</li>
		</ul>

		<a v-if="hasUpdates" :href="manageUrl" :class="$style.cta">
			<span>{{ t('serverinfo', 'Manage updates') }}</span>
			<IconArrow :size="14" />
		</a>
	</SectionCard>
</template>

<style module lang="scss">
.summary {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 14px;
	align-items: center;
}

.bigBlock {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 10px 16px;
	min-width: 92px;
	border-radius: var(--border-radius-large);
	transition: background 0.3s ease;
}

.bigBlock_clean {
	background: linear-gradient(135deg,
		color-mix(in srgb, var(--color-success) 18%, transparent),
		color-mix(in srgb, var(--color-success) 6%, transparent));
	color: color-mix(in srgb, var(--color-success) 35%, var(--color-main-text));
}

.bigBlock_active {
	background: linear-gradient(135deg,
		color-mix(in srgb, var(--color-warning) 22%, transparent),
		color-mix(in srgb, var(--color-warning) 8%, transparent));
	color: color-mix(in srgb, var(--color-warning) 35%, var(--color-main-text));
}

.bigNumber {
	font-size: 2.2em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	line-height: 1;
	letter-spacing: -0.02em;
}

.bigLabel {
	font-size: 0.72em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	font-weight: 600;
	opacity: 0.85;
	margin-top: 4px;
	text-align: center;
}

.meta {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.metaRow {
	display: flex;
	justify-content: space-between;
	font-size: 0.85em;
}

.metaLabel {
	color: var(--color-text-maxcontrast);
}

.metaValue {
	color: var(--color-main-text);
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.cleanRow {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	color: color-mix(in srgb, var(--color-success) 35%, var(--color-main-text));
	font-size: 0.82em;
	font-weight: 500;
	margin-top: 2px;
}

.updateList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.updateRow {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 12px;
	padding: 5px 10px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	font-size: 0.85em;
}

.updateName {
	color: var(--color-main-text);
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.updateVersion {
	color: var(--color-primary-element);
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	flex-shrink: 0;
}

.cta {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-primary-element);
	text-decoration: none;
	font-size: 0.85em;
	font-weight: 600;

	&:hover {
		text-decoration: underline;
	}
}
</style>
