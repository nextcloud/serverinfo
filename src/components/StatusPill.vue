<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { HealthStatus } from '../types.ts'

defineProps<{
	status: HealthStatus
	label: string
}>()
</script>

<template>
	<span :class="[$style.pill, $style[`pill_${status}`]]">
		<span :class="$style.dot" />
		<span>{{ label }}</span>
	</span>
</template>

<style module lang="scss">
.pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 2px 10px;
	border-radius: 999px;
	font-size: 0.85em;
	font-weight: 500;
	line-height: 1.4;
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background-color: var(--color-text-maxcontrast);
}

.pill_ok {
	background-color: color-mix(in srgb, var(--color-success) 18%, transparent);
	color: color-mix(in srgb, var(--color-success) 35%, var(--color-main-text));
	.dot {
		background-color: var(--color-success);
		box-shadow: 0 0 0 4px color-mix(in srgb, var(--color-success) 25%, transparent);
		animation: pulse 2.4s ease-out infinite;
	}
}

.pill_warning {
	background-color: color-mix(in srgb, var(--color-warning) 22%, transparent);
	color: color-mix(in srgb, var(--color-warning) 35%, var(--color-main-text));
	.dot {
		background-color: var(--color-warning);
	}
}

.pill_critical {
	background-color: color-mix(in srgb, var(--color-error) 20%, transparent);
	color: color-mix(in srgb, var(--color-error) 35%, var(--color-main-text));
	.dot {
		background-color: var(--color-error);
		animation: pulse-strong 1.4s ease-out infinite;
	}
}

@keyframes pulse {
	0%   { box-shadow: 0 0 0 0 color-mix(in srgb, var(--color-success) 50%, transparent); }
	70%  { box-shadow: 0 0 0 8px color-mix(in srgb, var(--color-success) 0%, transparent); }
	100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--color-success) 0%, transparent); }
}

@keyframes pulse-strong {
	0%   { box-shadow: 0 0 0 0 color-mix(in srgb, var(--color-error) 60%, transparent); }
	70%  { box-shadow: 0 0 0 10px color-mix(in srgb, var(--color-error) 0%, transparent); }
	100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--color-error) 0%, transparent); }
}
</style>
