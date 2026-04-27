<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconCpu from 'vue-material-design-icons/Cpu64Bit.vue'
import IconMemory from 'vue-material-design-icons/Memory.vue'
import SectionCard from './SectionCard.vue'
import Sparkline from './Sparkline.vue'
import StatTile from './StatTile.vue'
import UsageBar from './UsageBar.vue'
import WaterFill from './WaterFill.vue'
import { formatBytes, formatPercent } from '../composables/useFormat.ts'
import type { CpuInfo, SystemInfo } from '../types.ts'

const props = defineProps<{
	cpu: CpuInfo
	system: SystemInfo
	cpuHistory: number[]
	memHistory: number[]
	swapHistory: number[]
}>()

const cpuAvailable = computed(() =>
	Array.isArray(props.system.cpuload) && props.system.cpuload.length > 0 && props.system.cpunum > 0,
)

const cpuLoad = computed(() => {
	if (!cpuAvailable.value || !Array.isArray(props.system.cpuload)) {
		return [0, 0, 0]
	}
	return props.system.cpuload.map((v) => Number(v) || 0)
})

const cpuPercent = computed(() => {
	if (!cpuAvailable.value) {
		return 0
	}
	return Math.min(100, (cpuLoad.value[0] / props.system.cpunum) * 100)
})

const memUsed = computed(() => Math.max(0, props.system.mem_total - props.system.mem_free))
const memPercent = computed(() => (props.system.mem_total > 0 ? (memUsed.value / props.system.mem_total) * 100 : 0))

const swapUsed = computed(() => Math.max(0, props.system.swap_total - props.system.swap_free))
const swapPercent = computed(() => (props.system.swap_total > 0 ? (swapUsed.value / props.system.swap_total) * 100 : 0))

const swapAvailable = computed(() => props.system.swap_total > 0)
</script>

<template>
	<div :class="$style.grid">
		<SectionCard>
			<template #header>
				<div class="title-with-icon">
					<IconCpu :size="18" />
					<span>{{ t('serverinfo', 'CPU load') }}</span>
				</div>
			</template>

			<template v-if="cpuAvailable">
				<div :class="$style.tiles">
					<StatTile
						:label="t('serverinfo', 'Current usage')"
						:value="formatPercent(cpuPercent)"
						emphasis />
					<StatTile
						:label="t('serverinfo', 'Threads')"
						:value="cpu.threads" />
					<StatTile
						:label="t('serverinfo', 'Load avg')"
						:value="cpuLoad.map((l) => l.toFixed(2)).join(' / ')"
						:hint="t('serverinfo', '1 / 5 / 15 min')" />
				</div>

				<div :class="$style.chart">
					<Sparkline
						:values="cpuHistory"
						:max="100"
						color="#5b8def"
						interactive />
				</div>

				<UsageBar
					:value="cpuPercent"
					:label="t('serverinfo', 'Load on {threads} threads', { threads: cpu.threads })"
					:hint="formatPercent(cpuPercent)" />
			</template>
			<p v-else :class="$style.empty">
				{{ t('serverinfo', 'CPU info not available on this system.') }}
			</p>
		</SectionCard>

		<SectionCard>
			<template #header>
				<div class="title-with-icon">
					<IconMemory :size="18" />
					<span>{{ t('serverinfo', 'Memory') }}</span>
				</div>
			</template>

			<div :class="$style.tiles">
				<StatTile
					:label="t('serverinfo', 'Used')"
					:value="formatBytes(memUsed * 1024)"
					:hint="formatPercent(memPercent)"
					emphasis />
				<StatTile
					:label="t('serverinfo', 'Total')"
					:value="formatBytes(system.mem_total * 1024)" />
				<StatTile
					v-if="swapAvailable"
					:label="t('serverinfo', 'Swap used')"
					:value="formatBytes(swapUsed * 1024)"
					:hint="formatPercent(swapPercent)" />
			</div>

			<div :class="$style.chart">
				<Sparkline
					:values="memHistory"
					:max="100"
					color="#a76cf5"
					interactive />
			</div>

			<!-- Water-fill visualization: the higher the memory %, the higher the wave. -->
			<div :class="$style.water">
				<WaterFill :percent="memPercent" color="#a76cf5" />
				<span :class="$style.waterLabel">{{ formatPercent(memPercent) }}</span>
			</div>

			<UsageBar
				:value="memPercent"
				:label="t('serverinfo', 'Memory usage')"
				:hint="`${formatBytes(memUsed * 1024)} / ${formatBytes(system.mem_total * 1024)}`" />

			<UsageBar
				v-if="swapAvailable"
				:value="swapPercent"
				:label="t('serverinfo', 'Swap usage')"
				:hint="`${formatBytes(swapUsed * 1024)} / ${formatBytes(system.swap_total * 1024)}`" />
		</SectionCard>
	</div>
</template>

<style module lang="scss">
.grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 12px;
}

.tiles {
	display: flex;
	align-items: stretch;
	gap: 8px;
	flex-wrap: wrap;
}

.chart {
	height: 80px;
	padding: 0;
	display: block;
}

.water {
	position: relative;
	height: 32px;
	border-radius: 999px;
	overflow: hidden;
	background-color: var(--color-background-darker);
}

.waterLabel {
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	font-size: 0.78em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	pointer-events: none;
	mix-blend-mode: difference;
	color: white;
}

.empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
	padding: 20px 0;
	text-align: center;
	font-size: 0.9em;
}
</style>
