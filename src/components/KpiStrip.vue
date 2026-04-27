<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, toRef } from 'vue'
import IconCpu from 'vue-material-design-icons/Cpu64Bit.vue'
import IconMemory from 'vue-material-design-icons/Memory.vue'
import IconDisk from 'vue-material-design-icons/Harddisk.vue'
import IconUsers from 'vue-material-design-icons/AccountGroup.vue'
import IconFiles from 'vue-material-design-icons/FileMultiple.vue'
import IconUp from 'vue-material-design-icons/TrendingUp.vue'
import IconDown from 'vue-material-design-icons/TrendingDown.vue'
import IconFlat from 'vue-material-design-icons/TrendingNeutral.vue'
import KpiTile from './KpiTile.vue'
import { formatBytes, formatPercent, statusForUsage } from '../composables/useFormat.ts'
import { useTrend } from '../composables/useTrend.ts'
import type { ActiveUsers, DiskInfo, HealthStatus, StorageStats, SystemInfo } from '../types.ts'

const props = defineProps<{
	system: SystemInfo
	disks: DiskInfo[]
	cpuHistory: number[]
	memHistory: number[]
	activeUsers: ActiveUsers
	storage: StorageStats
}>()

const cpuPercent = computed(() => {
	if (!Array.isArray(props.system.cpuload) || props.system.cpuload.length === 0 || props.system.cpunum <= 0) {
		return 0
	}
	return Math.min(100, ((Number(props.system.cpuload[0]) || 0) / props.system.cpunum) * 100)
})

const memPercent = computed(() => {
	if (props.system.mem_total <= 0) return 0
	const used = Math.max(0, props.system.mem_total - props.system.mem_free)
	return (used / props.system.mem_total) * 100
})

const memUsed = computed(() => Math.max(0, props.system.mem_total - props.system.mem_free))

interface DiskUsage {
	mount: string
	percent: number
	used: number
	total: number
}

const worstDisk = computed<DiskUsage | null>(() => {
	let worst: DiskUsage | null = null
	for (const disk of props.disks) {
		const total = disk.used + disk.available
		if (total <= 0) continue
		const percent = (disk.used / total) * 100
		if (!worst || percent > worst.percent) {
			worst = { mount: disk.mount || disk.device, percent, used: disk.used, total }
		}
	}
	return worst
})

const cpuStatus = computed<HealthStatus>(() => statusForUsage(cpuPercent.value))
const memStatus = computed<HealthStatus>(() => statusForUsage(memPercent.value))
const diskStatus = computed<HealthStatus>(() => worstDisk.value ? statusForUsage(worstDisk.value.percent) : 'ok')

const cpuTrend = useTrend(toRef(props, 'cpuHistory'), 10, 1)
const memTrend = useTrend(toRef(props, 'memHistory'), 10, 0.5)

type Category = 'cpu' | 'mem' | 'disk' | 'users' | 'files'

const categoryColor = (cat: Category, status: HealthStatus): string => {
	if (status === 'critical') return 'var(--color-error)'
	if (status === 'warning') return 'var(--color-warning)'
	switch (cat) {
	case 'cpu': return '#5b8def'
	case 'mem': return '#a76cf5'
	case 'disk': return '#23b8a6'
	case 'users': return '#3fb950'
	case 'files': return '#f59e0b'
	}
}

const trendIcon = (dir: 'up' | 'down' | 'flat') => {
	if (dir === 'up') return IconUp
	if (dir === 'down') return IconDown
	return IconFlat
}

const trendClass = (cat: Category, dir: 'up' | 'down' | 'flat'): string => {
	if (dir === 'flat') return 'trend_flat'
	if (cat === 'users') return 'trend_neutral'
	return dir === 'up' ? 'trend_bad' : 'trend_good'
}

const fmtPct = (n: number): string => formatPercent(n, 0)
const fmtInt = (n: number): string => Math.round(n).toLocaleString()
</script>

