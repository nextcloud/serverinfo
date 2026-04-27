<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, toRef } from 'vue'
import ServerMascot from './ServerMascot.vue'
import StatusPill from './StatusPill.vue'
import { statusForUsage } from '../composables/useFormat.ts'
import { useWeather } from '../composables/useWeather.ts'
import type { DiskInfo, HealthStatus, SystemInfo } from '../types.ts'

const props = defineProps<{
	hostname: string
	system: SystemInfo
	disks: DiskInfo[]
	uptimeSeconds: number
	failed: boolean
}>()

const weather = useWeather(toRef(props, 'system'), toRef(props, 'disks'))

const cpuPercent = computed(() => {
	if (!Array.isArray(props.system.cpuload) || props.system.cpuload.length === 0 || props.system.cpunum <= 0) {
		return 0
	}
	return Math.min(100, ((Number(props.system.cpuload[0]) || 0) / props.system.cpunum) * 100)
})

const issues = computed<{ status: HealthStatus, label: string }[]>(() => {
	const list: { status: HealthStatus, label: string }[] = []

	if (props.failed) {
		list.push({ status: 'critical', label: t('serverinfo', 'Live data unavailable') })
	}

	if (props.system.mem_total > 0) {
		const used = props.system.mem_total - props.system.mem_free
		const memPct = (used / props.system.mem_total) * 100
		const memStatus = statusForUsage(memPct)
		if (memStatus !== 'ok') {
			list.push({
				status: memStatus,
				label: t('serverinfo', 'Memory at {percent}%', { percent: Math.round(memPct) }),
			})
		}
	}

	if (props.system.swap_total > 0) {
		const usedSwap = props.system.swap_total - props.system.swap_free
		if (usedSwap > 0) {
			const swapPct = (usedSwap / props.system.swap_total) * 100
			const swapStatus = statusForUsage(swapPct)
			if (swapStatus !== 'ok') {
				list.push({
					status: swapStatus,
					label: t('serverinfo', 'Swap at {percent}%', { percent: Math.round(swapPct) }),
				})
			}
		}
	}

	if (Array.isArray(props.system.cpuload) && props.system.cpuload.length > 0 && props.system.cpunum > 0) {
		const load1m = Number(props.system.cpuload[0]) || 0
		const cpuPct = (load1m / props.system.cpunum) * 100
		const cpuStatus = statusForUsage(cpuPct)
		if (cpuStatus !== 'ok') {
			list.push({
				status: cpuStatus,
				label: t('serverinfo', 'CPU load at {percent}%', { percent: Math.round(cpuPct) }),
			})
		}
	}

	for (const disk of props.disks) {
		const total = disk.used + disk.available
		if (total <= 0) {
			continue
		}
		const pct = (disk.used / total) * 100
		const ds = statusForUsage(pct)
		if (ds !== 'ok') {
			list.push({
				status: ds,
				label: t('serverinfo', '{mount} at {percent}%', {
					mount: disk.mount || disk.device,
					percent: Math.round(pct),
				}),
			})
		}
	}

	return list
})

const overall = computed<HealthStatus>(() => {
	if (issues.value.some((i) => i.status === 'critical')) {
		return 'critical'
	}
	if (issues.value.some((i) => i.status === 'warning')) {
		return 'warning'
	}
	return 'ok'
})

const overallLabel = computed(() => {
	switch (overall.value) {
	case 'critical':
		return t('serverinfo', 'Critical')
	case 'warning':
		return t('serverinfo', 'Warning')
	default:
		return t('serverinfo', 'All systems nominal')
	}
})

const uptimeAvailable = computed(() => Number.isFinite(props.uptimeSeconds) && props.uptimeSeconds >= 0)

