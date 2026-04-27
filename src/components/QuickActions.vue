<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
defineProps<{
	actions: { id: string, label: string, icon: unknown, href: string, target?: string }[]
}>()
</script>

<template>
	<div :class="$style.bar">
		<a
			v-for="a in actions"
			:key="a.id"
			:href="a.href"
			:target="a.target ?? '_self'"
			rel="noopener"
			:class="$style.action"
			:title="a.label">
			<component :is="a.icon" :size="14" />
			<span>{{ a.label }}</span>
		</a>
	</div>
</template>

<style module lang="scss">
.bar {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.action {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 3px 10px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
	color: var(--color-primary-element);
	font-size: 0.78em;
	font-weight: 600;
	text-decoration: none;
	transition: background-color 0.15s ease, transform 0.15s ease;
	border: 1px solid transparent;
}

.action:hover {
	background-color: color-mix(in srgb, var(--color-primary-element) 16%, transparent);
	border-color: color-mix(in srgb, var(--color-primary-element) 30%, transparent);
	text-decoration: none;
	transform: translateY(-1px);
}
</style>
