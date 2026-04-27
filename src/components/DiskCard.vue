<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconDisk from 'vue-material-design-icons/Harddisk.vue'
import SectionCard from './SectionCard.vue'
import UsageBar from './UsageBar.vue'
import { formatMegabytes, statusForUsage } from '../composables/useFormat.ts'
import type { DiskInfo, StorageStats } from '../types.ts'

const props = defineProps<{
	disks: DiskInfo[]
	storage: StorageStats
	systemFreespace: number | null
}>()

interface DiskRow {
	disk: DiskInfo
	totalMb: number
	percent: number
}

const rows = computed<DiskRow[]>(() =>
	props.disks.map((disk) => {
		const totalMb = disk.used + disk.available
		const percent = totalMb > 0 ? (disk.used / totalMb) * 100 : 0
		return { disk, totalMb, percent }
	}),
)

const basename = (path: string): string => {
	const parts = path.split('/')
	return parts[parts.length - 1] || path
}
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconDisk :size="18" />
				<span>{{ t('serverinfo', 'Disks') }}</span>
			</div>
		</template>
		<div :class="$style.grid">
			<article
				v-for="row in rows"
				:key="row.disk.device + row.disk.mount"
				:class="[$style.disk, $style[`disk_${statusForUsage(row.percent)}`]]">
				<header :class="$style.diskHeader">
					<h3 :class="$style.diskName">{{ basename(row.disk.device) }}</h3>
					<span :class="$style.diskMount" :title="row.disk.mount">{{ row.disk.mount }}</span>
				</header>
				<UsageBar
					:value="row.percent"
					:label="row.disk.fs"
					:hint="`${formatMegabytes(row.disk.used)} / ${formatMegabytes(row.totalMb)}`" />
				<footer :class="$style.footer">
					<span>
						<strong>{{ formatMegabytes(row.disk.available) }}</strong>
						{{ t('serverinfo', 'free') }}
					</span>
					<span :class="$style.percent">{{ row.disk.percent }}</span>
				</footer>
			</article>
		</div>
		<dl :class="$style.summary">
			<div>
				<dt>{{ t('serverinfo', 'Files') }}</dt>
				<dd>{{ storage.num_files.toLocaleString() }}</dd>
			</div>
			<div>
				<dt>{{ t('serverinfo', 'Storages') }}</dt>
				<dd>{{ storage.num_storages.toLocaleString() }}</dd>
			</div>
			<div v-if="systemFreespace !== null">
				<dt>{{ t('serverinfo', 'Free space') }}</dt>
				<dd>{{ formatMegabytes(systemFreespace / 1024 / 1024) }}</dd>
			</div>
		</dl>
	</SectionCard>
</template>

<style module lang="scss">
.grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 8px;
}

.disk {
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 8px;
	border-left: 3px solid var(--color-success);
}

.disk_warning { border-left-color: var(--color-warning); }
.disk_critical { border-left-color: var(--color-error); }

.diskHeader {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 8px;
}

.diskName {
	margin: 0;
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-main-text);
}

.diskMount {
	color: var(--color-text-maxcontrast);
	font-size: 0.78em;
	font-family: var(--font-face-monospace, monospace);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 60%;
}

.footer {
	display: flex;
	justify-content: space-between;
	color: var(--color-text-maxcontrast);
	font-size: 0.78em;
}

.percent {
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	color: var(--color-main-text);
}

.summary {
	display: flex;
	gap: 20px;
	flex-wrap: wrap;
	margin: 0;
	padding-top: 6px;
	border-top: 1px solid var(--color-border);

	div {
		display: flex;
		flex-direction: column;
		gap: 1px;
	}

	dt {
		color: var(--color-text-maxcontrast);
		font-size: 0.72em;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		font-weight: 500;
	}

	dd {
		margin: 0;
		font-size: 0.95em;
		font-weight: 600;
		color: var(--color-main-text);
		font-variant-numeric: tabular-nums;
	}
}
</style>
