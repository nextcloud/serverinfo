<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="server-info-wrapper">
		<template v-if="staticData">
			<!-- Server info + resource usage -->
			<div class="section server-infos-two">
				<div class="row">
					<div>
						<SystemSection
							:hostname="staticData.hostname"
							:osname="staticData.osname"
							:cpu="staticData.cpu"
							:servertime="liveData?.servertime ?? ''"
							:uptime="liveData?.uptime ?? ''" />
					</div>
					<div>
						<ResourceOverviewSection
							v-if="liveData"
							:cpuload="cpuload"
							:cpunum="staticData.cpu.threads"
							:memTotal="liveData.memory.total"
							:memFree="liveData.memory.free"
							:swapTotal="liveData.memory.swap_total"
							:swapFree="liveData.memory.swap_free" />
						<SectionSkeleton v-else />
					</div>
				</div>
			</div>

			<!-- CPU + Memory charts -->
			<div class="section server-infos-two">
				<div class="row">
					<div>
						<CpuChartSection
							v-if="liveData"
							:cpuload="cpuload"
							:cpunum="staticData.cpu.threads"
							:tick="tick" />
						<SectionSkeleton v-else />
					</div>
					<div>
						<MemoryChartSection
							v-if="liveData"
							:memTotal="liveData.memory.total"
							:memFree="liveData.memory.free"
							:swapTotal="liveData.memory.swap_total"
							:swapFree="liveData.memory.swap_free"
							:tick="tick" />
						<SectionSkeleton v-else />
					</div>
				</div>
			</div>

			<!-- Disk -->
			<DiskSection :disks="staticData.diskinfo" :freespace="staticData.freeSpace" :storage="staticData.storage" />

			<!-- Network -->
			<NetworkSection :networkinfo="staticData.networkinfo" :interfaces="staticData.networkinterfaces" />

			<!-- Background jobs -->
			<BackgroundJobsSection
				v-if="periodicData"
				:cron="periodicData.cron"
				:jobs="periodicData.backgroundJobs" />
			<SectionSkeleton v-else />

			<!-- Active users -->
			<ActiveUsersSection :activeUsers="staticData.activeUsers" :numUsers="staticData.storage.num_users" />

			<!-- Shares -->
			<SharesSection v-if="staticData.shares.num_shares > 0" :shares="staticData.shares" />

			<!-- Temperature -->
			<div v-if="!liveData || liveData.thermalzones.length > 0" class="section">
				<SectionSkeleton v-if="!liveData" />
				<ThermalSection v-else :thermalzones="liveData.thermalzones" />
			</div>

			<!-- PHP + Opcache -->
			<div class="section php-opcache">
				<div class="row">
					<div>
						<PhpSection
							:php="staticData.php"
							:fpm="staticData.fpm"
							:phpinfo="staticData.phpinfo"
							:phpinfoUrl="staticData.phpinfoUrl" />
					</div>
					<div>
						<OpcacheSection :opcache="staticData.opcache" />
					</div>
				</div>
			</div>

			<!-- Database -->
			<div class="section">
				<div class="row">
					<div>
						<DatabaseSection :database="staticData.database" />
					</div>
				</div>
			</div>

			<!-- External monitoring -->
			<MonitoringSection :ocs="staticData.ocs" />
		</template>

		<template v-else>
			<div class="section server-infos-two">
				<div class="row">
					<div>
						<SectionSkeleton />
					</div>
					<div>
						<SectionSkeleton />
					</div>
				</div>
			</div>
			<div class="section server-infos-two">
				<div class="row">
					<div>
						<SectionSkeleton />
					</div>
					<div>
						<SectionSkeleton />
					</div>
				</div>
			</div>
			<SectionSkeleton />
			<SectionSkeleton />
			<div class="section php-opcache">
				<div class="row">
					<div>
						<SectionSkeleton />
					</div>
					<div>
						<SectionSkeleton />
					</div>
				</div>
			</div>
			<div class="section">
				<div class="row">
					<div>
						<SectionSkeleton />
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { LiveData, PeriodicData } from '../types.ts'

import { computed } from 'vue'
import ActiveUsersSection from '../components/ActiveUsersSection.vue'
import BackgroundJobsSection from '../components/BackgroundJobsSection.vue'
import CpuChartSection from '../components/CpuChartSection.vue'
import DatabaseSection from '../components/DatabaseSection.vue'
import DiskSection from '../components/DiskSection.vue'
import MemoryChartSection from '../components/MemoryChartSection.vue'
import MonitoringSection from '../components/MonitoringSection.vue'
import NetworkSection from '../components/NetworkSection.vue'
import OpcacheSection from '../components/OpcacheSection.vue'
import PhpSection from '../components/PhpSection.vue'
import ResourceOverviewSection from '../components/ResourceOverviewSection.vue'
import SectionSkeleton from '../components/SectionSkeleton.vue'
import SharesSection from '../components/SharesSection.vue'
import SystemSection from '../components/SystemSection.vue'
import ThermalSection from '../components/ThermalSection.vue'
import { useLiveData } from '../composables/useLiveData.ts'
import { useStaticData } from '../composables/useStaticData.ts'

defineOptions({ name: 'ServerInfo' })

const { data: staticData } = useStaticData()
const { data: liveData, tick } = useLiveData<LiveData>('/apps/serverinfo/update')
const { data: periodicData } = useLiveData<PeriodicData>('/apps/serverinfo/periodic', 60000)

// The API reports "no CPU data" as both false and an empty array; collapsing
// the two keeps every consumer down to a single check.
const cpuload = computed(() => {
	const load = liveData.value?.cpu.load
	return Array.isArray(load) && load.length > 0 ? load : false
})
</script>
