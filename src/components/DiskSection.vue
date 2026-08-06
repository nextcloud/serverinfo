<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section disk-status">
		<SectionHeading :icon="Harddisk" :title="t('serverinfo', 'Disk')" />
		<div class="disk-grid">
			<div v-for="(disk, i) in disks" :key="disk.device" class="infobox text-center-mobile">
				<div class="diskchart-container">
					<canvas
						:ref="el => { if (el) canvasRefs[i] = el as HTMLCanvasElement }"
						class="DiskChart"
						role="img"
						:aria-label="chartLabel(disk)" />
				</div>
				<div class="diskinfo-container">
					<h3 :title="disk.device">
						{{ diskName(disk.device) }}
					</h3>
					<dl class="diskinfo">
						<div class="diskinfo__row">
							<dt>{{ t('serverinfo', 'Mount:') }}</dt>
							<dd class="info">
								{{ disk.mount }}
							</dd>
						</div>
						<div class="diskinfo__row">
							<dt>{{ t('serverinfo', 'Filesystem:') }}</dt>
							<dd class="info">
								{{ disk.fs }}
							</dd>
						</div>
						<div class="diskinfo__row">
							<dt>{{ t('serverinfo', 'Size:') }}</dt>
							<dd class="info">
								{{ formatMegabytes(disk.used + disk.available) }}
							</dd>
						</div>
						<div class="diskinfo__row">
							<dt class="info-color-label--available">
								{{ t('serverinfo', 'Available:') }}
							</dt>
							<dd class="info">
								{{ formatMegabytes(disk.available) }}
							</dd>
						</div>
						<div class="diskinfo__row">
							<dt class="info-color-label--used">
								{{ t('serverinfo', 'Used:') }}
							</dt>
							<dd class="info">
								{{ disk.percent }} ({{ formatMegabytes(disk.used) }})
							</dd>
						</div>
					</dl>
				</div>
			</div>
		</div>

		<p>{{ t('serverinfo', 'Files:') }} <strong>{{ storage.num_files }}</strong></p>
		<p>{{ t('serverinfo', 'Storages:') }} <strong>{{ storage.num_storages }}</strong></p>
		<p v-if="freespace !== null">
			{{ t('serverinfo', 'Free Space:') }} <strong>{{ formatBytes(freespace) }}</strong>
		</p>
	</div>
</template>

<script setup lang="ts">
import type { Plugin, TooltipItem } from 'chart.js'

import { t } from '@nextcloud/l10n'
import { ArcElement, Chart, DoughnutController, Tooltip } from 'chart.js'
import { onMounted, onUnmounted } from 'vue'
import Harddisk from 'vue-material-design-icons/Harddisk.vue'
import SectionHeading from './SectionHeading.vue'
import { cssColor, formatBytes, formatMegabytes, primaryColor } from '../utils.ts'

type Disk = { device: string, fs: string, used: number, available: number, percent: string, mount: string }

const props = defineProps<{
	disks: Disk[]
	freespace: number | null
	storage: { num_files: number, num_storages: number }
}>()

Chart.register(ArcElement, DoughnutController, Tooltip)

const canvasRefs: HTMLCanvasElement[] = []
const charts: Chart[] = []

/**
 *
 * @param device full device path
 */
function diskName(device: string): string {
	return device.split('/').pop() ?? device
}

/**
 * Screen-reader description of the otherwise opaque chart canvas.
 *
 * @param disk the disk the chart belongs to
 */
function chartLabel(disk: Disk): string {
	return t('serverinfo', '{used} of {total} used', {
		used: formatMegabytes(disk.used),
		total: formatMegabytes(disk.used + disk.available),
	})
}

/**
 * Rounds the percentage the API reports as a string ("54.25%") to fit inside
 * the doughnut.
 *
 * @param percent percentage as reported, including the sign
 */
function shortPercent(percent: string): string {
	const value = Number.parseFloat(percent)
	return Number.isNaN(value) ? percent : Math.round(value) + '%'
}

