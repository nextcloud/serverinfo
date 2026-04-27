<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed } from 'vue'
import type { HealthStatus } from '../types.ts'

const props = defineProps<{
	status: HealthStatus
	loadPercent: number
}>()

const expression = computed(() => {
	if (props.status === 'critical') return 'panic'
	if (props.status === 'warning') return 'concerned'
	if (props.loadPercent < 5) return 'sleepy'
	if (props.loadPercent < 30) return 'happy'
	return 'focused'
})

const eyes = computed(() => {
	switch (expression.value) {
	case 'sleepy': return { l: '— —', r: '' }
	case 'panic': return { l: '⚆', r: '⚆' }
	case 'concerned': return { l: '•', r: '•' }
	case 'focused': return { l: '◉', r: '◉' }
	default: return { l: '◕', r: '◕' }
	}
})

const mood = computed(() => {
	switch (expression.value) {
	case 'panic': return { mouth: '◯', tint: 'var(--color-error)' }
	case 'concerned': return { mouth: '︵', tint: 'var(--color-warning)' }
	case 'sleepy': return { mouth: 'z', tint: 'var(--color-text-maxcontrast)' }
	case 'focused': return { mouth: '⌣', tint: 'var(--color-primary-element)' }
	default: return { mouth: '‿', tint: 'var(--color-success)' }
	}
})
</script>

<template>
	<div
		:class="[$style.mascot, $style[`mascot_${expression}`]]"
		:style="{ '--mascot-tint': mood.tint }"
		:title="`Server mood: ${expression}`"
		aria-hidden="true">
		<div :class="$style.face">
			<div :class="$style.eyes">
				<span>{{ eyes.l }}</span>
				<span v-if="eyes.r">{{ eyes.r }}</span>
			</div>
			<div :class="$style.mouth">{{ mood.mouth }}</div>
		</div>
		<div :class="$style.shadow" />
	</div>
</template>

<style module lang="scss">
.mascot {
	width: 64px;
	height: 64px;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: flex-end;
	pointer-events: none;
	position: relative;
}

.face {
	width: 56px;
	height: 50px;
	border-radius: 18px 18px 14px 14px;
	background: linear-gradient(160deg,
		color-mix(in srgb, var(--mascot-tint) 18%, var(--color-main-background)),
		color-mix(in srgb, var(--mascot-tint) 8%, var(--color-main-background)));
	border: 1px solid color-mix(in srgb, var(--mascot-tint) 35%, var(--color-border));
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 2px;
	position: relative;
	box-shadow: 0 4px 12px color-mix(in srgb, var(--mascot-tint) 18%, transparent);
	animation: si-mascot-bob 3.4s ease-in-out infinite;
}

.face::before, .face::after {
	/* little antennae */
	content: '';
	position: absolute;
	top: -6px;
	width: 2px;
	height: 6px;
	background: color-mix(in srgb, var(--mascot-tint) 60%, var(--color-text-maxcontrast));
	border-radius: 2px;
}
.face::before { left: 14px; transform: rotate(-12deg); }
.face::after  { right: 14px; transform: rotate(12deg); }

.eyes {
	display: flex;
	gap: 8px;
	font-size: 14px;
	color: var(--mascot-tint);
	font-family: var(--font-face-monospace, monospace);
	line-height: 1;
}

.mouth {
	font-size: 14px;
	color: var(--mascot-tint);
	font-family: var(--font-face-monospace, monospace);
	line-height: 1;
	font-weight: 700;
}

.shadow {
	margin-top: 2px;
	width: 36px;
	height: 4px;
	border-radius: 50%;
	background: color-mix(in srgb, var(--mascot-tint) 30%, transparent);
	filter: blur(2px);
	animation: si-mascot-shadow 3.4s ease-in-out infinite;
}

.mascot_panic .face {
	animation: si-mascot-shake 0.4s ease-in-out infinite;
}

@keyframes si-mascot-bob {
	0%, 100% { transform: translateY(0); }
	50%      { transform: translateY(-4px); }
}

@keyframes si-mascot-shadow {
	0%, 100% { transform: scale(1); opacity: 0.7; }
	50%      { transform: scale(0.7); opacity: 0.4; }
}

@keyframes si-mascot-shake {
	0%, 100% { transform: translate(0, 0); }
	25%      { transform: translate(-1px, -1px); }
	50%      { transform: translate(1px, 0); }
	75%      { transform: translate(-1px, 1px); }
}

@media (prefers-reduced-motion: reduce) {
	.face, .shadow, .mascot_panic .face { animation: none; }
}
</style>
