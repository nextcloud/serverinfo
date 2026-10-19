<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<nav class="filter-pills" :aria-label="label">
		<button
			v-for="option in options"
			:key="option.id"
			type="button"
			class="filter-pills__pill"
			:class="{ 'filter-pills__pill--active': model === option.id }"
			:aria-pressed="model === option.id"
			@click="model = option.id">
			{{ option.label }}
			<span class="filter-pills__count">{{ option.count }}</span>
		</button>
	</nav>
</template>

<script setup lang="ts" generic="T extends string">
const model = defineModel<T>({ required: true })

defineProps<{
	/** Accessible name of the group */
	label: string
	options: Array<{ id: T, label: string, count: number }>
}>()

</script>

<style scoped>
.filter-pills {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 12px 0;
	/* Sits the pills on a sunken strip spanning the page, so the group reads as
	   one control rather than loose buttons. */
	padding: 4px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.filter-pills__pill {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	/* On the sunken strip an outlined white pill reads as a raised chip, so the
	   inactive ones stay flat and only the selected one is filled. */
	border: none;
	border-radius: var(--border-radius-pill);
	background: transparent;
	color: var(--color-main-text);
	padding: 4px 14px;
}

.filter-pills__pill:hover {
	background: var(--color-background-hover);
}

.filter-pills__pill--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.filter-pills__count {
	color: inherit;
	opacity: 0.75;
}
</style>