/**
 * Writes the usage percentage into the hole of the doughnut, which Chart.js
 * has no built-in for.
 *
 * @param text the label to centre
 * @param color the themed text colour, resolved for the canvas
 */
function centerLabel(text: string, color: string): Plugin<'doughnut'> {
	return {
		id: 'centerLabel',
		afterDatasetsDraw(chart) {
			const { ctx, chartArea } = chart
			const size = Math.round((chartArea.right - chartArea.left) * 0.24)
			ctx.save()
			ctx.fillStyle = color
			ctx.font = `bold ${size}px ${getComputedStyle(chart.canvas).fontFamily}`
			ctx.textAlign = 'center'
			ctx.textBaseline = 'middle'
			ctx.fillText(text, (chartArea.left + chartArea.right) / 2, (chartArea.top + chartArea.bottom) / 2)
			ctx.restore()
		},
	}
}

onMounted(() => {
	// Canvases cannot resolve var(...), so read the theme colours once here
	const usedColor = primaryColor()
	const availableColor = cssColor('--color-border-maxcontrast', 'rgb(148, 148, 148)')
	const textColor = cssColor('--color-main-text', '#222222')

	props.disks.forEach((disk, i) => {
		const canvas = canvasRefs[i]
		if (!canvas) {
			return
		}
		charts.push(new Chart(canvas, {
			type: 'doughnut',
			data: {
				labels: [t('serverinfo', 'Used'), t('serverinfo', 'Available')],
				datasets: [{
					backgroundColor: [usedColor, availableColor],
					// The default white border gashes the ring on dark themes
					borderWidth: 0,
					borderRadius: 4,
					spacing: 2,
					hoverOffset: 4,
					data: [disk.used, disk.available],
				}],
			},
			options: {
				responsive: true,
				maintainAspectRatio: true,
				aspectRatio: 1,
				cutout: '70%',
				// Room for hoverOffset to grow into
				layout: { padding: 4 },
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							label: (ctx: TooltipItem<'doughnut'>) => `${ctx.label}: ${formatMegabytes(ctx.parsed)}`,
						},
					},
				},
			},
			plugins: [centerLabel(shortPercent(disk.percent), textColor)],
		}))
	})
})

onUnmounted(() => {
	charts.forEach((chart) => chart.destroy())
	charts.length = 0
})
</script>

<style scoped>
/* The .col-* classes size off the viewport, but the settings pane is much
   narrower, so col-4 gave each card a third of an already narrow column. */
.disk-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
	gap: 16px;
	margin: 16px 0;
}

.disk-grid .infobox {
	margin: 0;
}

.diskchart-container {
	/* Chart.js sizes the canvas from this box, so it needs a definite width */
	position: relative;
	flex: 0 0 auto;
	width: 120px;
	margin-right: 24px;
}

.diskinfo-container {
	/* Without min-width a long device name keeps the flex item at its
	   max-content width and spills over the border */
	flex: 1 1 auto;
	min-width: 0;
}

/* Core styles definition lists for prose (dt,dd{padding:12px},
   dt{width:130px}), which a compact stat list has to undo */
.diskinfo {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 2px 8px;
	padding: 0;
	margin: 0;
	line-height: 1.4;
}

.diskinfo__row {
	display: contents;
}

.diskinfo__row dt {
	width: auto;
	padding: 0;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	text-align: end;
}

.diskinfo__row dd {
	min-width: 0;
	padding: 0;
	margin: 0;
	overflow-wrap: break-word;
}

.info-color-label--available::before, .info-color-label--used::before {
	content: '';
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 10px;
	margin-right: 5px;
}

.info-color-label--available::before {
	background-color: var(--color-border-maxcontrast);
}

.info-color-label--used::before {
	background-color: var(--color-primary-element);
}

/* Default paragraph margins leave a blank line between each stat */
.disk-status > p {
	margin: 2px 0;
}

@media (width <= 1280px) {
	.text-center-mobile {
		text-align: center;
	}

	.diskchart-container {
		margin: 0 auto;
	}

	.text-center-mobile .diskinfo {
		justify-content: center;
	}
}
</style>
