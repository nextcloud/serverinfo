<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import IconClose from 'vue-material-design-icons/Close.vue'

interface ToastItem {
	id: string
	emoji: string
	title: string
	description: string
	receivedAt: number
}

const props = defineProps<{
	items: ToastItem[]
	dismiss: (id: string) => void
}>()

const timers = new Map<string, ReturnType<typeof setTimeout>>()

watch(() => props.items, (next) => {
	for (const item of next) {
		if (timers.has(item.id)) continue
		const handle = setTimeout(() => {
			props.dismiss(item.id)
			timers.delete(item.id)
		}, 7000)
		timers.set(item.id, handle)
	}
}, { immediate: true, deep: true })

onBeforeUnmount(() => {
	for (const handle of timers.values()) {
		clearTimeout(handle)
	}
	timers.clear()
})
</script>

<template>
	<TransitionGroup
		tag="div"
		:class="$style.stack"
		:enter-from-class="$style.enterFrom"
		:enter-active-class="$style.enterActive"
		:leave-active-class="$style.leaveActive"
		:leave-to-class="$style.leaveTo">
		<div
			v-for="t in items"
			:key="t.id"
			:class="$style.toast"
			role="status"
			aria-live="polite">
			<div :class="$style.emoji" aria-hidden="true">{{ t.emoji }}</div>
			<div :class="$style.body">
				<div :class="$style.title">{{ t.title }}</div>
				<div :class="$style.desc">{{ t.description }}</div>
			</div>
			<button type="button" :class="$style.close" title="Dismiss" @click="dismiss(t.id)">
				<IconClose :size="16" />
			</button>
		</div>
	</TransitionGroup>
</template>

<style module lang="scss">
.stack {
	position: fixed;
	bottom: 20px;
	right: 20px;
	display: flex;
	flex-direction: column;
	gap: 10px;
	z-index: 9999;
	pointer-events: none;
}

.toast {
	pointer-events: auto;
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 14px 12px 12px;
	min-width: 280px;
	max-width: 380px;
	border-radius: var(--border-radius-large);
	background: linear-gradient(135deg,
		color-mix(in srgb, var(--color-primary-element) 14%, var(--color-main-background)),
		var(--color-main-background));
	border: 1px solid var(--color-border);
	box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
}

.emoji {
	font-size: 26px;
	line-height: 1;
	flex-shrink: 0;
	filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.15));
	animation: si-ach-pop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.body {
	flex: 1;
	min-width: 0;
}

.title {
	font-weight: 700;
	color: var(--color-main-text);
	font-size: 0.95em;
	line-height: 1.2;
}

.desc {
	font-size: 0.82em;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
	line-height: 1.3;
}

.close {
	background: transparent;
	border: none;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 4px;
	border-radius: 50%;
	display: inline-flex;
	align-items: center;
	justify-content: center;
}
.close:hover {
	background-color: var(--color-background-hover);
}

.enterFrom    { transform: translateX(120%); opacity: 0; }
.leaveTo      { transform: translateX(120%); opacity: 0; }
.enterActive,
.leaveActive  { transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.3s ease; }

@keyframes si-ach-pop {
	0%   { transform: scale(0.4) rotate(-12deg); opacity: 0; }
	60%  { transform: scale(1.2) rotate(8deg); opacity: 1; }
	100% { transform: scale(1) rotate(0); opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
	.emoji { animation: none; }
}
</style>
