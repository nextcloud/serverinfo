<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconForecast from 'vue-material-design-icons/ChartTimelineVariant.vue'
import SectionCard from './SectionCard.vue'
import Sparkline from './Sparkline.vue'
import StatusPill from './StatusPill.vue'
import { formatBytes } from '../composables/useFormat.ts'
import type { DiskGrowthInfo, HealthStatus } from '../types.ts'

const props = defineProps<{
	growth: DiskGrowthInfo
}>()

const status = computed<HealthStatus>(() => {
	if (props.growth.daysUntilFull < 0) return 'ok'
	if (props.growth.daysUntilFull < 14) return 'critical'
	if (props.growth.daysUntilFull < 60) return 'warning'
	return 'ok'
})

const statusLabel = computed(() => {
	if (!props.growth.hasEnoughData) return t('serverinfo', 'Building data…')
	if (props.growth.daysUntilFull < 0) return t('serverinfo', 'Stable / shrinking')
	if (props.growth.daysUntilFull < 14) return t('serverinfo', 'Critical')
	if (props.growth.daysUntilFull < 60) return t('serverinfo', 'Plan ahead')
	return t('serverinfo', 'Healthy')
})

const usageHistory = computed(() => {
	if (props.growth.samples.length === 0) return []
	const max = Math.max(...props.growth.samples.map((s) => s.freeBytes), 1)
	// Display "used %" so the chart trends UP as fill grows.
	return props.growth.samples.map((s) => 100 - (s.freeBytes / max) * 100)
})

const fmtRate = (bytesPerDay: number): string => {
	const abs = Math.abs(bytesPerDay)
	const formatted = formatBytes(abs)
	return bytesPerDay >= 0
		? t('serverinfo', '+ {b} / day (freeing)', { b: formatted })
		: t('serverinfo', '− {b} / day (filling)', { b: formatted })
}
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconForecast :size="18" />
				<span>{{ t('serverinfo', 'Disk forecast') }}</span>
			</div>
		</template>
		<template #actions>
			<StatusPill :status="status" :label="statusLabel" />
		</template>

		<div :class="$style.body">
			<div :class="$style.left">
				<div :class="$style.bigValue">
					<template v-if="!growth.hasEnoughData">–</template>
					<template v-else-if="growth.daysUntilFull < 0">∞</template>
					<template v-else>{{ growth.daysUntilFull }}</template>
				</div>
				<div :class="$style.bigLabel">
					{{ growth.daysUntilFull < 0 ? t('serverinfo', 'days at current rate (free space stable or growing)') : t('serverinfo', 'days until disk full') }}
				</div>
				<div :class="$style.meta">
					<div>{{ t('serverinfo', 'Free now') }}: <strong>{{ formatBytes(growth.freeBytes) }}</strong></div>
					<div v-if="growth.hasEnoughData">{{ t('serverinfo', 'Trend') }}: {{ fmtRate(growth.bytesPerDay) }}</div>
					<div v-if="growth.hasEnoughData">{{ t('serverinfo', 'Files') }}: <strong>{{ growth.filesPerDay >= 0 ? '+' : '' }}{{ growth.filesPerDay.toLocaleString() }}</strong> / {{ t('serverinfo', 'day') }}</div>
					<div v-if="!growth.hasEnoughData" :class="$style.note">
						{{ t('serverinfo', 'Need at least 2 daily snapshots — come back tomorrow!') }}
					</div>
				</div>
			</div>

			<div v-if="usageHistory.length >= 2" :class="$style.chart">
				<Sparkline :values="usageHistory" :max="100" color="#23b8a6" :animate-on-mount="false" />
			</div>
		</div>
	</SectionCard>
</template>

<style module lang="scss">
.body {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	align-items: center;
}

@media (max-width: 540px) {
	.body { grid-template-columns: 1fr; }
}

.left {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.bigValue {
	font-size: 3em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	line-height: 1;
	letter-spacing: -0.025em;
}

.bigLabel {
	font-size: 0.78em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	max-width: 240px;
	line-height: 1.3;
}

.meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin-top: 8px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);

	strong {
		color: var(--color-main-text);
		font-weight: 600;
	}
}

.note {
	font-style: italic;
	margin-top: 4px;
}

.chart {
	height: 120px;
}
</style>