<template>
	<div :class="$style.strip">
		<KpiTile
			:label="t('serverinfo', 'CPU')"
			:value="cpuPercent"
			:color="categoryColor('cpu', cpuStatus)"
			:format-value="fmtPct"
			:history="cpuHistory"
			:pulse-percent="cpuPercent"
			:preview-label="t('serverinfo', 'CPU load · live history')">
			<template #head>
				<span :class="$style.iconBadge"><IconCpu :size="14" /></span>
				<span :class="$style.label">{{ t('serverinfo', 'CPU') }}</span>
				<span v-if="cpuTrend.hasEnoughData" :class="[$style.trend, $style[trendClass('cpu', cpuTrend.direction)]]">
					<component :is="trendIcon(cpuTrend.direction)" :size="12" />
					<span>{{ cpuTrend.direction === 'flat' ? t('serverinfo', 'stable') : `${Math.abs(Math.round(cpuTrend.deltaPercent))}%` }}</span>
				</span>
			</template>
		</KpiTile>

		<KpiTile
			:label="t('serverinfo', 'Memory')"
			:value="memPercent"
			:color="categoryColor('mem', memStatus)"
			:format-value="fmtPct"
			:history="memHistory"
			:pulse-percent="memPercent"
			:preview-label="t('serverinfo', 'Memory · live history')">
			<template #head>
				<span :class="$style.iconBadge"><IconMemory :size="14" /></span>
				<span :class="$style.label">{{ t('serverinfo', 'Memory') }}</span>
				<span v-if="memTrend.hasEnoughData" :class="[$style.trend, $style[trendClass('mem', memTrend.direction)]]">
					<component :is="trendIcon(memTrend.direction)" :size="12" />
					<span>{{ memTrend.direction === 'flat' ? t('serverinfo', 'stable') : `${Math.abs(Math.round(memTrend.deltaPercent))}%` }}</span>
				</span>
			</template>
			<template #foot>
				{{ formatBytes(memUsed * 1024) }} / {{ formatBytes(system.mem_total * 1024) }}
			</template>
		</KpiTile>

		<KpiTile
			:label="t('serverinfo', 'Disk')"
			:value="worstDisk ? worstDisk.percent : null"
			:color="categoryColor('disk', diskStatus)"
			:format-value="fmtPct"
			:pulse-percent="worstDisk ? worstDisk.percent : 0">
			<template #head>
				<span :class="$style.iconBadge"><IconDisk :size="14" /></span>
				<span :class="$style.label">{{ t('serverinfo', 'Disk') }}</span>
			</template>
			<template v-if="worstDisk" #foot>
				<span :title="worstDisk.mount">{{ worstDisk.mount }}</span>
			</template>
		</KpiTile>

		<KpiTile
			:label="t('serverinfo', 'Active')"
			:value="activeUsers.last5minutes ?? 0"
			:color="categoryColor('users', 'ok')"
			:format-value="fmtInt">
			<template #head>
				<span :class="$style.iconBadge"><IconUsers :size="14" /></span>
				<span :class="$style.label">{{ t('serverinfo', 'Active') }}</span>
			</template>
			<template #foot>
				{{ t('serverinfo', '{n} in 24h', { n: (activeUsers.last24hours ?? 0).toLocaleString() }) }}
			</template>
		</KpiTile>

		<KpiTile
			:label="t('serverinfo', 'Files')"
			:value="storage.num_files"
			:color="categoryColor('files', 'ok')"
			:format-value="fmtInt">
			<template #head>
				<span :class="$style.iconBadge"><IconFiles :size="14" /></span>
				<span :class="$style.label">{{ t('serverinfo', 'Files') }}</span>
			</template>
			<template #foot>
				{{ t('serverinfo', '{n} users', { n: storage.num_users.toLocaleString() }) }}
			</template>
		</KpiTile>
	</div>
</template>

<style module lang="scss">
.strip {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: var(--si-gap, 10px);
}

.iconBadge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	border-radius: 6px;
	background-color: color-mix(in srgb, var(--kpi-color) 18%, transparent);
	color: var(--kpi-color);
}

.label {
	font-size: 0.74em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	flex: 1;
}

.trend {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	padding: 1px 7px 1px 5px;
	border-radius: 999px;
	font-size: 0.7em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.trend_flat   { color: var(--color-text-maxcontrast); background-color: var(--color-background-hover); }
.trend_good   { color: color-mix(in srgb, var(--color-success) 35%, var(--color-main-text)); background-color: color-mix(in srgb, var(--color-success) 18%, transparent); }
.trend_bad    { color: color-mix(in srgb, var(--color-error) 35%, var(--color-main-text));   background-color: color-mix(in srgb, var(--color-error) 18%, transparent); }
.trend_neutral{ color: var(--color-primary-element);  background-color: color-mix(in srgb, var(--color-primary-element) 14%, transparent); }
</style>
