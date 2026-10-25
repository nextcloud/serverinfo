<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="range-switch" role="group" :aria-label="label">
		<button
			v-for="option in options"
			:key="option.id"
			type="button"
			class="range-switch__option"
			:class="{ 'range-switch__option--active': model === option.id }"
			:aria-pressed="model === option.id"
			@click="model = option.id">
			{{ option.label }}
		</button>
	</div>
</template>

<script setup lang="ts" generic="T extends string">
const model = defineModel<T>({ required: true })

defineProps<{
	/** Accessible name of the group */
	label: string
	options: Array<{ id: T, label: string }>
}>()
</script>

<style scoped>
.range-switch {
	display: inline-flex;
	align-self: flex-start;
	flex-wrap: wrap;
	gap: 2px;
	margin-bottom: 8px;
	padding: 2px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-pill);
}

.range-switch__option {
	min-height: 0;
	margin: 0;
	border: none;
	border-radius: var(--border-radius-pill);
	background: transparent;
	color: var(--color-main-text);
	padding: 2px 10px;
	font-size: 0.9em;
}

.range-switch__option:hover {
	background: var(--color-background-hover);
}

.range-switch__option--active,
.range-switch__option--active:hover {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}
</style>
