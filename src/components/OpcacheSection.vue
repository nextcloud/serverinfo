<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<SectionHeading :icon="Flash" :title="t('serverinfo', 'OPcache')" />

	<NcNoteCard v-if="opcache.status !== 'ok'" type="warning">
		{{ reasonText }}
	</NcNoteCard>

	<template v-else>
		<div class="row row--tiles">
			<!-- TRANSLATORS: Tile label above the share of script lookups served from the cache, noun -->
			<StatTile :label="t('serverinfo', 'Hit rate')" :value="`${opcache.hitRate.toFixed(2)} %`" />
			<!-- TRANSLATORS: Tile label above how many PHP scripts are currently cached, noun -->
			<StatTile :label="t('serverinfo', 'Cached scripts')" :value="opcache.cachedScripts" />
		</div>

		<div
			class="opcache-meters"
			:title="t('serverinfo', 'These numbers describe the PHP process handling this request. Other FPM pools or the CLI keep their own OPcache.')">
			<UsageMeterBar v-for="meter in meters" :key="meter.label" v-bind="meter" />
		</div>

		<div class="server-info-table">
			<table>
				<tbody>
					<tr>
						<td>{{ t('serverinfo', 'Revalidate frequency:') }}</td>
						<td class="info">
							{{ opcache.revalidateFreq }} {{ t('serverinfo', 'seconds') }}
						</td>
					</tr>
					<tr>
						<!-- TRANSLATORS: Whether OPcache re-checks a script's file timestamp before serving it from cache, noun -->
						<td>{{ t('serverinfo', 'Validate timestamps:') }}</td>
						<td class="info">
							{{ opcache.validateTimestamps ? t('serverinfo', 'Yes') : t('serverinfo', 'No') }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'OOM restarts:') }}</td>
						<td class="info" :class="{ 'info--warning': opcache.oomRestarts > 0 }">
							{{ opcache.oomRestarts }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Last restart:') }}</td>
						<td class="info">
							<NcDateTime v-if="opcache.lastRestart !== null" :timestamp="opcache.lastRestart * 1000" />
							<template v-else>
								<!-- TRANSLATORS: Shown when OPcache has never restarted since the process started -->
								{{ t('serverinfo', 'Never') }}
							</template>
						</td>
					</tr>
					<tr v-if="opcache.jit">
						<td>{{ t('serverinfo', 'JIT:') }}</td>
						<td class="info">
							{{ jitText }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</template>
</template>

<script setup lang="ts">
import type { OpcacheStatus } from '../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Flash from 'vue-material-design-icons/Flash.vue'
import SectionHeading from './SectionHeading.vue'
import StatTile from './StatTile.vue'
import UsageMeterBar from './UsageMeterBar.vue'
import { formatBytes } from '../utils.ts'

const props = defineProps<{ opcache: OpcacheStatus }>()

const reasonText = computed(() => {
	switch (props.opcache.status) {
		case 'not_loaded':
			// TRANSLATORS: OPcache status shown when the PHP extension itself is missing
			return t('serverinfo', 'OPcache is not loaded.')
		case 'disabled':
			// TRANSLATORS: OPcache status shown when the extension is loaded but turned off
			return t('serverinfo', 'OPcache is disabled.')
		case 'api_restricted':
			// TRANSLATORS: OPcache status shown when opcache.restrict_api blocks Nextcloud from reading it
			return t('serverinfo', 'Nextcloud is not permitted to read the OPcache status ("opcache.restrict_api").')
		default:
			// TRANSLATORS: OPcache status shown when opcache_get_status() itself has been disabled
			return t('serverinfo', 'OPcache status is unavailable.')
	}
})

/**
 * @param label meter label
 * @param detail "used of total" caption
 * @param numerator amount in use
 * @param denominator amount available; a row is skipped when this is 0 (e.g. a disabled buffer), not divided into
 * @param forceError forces the error colour regardless of percentage, e.g. when OPcache itself reports "full"
 */
function meterRow(label: string, detail: string, numerator: number, denominator: number, forceError = false) {
	if (denominator <= 0) {
		return null
	}
	return {
		label,
		detail,
		percentage: Math.round((numerator / denominator) * 100),
		forceError,
	}
}

const meters = computed(() => {
	if (props.opcache.status !== 'ok') {
		return []
	}
	const { memory, internedStrings, keys, cacheFull } = props.opcache

	return [
		meterRow(
			// TRANSLATORS: Label of the usage bar for OPcache's shared memory
			t('serverinfo', 'Memory'),
			// TRANSLATORS: {used} and {total} are formatted sizes, e.g. "3.1 GB". {used} includes wasted memory, matching the bar it captions.
			t('serverinfo', '{used} of {total}', {
				used: formatBytes(memory.used + memory.wasted),
				total: formatBytes(memory.total),
			}),
			memory.used + memory.wasted,
			memory.total,
			cacheFull,
		),
		meterRow(
			// TRANSLATORS: Label of the usage bar for OPcache's interned strings buffer
			t('serverinfo', 'Interned strings'),
			// TRANSLATORS: {used} and {total} are formatted sizes, e.g. "5.8 MB"
			t('serverinfo', '{used} of {total}', {
				used: formatBytes(internedStrings.used),
				total: formatBytes(internedStrings.total),
			}),
			internedStrings.used,
			internedStrings.total,
		),
		meterRow(
			// TRANSLATORS: Label of the usage bar for how many of OPcache's cache key slots are used
			t('serverinfo', 'Keys'),
			// TRANSLATORS: {used} and {max} are counts of cached scripts, e.g. "12,400"
			t('serverinfo', '{used} of {max}', {
				used: keys.used.toLocaleString(),
				max: keys.max.toLocaleString(),
			}),
			keys.used,
			keys.max,
		),
	].filter((meter) => meter !== null)
})

const jitText = computed(() => {
	if (props.opcache.status !== 'ok' || !props.opcache.jit) {
		return ''
	}
	if (!props.opcache.jit.enabled) {
		return t('serverinfo', 'Disabled')
	}
	// TRANSLATORS: {used} and {total} are formatted sizes, e.g. "16 MB of 64 MB"
	return t('serverinfo', 'Enabled, {used} of {total} buffer used', {
		used: formatBytes(props.opcache.jit.bufferUsed),
		total: formatBytes(props.opcache.jit.bufferTotal),
	})
})
</script>

<style scoped>
.opcache-meters {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0;
}

.info--warning {
	color: var(--color-warning-text, var(--color-warning));
}
</style>
