<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<SectionHeading :icon="Thermometer" :title="t('serverinfo', 'Temperature')" />
	<div class="table-wrapper">
		<table class="server-infos-table">
			<tbody>
				<tr v-for="zone in thermalzones" :key="zone.zone">
					<td>{{ zone.type }}:</td>
					<td>&nbsp;<span class="info">{{ zone.temp }}</span>°C</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup lang="ts">
import type { ThermalZone } from '../composables/useLiveData.ts'

import { t } from '@nextcloud/l10n'
import Thermometer from 'vue-material-design-icons/Thermometer.vue'
import SectionHeading from './SectionHeading.vue'

defineProps<{
	thermalzones: ThermalZone[]
}>()
</script>
