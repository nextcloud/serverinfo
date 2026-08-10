<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<SectionHeading :icon="Thermometer" :title="t('serverinfo', 'Temperature')" />
	<div class="server-info-table">
		<table>
			<tbody>
				<tr v-for="zone in thermalzones" :key="zone.zone">
					<td>{{ zone.type }}:</td>
					<td><span class="info temp">{{ Math.round(zone.temp) }}</span>°C</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup lang="ts">
import type { ThermalZone } from '../types.ts'

import { t } from '@nextcloud/l10n'
import Thermometer from 'vue-material-design-icons/Thermometer.vue'
import SectionHeading from './SectionHeading.vue'

defineProps<{
	thermalzones: ThermalZone[]
}>()
</script>

<style scoped>
/* The reading refreshes every two seconds; equal-width digits keep it from
   jumping sideways between updates. */
.temp {
	font-variant-numeric: tabular-nums;
}
</style>