const formattedUptime = computed(() => {
	const total = props.uptimeSeconds
	if (!uptimeAvailable.value) {
		return '–'
	}
	const days = Math.floor(total / 86400)
	const hours = Math.floor((total % 86400) / 3600)
	const minutes = Math.floor((total % 3600) / 60)
	const seconds = total % 60
	if (days > 0) {
		return t('serverinfo', '{days}d {hours}h {minutes}m', { days, hours, minutes })
	}
	if (hours > 0) {
		return t('serverinfo', '{hours}h {minutes}m {seconds}s', { hours, minutes, seconds })
	}
	return t('serverinfo', '{minutes}m {seconds}s', { minutes, seconds })
})

interface Milestone { emoji: string, label: string }

const uptimeMilestone = computed<Milestone | null>(() => {
	if (!uptimeAvailable.value) {
		return null
	}
	const days = Math.floor(props.uptimeSeconds / 86400)
	if (days >= 365) {
		return { emoji: '🏆', label: t('serverinfo', '{n} year(s) without a reboot — legendary!', { n: Math.floor(days / 365) }) }
	}
	if (days >= 100) {
		return { emoji: '🎉', label: t('serverinfo', '{n} days strong', { n: days }) }
	}
	if (days >= 30) {
		return { emoji: '✨', label: t('serverinfo', 'Up over a month') }
	}
	if (days >= 7) {
		return { emoji: '👍', label: t('serverinfo', 'Up over a week') }
	}
	return null
})
</script>

<template>
	<section :class="[$style.hero, $style[`hero_${overall}`]]">
		<!-- Animated mesh gradient backdrop. Three slow-drifting blobs. -->
		<div :class="$style.mesh" aria-hidden="true">
			<span :class="[$style.blob, $style.blob1]" />
			<span :class="[$style.blob, $style.blob2]" />
			<span :class="[$style.blob, $style.blob3]" />
		</div>
		<svg :class="$style.noise" aria-hidden="true">
			<filter id="serverinfo-hero-noise">
				<feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="2" seed="3" />
				<feColorMatrix values="0 0 0 0 0
				                       0 0 0 0 0
				                       0 0 0 0 0
				                       0 0 0 0.05 0" />
			</filter>
			<rect width="100%" height="100%" filter="url(#serverinfo-hero-noise)" />
		</svg>

		<div :class="$style.heroLeft">
			<div :class="$style.hostname" :title="hostname">
				{{ hostname }}
			</div>
			<div :class="$style.weather" :title="weather.text">
				<span :class="$style.wEmoji" aria-hidden="true">{{ weather.emoji }}</span>
				<span>{{ weather.text }}</span>
			</div>
			<div :class="$style.metaLine">
				<StatusPill :status="overall" :label="overallLabel" />
				<span v-if="uptimeAvailable" :class="$style.uptime">
					{{ t('serverinfo', 'Up {uptime}', { uptime: formattedUptime }) }}
				</span>
				<span v-if="uptimeMilestone" :class="$style.milestone" :title="uptimeMilestone.label">
					<span aria-hidden="true">{{ uptimeMilestone.emoji }}</span>
					<span>{{ uptimeMilestone.label }}</span>
				</span>
			</div>
		</div>

		<div :class="$style.heroRight">
			<ul v-if="issues.length > 0" :class="$style.issues">
				<li v-for="(issue, idx) in issues" :key="idx" :class="$style.issue">
					<StatusPill :status="issue.status" :label="issue.label" />
				</li>
			</ul>
			<div v-else :class="$style.allGood">
				{{ t('serverinfo', 'No issues detected.') }}
			</div>

			<div :class="$style.companions">
				<ServerMascot :status="overall" :load-percent="cpuPercent" />
			</div>
		</div>
	</section>
</template>

<style module lang="scss">
.hero {
	position: relative;
	display: flex;
	flex-direction: row;
	align-items: stretch;
	justify-content: space-between;
	gap: 22px;
	flex-wrap: wrap;
	padding: 22px 26px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	overflow: hidden;
	isolation: isolate;
	min-height: 188px;
}

/* Mesh gradient: 3 stacked blurred blobs, hue varies by health, drifting slowly. */
.mesh {
	position: absolute;
	inset: 0;
	z-index: 0;
	overflow: hidden;
}

