<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
	percent: number
	color?: string
	height?: number
}>(), {
	color: 'var(--color-primary-element)',
	height: 80,
})

const VIEW_W = 600
const fillY = computed(() => props.height - (Math.max(0, Math.min(100, props.percent)) / 100) * props.height)
const gradientId = computed(() => `water-grad-${Math.random().toString(36).slice(2, 9)}`)
</script>

<template>
	<svg
		:class="$style.svg"
		:viewBox="`0 0 ${VIEW_W} ${height}`"
		preserveAspectRatio="none"
		role="img"
		aria-hidden="true">
		<defs>
			<linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
				<stop offset="0%" :stop-color="color" stop-opacity="0.55" />
				<stop offset="100%" :stop-color="color" stop-opacity="0.18" />
			</linearGradient>
		</defs>
		<g :style="{ '--fill-y': `${fillY}` }">
			<!-- Two stacked sine waves drifting at different speeds. -->
			<path
				:fill="`url(#${gradientId})`"
				:class="$style.wave"
				:d="`M 0 ${fillY} Q 75 ${fillY - 4} 150 ${fillY} T 300 ${fillY} T 450 ${fillY} T 600 ${fillY} L 600 ${height} L 0 ${height} Z`" />
			<path
				:fill="color"
				opacity="0.32"
				:class="[$style.wave, $style.waveBack]"
				:d="`M 0 ${fillY + 2} Q 100 ${fillY - 5} 200 ${fillY + 2} T 400 ${fillY + 2} T 600 ${fillY + 2} L 600 ${height} L 0 ${height} Z`" />
		</g>
	</svg>
</template>

<style module lang="scss">
.svg {
	width: 100%;
	height: 100%;
	display: block;
	overflow: hidden;
}

.wave {
	animation: si-wave 3.6s linear infinite;
	transform-origin: center;
	transition: d 0.7s cubic-bezier(0.22, 1, 0.36, 1);
}

.waveBack {
	animation-duration: 5.2s;
	animation-direction: reverse;
}

@keyframes si-wave {
	0%   { transform: translateX(0); }
	100% { transform: translateX(-150px); }
}

@media (prefers-reduced-motion: reduce) {
	.wave { animation: none; }
}
</style>
