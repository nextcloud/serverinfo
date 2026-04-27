<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed, ref } from 'vue'
import Sparkline from './Sparkline.vue'
import AnimatedNumber from './AnimatedNumber.vue'
import { useTilt } from '../composables/useTilt.ts'

const props = withDefaults(defineProps<{
	label: string
	value: number | null
	color: string
	formatValue: (v: number) => string
	history?: number[]
	foot?: string
	pulsePercent?: number
	previewLabel?: string
}>(), {
	history: () => [],
	foot: '',
	pulsePercent: 0,
	previewLabel: '',
})

const hovered = ref(false)
const { bind: tiltBind, style: tiltStyle } = useTilt()

const cardBind = {
	...tiltBind,
	onMouseenter: () => { hovered.value = true },
	onMouseleave: () => {
		hovered.value = false
		tiltBind.onMouseleave()
	},
}

const breathDuration = computed(() => {
	// Higher load → faster heartbeat. Range ~ 4s (idle) to 1.2s (very busy).
	const p = Math.max(0, Math.min(100, props.pulsePercent))
	const seconds = 4 - (p / 100) * 2.8
	return `${seconds.toFixed(2)}s`
})

const showPreview = computed(() => hovered.value && props.history.length >= 4)
</script>

<template>
	<div :class="$style.wrap">
		<div
			:class="$style.kpi"
			:style="{ ...tiltStyle, '--kpi-color': color, '--breath': breathDuration }"
			v-on="cardBind">
			<span :class="$style.glow" aria-hidden="true" />
			<div :class="$style.head">
				<slot name="head" />
			</div>
			<div :class="$style.value">
				<AnimatedNumber
					v-if="value !== null"
					:value="value"
					:formatter="formatValue" />
				<span v-else>–</span>
			</div>
			<slot name="extra" />
			<div :class="$style.foot">
				<slot name="foot">{{ foot }}</slot>
			</div>
			<div v-if="history && history.length > 0" :class="$style.spark">
				<Sparkline :values="history" :max="100" :color="color" />
			</div>
		</div>

		<Transition
			:enter-from-class="$style.previewEnterFrom"
			:enter-active-class="$style.previewEnterActive"
			:leave-active-class="$style.previewLeaveActive"
			:leave-to-class="$style.previewLeaveTo">
			<div v-if="showPreview" :class="$style.preview" :style="{ '--kpi-color': color }">
				<div :class="$style.previewLabel">{{ previewLabel || label }}</div>
				<div :class="$style.previewChart">
					<Sparkline :values="history" :max="100" :color="color" :height="80" :animate-on-mount="false" />
				</div>
				<div :class="$style.previewFoot">
					<span>{{ history.length }} samples</span>
					<span>{{ formatValue(value ?? 0) }}</span>
				</div>
			</div>
		</Transition>
	</div>
</template>

<style module lang="scss">
.wrap {
	position: relative;
}

.kpi {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: var(--si-card-padding-y, 18px) var(--si-card-padding-x, 20px);
	border-radius: var(--border-radius-large);
	background:
		linear-gradient(180deg,
			var(--color-main-background),
			color-mix(in srgb, var(--kpi-color) 6%, var(--color-main-background)));
	border: 1px solid var(--color-border);
	border-left: 4px solid var(--kpi-color);
	min-height: var(--si-kpi-min-height, 156px);
	position: relative;
	overflow: hidden;
	isolation: isolate;
	transform-style: preserve-3d;
	transition: transform 0.5s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.18s ease;
	animation: si-kpi-breath var(--breath, 4s) ease-in-out infinite;
	will-change: transform;
}

.kpi::after {
	content: '';
	position: absolute;
	inset: auto -30px -30px auto;
	width: 130px;
	height: 130px;
	border-radius: 50%;
	background: radial-gradient(circle, color-mix(in srgb, var(--kpi-color) 22%, transparent), transparent 70%);
	pointer-events: none;
	z-index: 0;
}

.kpi:hover {
	box-shadow: 0 12px 28px color-mix(in srgb, var(--kpi-color) 16%, rgba(0,0,0,0.06));
}

.glow {
	position: absolute;
	inset: 0;
	pointer-events: none;
	border-radius: inherit;
	background:
		radial-gradient(
			circle 320px at var(--tilt-glow-x, 50%) var(--tilt-glow-y, 50%),
			color-mix(in srgb, var(--kpi-color) 18%, transparent),
			transparent 55%
		);
	opacity: var(--tilt-active, 0);
	transition: opacity 0.3s ease;
	z-index: 0;
}

.head, .value, .foot, .spark {
	position: relative;
	z-index: 1;
}

.head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.value {
	font-size: 2.4em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	line-height: 1.05;
	letter-spacing: -0.025em;
	margin-top: 2px;
}

.spark {
	height: 32px;
	margin-top: auto;
	margin-left: calc(-1 * var(--si-card-padding-x, 20px));
	margin-right: calc(-1 * var(--si-card-padding-x, 20px));
	margin-bottom: calc(-1 * var(--si-card-padding-y, 18px));
	opacity: 0.55;
}

.foot {
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	margin-top: auto;
}

.preview {
	position: absolute;
	left: 50%;
	top: calc(100% + 10px);
	transform: translateX(-50%);
	z-index: 100;
	width: 280px;
	padding: 12px 14px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	box-shadow: 0 18px 40px rgba(0, 0, 0, 0.16);
	pointer-events: none;
}

.preview::before {
	content: '';
	position: absolute;
	left: 50%;
	top: -7px;
	transform: translateX(-50%) rotate(45deg);
	width: 12px;
	height: 12px;
	background: var(--color-main-background);
	border-top: 1px solid var(--color-border);
	border-left: 1px solid var(--color-border);
}

.previewLabel {
	font-size: 0.72em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.previewChart {
	height: 80px;
	margin: 8px -8px 6px;
}

.previewFoot {
	display: flex;
	justify-content: space-between;
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	border-top: 1px solid var(--color-border);
	padding-top: 6px;
}

.previewEnterFrom    { opacity: 0; transform: translateX(-50%) translateY(-6px); }
.previewLeaveTo      { opacity: 0; transform: translateX(-50%) translateY(-6px); }
.previewEnterActive,
.previewLeaveActive  { transition: opacity 0.18s ease, transform 0.22s cubic-bezier(0.22, 1, 0.36, 1); }

@keyframes si-kpi-breath {
	0%, 100% { transform: scale(1); }
	50%      { transform: scale(1.012); }
}

@media (prefers-reduced-motion: reduce) {
	.kpi { animation: none; transform: none !important; transition: none; }
	.glow { display: none; }
}
</style>
