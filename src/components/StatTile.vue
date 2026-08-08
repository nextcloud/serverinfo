<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="stat-tile">
		<div class="stat-tile__label">
			{{ label }}
		</div>
		<div class="stat-tile__value" :class="status && `stat-tile__value--${status}`">
			<slot>{{ value }}</slot>
			<!-- The colour is the only visible cue, so the severity has to be
				 spelled out for assistive technology -->
			<span v-if="statusLabel" class="hidden-visually">{{ statusLabel }}</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'

const props = defineProps<{
	label: string
	value?: string | number
	/** Colours the value when the number itself is the warning */
	status?: 'ok' | 'warning' | 'critical'
}>()

const statusLabel = computed(() => {
	switch (props.status) {
		case 'warning':
			// TRANSLATORS: Read out after a value that has crossed a warning threshold
			return t('serverinfo', 'Warning')
		case 'critical':
			// TRANSLATORS: Read out after a value that has crossed a critical threshold
			return t('serverinfo', 'Critical')
		default:
			return ''
	}
})
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

.stat-tile__value--warning {
	color: var(--color-warning-text, var(--color-warning));
}

.stat-tile__value--critical {
	color: var(--color-error-text, var(--color-error));
}
</style>
