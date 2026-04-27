<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconThermometer from 'vue-material-design-icons/Thermometer.vue'
import SectionCard from './SectionCard.vue'
import type { HealthStatus, ThermalZoneInfo } from '../types.ts'

const props = defineProps<{
	zones: ThermalZoneInfo[]
}>()

const statusFor = (temp: number): HealthStatus => {
	if (temp >= 85) {
		return 'critical'
	}
	if (temp >= 70) {
		return 'warning'
	}
	return 'ok'
}

const sortedZones = computed(() =>
	[...props.zones].sort((a, b) => b.temp - a.temp),
)
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconThermometer :size="18" />
				<span>{{ t('serverinfo', 'Temperature') }}</span>
			</div>
		</template>
		<ul :class="$style.zones">
			<li
				v-for="zone in sortedZones"
				:key="zone.zone"
				:class="[$style.zone, $style[`zone_${statusFor(zone.temp)}`]]">
				<div :class="$style.type">{{ zone.type }}</div>
				<div :class="$style.value">
					<span :class="$style.temp">{{ zone.temp.toFixed(1) }}</span>
					<span :class="$style.unit">°C</span>
				</div>
			</li>
		</ul>
	</SectionCard>
</template>

<style module lang="scss">
.zones {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
	gap: 6px;
}

.zone {
	padding: 8px 10px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	border-inline-start: 3px solid var(--color-success);
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.zone_warning {
	border-inline-start-color: var(--color-warning);
}

.zone_critical {
	border-inline-start-color: var(--color-error);
	background: linear-gradient(135deg,
		color-mix(in srgb, var(--color-error) 12%, var(--color-background-hover)),
		var(--color-background-hover));
}

.type {
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
	text-transform: capitalize;
}

.value {
	display: flex;
	align-items: baseline;
	gap: 3px;
}

.temp {
	font-size: 1.25em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	color: var(--color-main-text);
	line-height: 1.1;
}

.unit {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}
</style>
