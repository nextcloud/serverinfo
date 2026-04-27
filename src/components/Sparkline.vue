<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { computed, ref, watch } from 'vue'

const props = withDefaults(defineProps<{
	values: number[]
	max?: number
	color?: string
	height?: number
	fill?: boolean
	smooth?: boolean
	animateOnMount?: boolean
	interactive?: boolean
	formatValue?: (n: number) => string
}>(), {
	max: 100,
	color: 'var(--color-primary-element)',
	height: 80,
	fill: true,
	smooth: true,
	animateOnMount: true,
	interactive: false,
	formatValue: (n: number) => `${Math.round(n)}%`,
})

const VIEW_W = 600

const smoothPath = (points: { x: number, y: number }[]): string => {
	if (points.length === 0) return ''
	if (points.length === 1) return `M${points[0].x.toFixed(2)},${points[0].y.toFixed(2)}`
	let d = `M${points[0].x.toFixed(2)},${points[0].y.toFixed(2)}`
	for (let i = 0; i < points.length - 1; i++) {
		const p0 = points[Math.max(0, i - 1)]
		const p1 = points[i]
		const p2 = points[i + 1]
		const p3 = points[Math.min(points.length - 1, i + 2)]
		const tension = 0.18
		const cp1x = p1.x + (p2.x - p0.x) * tension
		const cp1y = p1.y + (p2.y - p0.y) * tension
		const cp2x = p2.x - (p3.x - p1.x) * tension
		const cp2y = p2.y - (p3.y - p1.y) * tension
		d += ` C${cp1x.toFixed(2)},${cp1y.toFixed(2)} ${cp2x.toFixed(2)},${cp2y.toFixed(2)} ${p2.x.toFixed(2)},${p2.y.toFixed(2)}`
	}
	return d
}

const points = computed(() => {
	const values = props.values
	if (values.length === 0) return []
	const max = Math.max(props.max, 1)
	const step = values.length === 1 ? 0 : VIEW_W / (values.length - 1)
	return values.map((value, idx) => ({
		x: idx * step,
		y: props.height - Math.max(0, Math.min(1, value / max)) * props.height,
	}))
})

const path = computed(() => {
	const pts = points.value
	if (pts.length === 0) return ''
	if (props.smooth) return smoothPath(pts)
	return `M${pts.map((p) => `${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' L')}`
})

const fillPath = computed(() => {
	const pts = points.value
	if (!props.fill || pts.length === 0) return ''
	const lastX = pts[pts.length - 1].x
	return `${path.value} L${lastX.toFixed(2)},${props.height} L0,${props.height} Z`
})

const lastPoint = computed(() => {
	const pts = points.value
	return pts.length > 0 ? pts[pts.length - 1] : null
})

const gradientId = computed(() => `spark-grad-${Math.random().toString(36).slice(2, 9)}`)

const drawProgress = ref(props.animateOnMount ? 0 : 1)
const drawn = ref(!props.animateOnMount)

watch(points, () => {
	if (drawn.value) return
	requestAnimationFrame(() => {
		drawProgress.value = 1
		drawn.value = true
	})
}, { immediate: true })

// Cursor / hover interaction
const svgRef = ref<SVGSVGElement | null>(null)
const cursorIdx = ref<number | null>(null)

const onPointerMove = (e: PointerEvent): void => {
	if (!props.interactive) return
	const svg = svgRef.value
	if (!svg) return
	const rect = svg.getBoundingClientRect()
	const x = e.clientX - rect.left
	const ratio = Math.max(0, Math.min(1, x / rect.width))
	const idx = Math.round(ratio * (props.values.length - 1))
	if (idx >= 0 && idx < props.values.length) {
		cursorIdx.value = idx
	}
}

const onPointerLeave = (): void => {
	cursorIdx.value = null
}

const cursor = computed(() => {
	if (cursorIdx.value === null) return null
	const pt = points.value[cursorIdx.value]
	if (!pt) return null
	return { ...pt, value: props.values[cursorIdx.value] }
})
</script>

<template>
	<div :class="$style.box">
		<svg
			ref="svgRef"
			:class="$style.svg"
			:viewBox="`0 0 ${VIEW_W} ${height}`"
			preserveAspectRatio="none"
			role="img"
			aria-hidden="true"
			@pointermove="onPointerMove"
			@pointerleave="onPointerLeave">
			<defs>
				<linearGradient :id="gradientId" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0%" :stop-color="color" stop-opacity="0.42" />
					<stop offset="100%" :stop-color="color" stop-opacity="0" />
				</linearGradient>
			</defs>
			<path
				v-if="fill && fillPath"
				:d="fillPath"
				:fill="`url(#${gradientId})`"
				:style="{ opacity: drawProgress }"
				:class="$style.fillPath" />
			<path
				:d="path"
				fill="none"
				:stroke="color"
				stroke-width="2"
				stroke-linecap="round"
				stroke-linejoin="round"
				:class="$style.strokePath" />
			<circle
				v-if="lastPoint && cursor === null"
				:cx="lastPoint.x"
				:cy="lastPoint.y"
				r="3"
				:fill="color"
				:class="$style.dot" />
			<g v-if="cursor !== null">
				<line
					:x1="cursor.x"
					:y1="0"
					:x2="cursor.x"
					:y2="height"
					:stroke="color"
					stroke-width="1"
					stroke-dasharray="3 3"
					opacity="0.55" />
				<circle
					:cx="cursor.x"
					:cy="cursor.y"
					r="4"
					:fill="color" />
			</g>
		</svg>
		<div
			v-if="interactive && cursor !== null"
			:class="$style.tooltip"
			:style="{ left: `${(cursor.x / VIEW_W) * 100}%`, '--cursor-color': color }">
			{{ formatValue(cursor.value) }}
		</div>
	</div>
</template>

<style module lang="scss">
.box {
	position: relative;
	width: 100%;
	height: 100%;
}

.svg {
	width: 100%;
	height: 100%;
	display: block;
}

.strokePath {
	stroke-dasharray: 2000;
	stroke-dashoffset: 2000;
	animation: si-spark-draw 1.1s cubic-bezier(0.22, 1, 0.36, 1) forwards;
	transition: d 0.55s cubic-bezier(0.22, 1, 0.36, 1);
}

.fillPath {
	transition: opacity 0.6s ease 0.3s, d 0.55s cubic-bezier(0.22, 1, 0.36, 1);
}

.dot {
	opacity: 0;
	animation: si-spark-dot 0.4s ease 0.9s forwards;
	transform-origin: center;
	transition: cx 0.55s cubic-bezier(0.22, 1, 0.36, 1), cy 0.55s cubic-bezier(0.22, 1, 0.36, 1);
}

.tooltip {
	position: absolute;
	top: -22px;
	transform: translateX(-50%);
	padding: 2px 8px;
	border-radius: 999px;
	background: var(--color-main-background);
	border: 1px solid var(--cursor-color, var(--color-border));
	color: var(--color-main-text);
	font-size: 0.72em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
	pointer-events: none;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

@keyframes si-spark-draw {
	to { stroke-dashoffset: 0; }
}

@keyframes si-spark-dot {
	from { opacity: 0; transform: scale(0); }
	to { opacity: 1; transform: scale(1); }
}

@media (prefers-reduced-motion: reduce) {
	.strokePath {
		animation: none;
		stroke-dashoffset: 0;
	}
	.fillPath {
		opacity: 1 !important;
		transition: none;
	}
	.dot {
		opacity: 1;
		animation: none;
	}
}
</style>
