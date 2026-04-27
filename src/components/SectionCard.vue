<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { useTilt } from '../composables/useTilt.ts'

defineProps<{
	title?: string
	icon?: string
}>()

const { bind, style } = useTilt()
</script>

<template>
	<section
		:class="$style.card"
		:style="style"
		v-on="bind">
		<span :class="$style.glow" aria-hidden="true" />
		<header v-if="title || $slots.header || $slots.actions" :class="$style.header">
			<div :class="$style.title">
				<span v-if="icon" :class="$style.icon" aria-hidden="true">
					<!-- eslint-disable-next-line vue/no-v-html -->
					<span v-html="icon" />
				</span>
				<slot name="header">
					<h2 :class="$style.h2">{{ title }}</h2>
				</slot>
			</div>
			<div v-if="$slots.actions" :class="$style.actions">
				<slot name="actions" />
			</div>
		</header>
		<div :class="$style.body">
			<slot />
		</div>
	</section>
</template>

<style module lang="scss">
.card {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: var(--si-card-gap, 10px);
	padding: var(--si-card-padding-y, 14px) var(--si-card-padding-x, 16px);
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	transform-style: preserve-3d;
	transition: transform 0.5s cubic-bezier(0.22, 1, 0.36, 1), border-color 0.15s ease, box-shadow 0.15s ease;
	will-change: transform;
}

.card:hover {
	border-color: color-mix(in srgb, var(--color-primary-element) 25%, var(--color-border));
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
}

.glow {
	position: absolute;
	inset: 0;
	pointer-events: none;
	border-radius: inherit;
	background:
		radial-gradient(
			circle 360px at var(--tilt-glow-x, 50%) var(--tilt-glow-y, 50%),
			color-mix(in srgb, var(--color-primary-element) 14%, transparent),
			transparent 60%
		);
	opacity: var(--tilt-active, 0);
	transition: opacity 0.4s ease;
	z-index: 0;
}

.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	flex-wrap: wrap;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
	position: relative;
	z-index: 1;
}

.title {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.icon {
	display: inline-flex;
	width: 24px;
	height: 24px;
	border-radius: 6px;
	align-items: center;
	justify-content: center;
	background-color: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
}

.icon :global(svg) {
	width: 16px;
	height: 16px;
}

.h2 {
	margin: 0;
	font-size: 0.95em;
	font-weight: 600;
	color: var(--color-main-text);
	letter-spacing: -0.005em;
}

.actions {
	display: flex;
	gap: 6px;
	align-items: center;
}

.body {
	display: flex;
	flex-direction: column;
	gap: 10px;
	position: relative;
	z-index: 1;
}

@media (prefers-reduced-motion: reduce) {
	.card { transition: none; transform: none !important; }
	.glow { display: none; }
}
</style>