.blob {
	position: absolute;
	border-radius: 50%;
	filter: blur(60px);
	opacity: 0.65;
	will-change: transform;
}

.blob1 {
	width: 60%;
	height: 80%;
	left: -10%;
	top: -20%;
	background: radial-gradient(circle, var(--blob-color-1, #5b8def), transparent 70%);
	animation: si-blob-1 22s ease-in-out infinite alternate;
}
.blob2 {
	width: 55%;
	height: 70%;
	right: -10%;
	top: -10%;
	background: radial-gradient(circle, var(--blob-color-2, #a76cf5), transparent 70%);
	animation: si-blob-2 18s ease-in-out infinite alternate;
}
.blob3 {
	width: 65%;
	height: 75%;
	right: 10%;
	bottom: -25%;
	background: radial-gradient(circle, var(--blob-color-3, #23b8a6), transparent 70%);
	animation: si-blob-3 26s ease-in-out infinite alternate;
}

.hero {
	--blob-color-1: #5b8def;
	--blob-color-2: #a76cf5;
	--blob-color-3: #23b8a6;
}

.hero_warning {
	--blob-color-1: #f0a823;
	--blob-color-2: #e8772e;
	--blob-color-3: #f5c54a;
}

.hero_critical {
	--blob-color-1: #e63946;
	--blob-color-2: #f56565;
	--blob-color-3: #c81e1e;
}

@keyframes si-blob-1 {
	0%   { transform: translate(0, 0) scale(1); }
	100% { transform: translate(8%, 6%) scale(1.15); }
}
@keyframes si-blob-2 {
	0%   { transform: translate(0, 0) scale(1); }
	100% { transform: translate(-7%, 9%) scale(1.1); }
}
@keyframes si-blob-3 {
	0%   { transform: translate(0, 0) scale(1); }
	100% { transform: translate(-5%, -8%) scale(1.18); }
}

.noise {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	pointer-events: none;
	z-index: 1;
	opacity: 0.55;
	mix-blend-mode: overlay;
}

.heroLeft {
	flex: 1;
	min-width: 240px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	z-index: 2;
}

.greeting {
	display: flex;
	align-items: center;
	gap: 8px;
}

.greetEmoji {
	font-size: 1.1em;
	filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.15));
}

.greetText {
	font-size: 0.92em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	letter-spacing: 0.01em;
}

.hostname {
	font-size: 1.85em;
	font-weight: 800;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	letter-spacing: -0.02em;
	line-height: 1.1;
}

.weather {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-main-text);
	font-size: 0.9em;
	font-weight: 500;
	font-style: italic;
	opacity: 0.85;
}

.wEmoji {
	font-size: 1.05em;
	font-style: normal;
}

.metaLine {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
	margin-top: 4px;
}

.uptime {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-variant-numeric: tabular-nums;
	font-weight: 500;
}

.milestone {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 3px 10px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 18%, transparent);
	color: color-mix(in srgb, var(--color-primary-element) 35%, var(--color-main-text));
	font-size: 0.78em;
	font-weight: 700;
}

.heroRight {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	justify-content: space-between;
	gap: 14px;
	z-index: 2;
	min-width: 180px;
}

.issues {
	list-style: none;
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin: 0;
	padding: 0;
	justify-content: flex-end;
}

.issue {
	margin: 0;
	padding: 0;
}

.allGood {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.companions {
	display: flex;
	align-items: flex-end;
	gap: 10px;
}

.fingerprintWrap {
	border-radius: 14px;
	overflow: hidden;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
	border: 1px solid color-mix(in srgb, var(--color-main-text) 8%, transparent);
}

@media (max-width: 768px) {
	.heroRight {
		align-items: flex-start;
		min-width: 0;
		width: 100%;
	}
	.companions {
		justify-content: flex-start;
	}
	.issues {
		justify-content: flex-start;
	}
}

@media (prefers-reduced-motion: reduce) {
	.blob1, .blob2, .blob3 {
		animation: none;
	}
}
</style>
