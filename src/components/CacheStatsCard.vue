<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import IconCache from 'vue-material-design-icons/Flash.vue'
import SectionCard from './SectionCard.vue'
import UsageBar from './UsageBar.vue'
import { formatPercent } from '../composables/useFormat.ts'
import type { CachingInfo } from '../types.ts'

defineProps<{
	caching: CachingInfo
}>()
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconCache :size="18" />
				<span>{{ t('serverinfo', 'Cache performance') }}</span>
			</div>
		</template>

		<div :class="$style.grid">
			<div :class="$style.col">
				<div :class="$style.colHead">
					<div :class="$style.colName">OPcache</div>
					<div v-if="caching.opcache.enabled" :class="$style.hitRate">
						{{ formatPercent(caching.opcache.hitRate, 1) }}
					</div>
					<div v-else :class="$style.disabled">{{ t('serverinfo', 'disabled') }}</div>
				</div>
				<UsageBar
					v-if="caching.opcache.enabled"
					:value="caching.opcache.hitRate"
					:max="100"
					:label="t('serverinfo', 'Hit rate')"
					:hint="`${caching.opcache.hits.toLocaleString()} hits`" />
				<div v-if="caching.opcache.enabled" :class="$style.meta">
					<span>{{ t('serverinfo', '{n} cached scripts', { n: caching.opcache.cachedScripts.toLocaleString() }) }}</span>
					<span>{{ caching.opcache.memoryUsedMB }} / {{ (caching.opcache.memoryUsedMB + caching.opcache.memoryFreeMB).toFixed(1) }} MB</span>
				</div>
			</div>

			<div :class="$style.col">
				<div :class="$style.colHead">
					<div :class="$style.colName">APCu</div>
					<div v-if="caching.apcu.enabled" :class="$style.hitRate">
						{{ formatPercent(caching.apcu.hitRate, 1) }}
					</div>
					<div v-else :class="$style.disabled">{{ t('serverinfo', 'not loaded') }}</div>
				</div>
				<UsageBar
					v-if="caching.apcu.enabled"
					:value="caching.apcu.hitRate"
					:max="100"
					:label="t('serverinfo', 'Hit rate')"
					:hint="`${caching.apcu.hits.toLocaleString()} hits`" />
				<div v-if="caching.apcu.enabled" :class="$style.meta">
					<span>{{ caching.apcu.misses.toLocaleString() }} misses</span>
					<span>{{ caching.apcu.memoryUsedMB }} MB used</span>
				</div>
			</div>
		</div>

		<div :class="$style.config">
			<div :class="$style.cfgRow">
				<span :class="$style.cfgLabel">{{ t('serverinfo', 'Local cache') }}</span>
				<code>{{ caching.memcache.local || t('serverinfo', '— not configured —') }}</code>
			</div>
			<div :class="$style.cfgRow">
				<span :class="$style.cfgLabel">{{ t('serverinfo', 'Distributed cache') }}</span>
				<code>{{ caching.memcache.distributed || t('serverinfo', '— not configured —') }}</code>
			</div>
			<div :class="$style.cfgRow">
				<span :class="$style.cfgLabel">{{ t('serverinfo', 'File locking') }}</span>
				<code>{{ caching.memcache.locking || t('serverinfo', '— not configured —') }}</code>
			</div>
		</div>
	</SectionCard>
</template>

<style module lang="scss">
.grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 12px;
}

.col {
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.colHead {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
}

.colName {
	font-weight: 700;
	color: var(--color-main-text);
	font-size: 0.95em;
}

.hitRate {
	font-size: 1.2em;
	font-weight: 700;
	color: var(--color-primary-element);
	font-variant-numeric: tabular-nums;
}

.disabled {
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.meta {
	display: flex;
	justify-content: space-between;
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.config {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
	font-size: 0.85em;
}

.cfgRow {
	display: flex;
	justify-content: space-between;
	gap: 12px;

	code {
		font-family: var(--font-face-monospace, monospace);
		color: var(--color-main-text);
	}
}

.cfgLabel {
	color: var(--color-text-maxcontrast);
}
</style>
