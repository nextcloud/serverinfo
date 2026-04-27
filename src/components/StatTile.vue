<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
withDefaults(defineProps<{
	label: string
	value: string | number
	hint?: string
	emphasis?: boolean
}>(), {
	hint: '',
	emphasis: false,
})
</script>

<template>
	<div :class="[$style.tile, emphasis && $style.tile_emphasis]">
		<div :class="$style.label">{{ label }}</div>
		<div :class="$style.value">{{ value }}</div>
		<div v-if="hint" :class="$style.hint">{{ hint }}</div>
	</div>
</template>

<style module lang="scss">
.tile {
	flex: 1;
	min-width: 110px;
	min-height: 108px;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	display: grid;
	grid-template-rows: auto 1fr auto;
	gap: 2px;
}

.tile_emphasis {
	background: linear-gradient(135deg,
		color-mix(in srgb, var(--color-primary-element) 14%, var(--color-background-hover)),
		var(--color-background-hover));
}

.label {
	font-size: 0.72em;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
	font-weight: 500;
}

.value {
	font-size: 1.25em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	line-height: 1.2;
	word-break: break-word;
}

.hint {
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}
</style>
