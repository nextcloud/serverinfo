<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section disk-status">
		<div class="row">
			<div class="col col-12">
				<h2>
					<Harddisk class="infoicon" :size="20" />
					{{ t('serverinfo', 'Disk') }}
				</h2>
			</div>
			<div v-for="(disk, i) in disks" :key="disk.device" class="col col-4 col-xl-6 col-m-12">
				<div class="infobox text-center-mobile">
					<div class="diskchart-container">
						<canvas
							:ref="el => { if (el) canvasRefs[i] = el as HTMLCanvasElement }"
							class="DiskChart"
							style="width:100%; height:200px"
							width="600"
							height="200" />
					</div>
					<div class="diskinfo-container">
						<h3>{{ diskName(disk.device) }}</h3>
						{{ t('serverinfo', 'Mount:') }}
						<span class="info">{{ disk.mount }}</span><br>
						{{ t('serverinfo', 'Filesystem:') }}
						<span class="info">{{ disk.fs }}</span><br>
						{{ t('serverinfo', 'Size:') }}
						<span class="info">{{ formatMegabytes(disk.used + disk.available) }}</span><br>
						<span class="info-color-label--available">{{ t('serverinfo', 'Available:') }}</span>
						<span class="info">{{ formatMegabytes(disk.available) }}</span><br>
						<span class="info-color-label--used">{{ t('serverinfo', 'Used:') }}</span>
						<span class="info">{{ disk.percent }} ({{ formatMegabytes(disk.used) }})</span>
					</div>
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
import { t } from '@nextcloud/l10n'
import { ArcElement, Chart, DoughnutController, Tooltip } from 'chart.js'
import { onMounted, onUnmounted } from 'vue'
import Harddisk from 'vue-material-design-icons/Harddisk.vue'
import { formatBytes, formatMegabytes, primaryColor } from '../utils.ts'

const props = defineProps<{
	disks: Array<{ device: string, fs: string, used: number, available: number, percent: string, mount: string }>
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
 *
 */
function passiveColor(): string {
	return 'rgb(148, 148, 148)'
}

onMounted(() => {
	props.disks.forEach((disk, i) => {
		const canvas = canvasRefs[i]
		if (!canvas) {
			return
		}
		charts.push(new Chart(canvas, {
			type: 'doughnut',
			data: {
				datasets: [{
					backgroundColor: [primaryColor(), passiveColor()],
					data: [disk.used, disk.available],
				}],
			},
			options: {
				plugins: {
					legend: { display: false },
					tooltip: { enabled: false },
				},
				cutout: '60%',
			},
		}))
	})
})

onUnmounted(() => {
	charts.forEach((chart) => chart.destroy())
	charts.length = 0
})
</script>
