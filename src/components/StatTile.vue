<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="stat-tile">
		<div class="stat-tile__label">
			{{ label }}
		</div>
		<div class="stat-tile__value">
			{{ value }}
		</div>
	</div>
</template>

<script setup lang="ts">
defineProps<{
	label: string
	value: string | number
}>()
</script>

<style scoped>
.stat-tile {
	display: grid;
	grid-template-rows: auto 1fr;
	gap: 2px;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.stat-tile__label {
	font-size: 0.72em;
	font-weight: 500;
	letter-spacing: 0.05em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.stat-tile__value {
	font-size: 1.25em;
	font-weight: 700;
	line-height: 1.2;
	font-variant-numeric: tabular-nums;
	word-break: break-word;
}
</style>
