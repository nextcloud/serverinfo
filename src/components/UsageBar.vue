<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed } from 'vue'
import { statusForUsage } from '../composables/useFormat.ts'

const props = withDefaults(defineProps<{
	value: number
	max?: number
	label?: string
	hint?: string
}>(), {
	max: 100,
	label: '',
	hint: '',
})

const percent = computed(() => {
	if (!Number.isFinite(props.value) || !Number.isFinite(props.max) || props.max <= 0) {
		return 0
	}
	return Math.max(0, Math.min(100, (props.value / props.max) * 100))
})

const status = computed(() => statusForUsage(percent.value))
</script>

<template>
	<div :class="$style.wrapper">
		<div v-if="label || hint" :class="$style.row">
			<span :class="$style.label">{{ label }}</span>
			<span :class="$style.hint">{{ hint }}</span>
		</div>
		<div :class="$style.track">
			<div
				:class="[$style.fill, $style[`fill_${status}`]]"
				:style="{ width: `${percent}%` }"
				role="progressbar"
				:aria-valuenow="Math.round(percent)"
				aria-valuemin="0"
				aria-valuemax="100" />
		</div>
	</div>
</template>

<style module lang="scss">
.wrapper {
	width: 100%;
}

.row {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 3px;
	font-size: 0.82em;
}

.label {
	color: var(--color-main-text);
	font-weight: 500;
}

.hint {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.track {
	width: 100%;
	height: 6px;
	border-radius: 999px;
	background-color: var(--color-background-darker);
	overflow: hidden;
}

.fill {
	height: 100%;
	border-radius: 999px;
	transition: width 0.6s ease, background-color 0.4s ease;
}

.fill_ok {
	background: linear-gradient(90deg, var(--color-success), color-mix(in srgb, var(--color-success) 70%, var(--color-primary-element)));
}

.fill_warning {
	background: linear-gradient(90deg, var(--color-warning), color-mix(in srgb, var(--color-warning) 60%, #f59e0b));
}

.fill_critical {
	background: linear-gradient(90deg, var(--color-error), color-mix(in srgb, var(--color-error) 60%, #b91c1c));
}
</style>
