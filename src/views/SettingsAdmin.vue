<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="server-info-wrapper">
		<template v-if="staticData">
			<!-- Server info + Thermal -->
			<div class="section server-infos-two">
				<div class="row">
					<div class="col col-6 col-l-12">
						<SystemSection
							:hostname="staticData.hostname"
							:osname="staticData.osname"
							:cpu="staticData.cpu"
							:memTotal="staticData.memTotal"
							:servertime="liveData?.servertime ?? ''"
							:uptime="liveData?.uptime ?? ''" />
					</div>
					<!-- Thermal only exists once live data lands; show a skeleton until then. -->
					<div v-if="!liveData || liveData.thermalzones.length > 0" class="col col-6 col-l-12">
						<SectionSkeleton v-if="!liveData" />
						<ThermalSection v-else :thermalzones="liveData.thermalzones" />
					</div>
				</div>
			</div>

			<!-- CPU + Memory charts -->
			<div class="section server-infos-two">
				<div class="row">
					<div class="col col-6 col-l-12">
						<CpuChartSection
							v-if="liveData"
							:cpuload="liveData.cpu.load"
							:cpunum="staticData.cpu.threads"
							:tick="tick" />
						<SectionSkeleton v-else />
					</div>
					<div class="col col-6 col-l-12">
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

			<!-- Active users -->
			<ActiveUsersSection :activeUsers="staticData.activeUsers" :numUsers="staticData.storage.num_users" />

			<!-- Shares -->
			<SharesSection v-if="staticData.shares.num_shares > 0" :shares="staticData.shares" />

			<!-- PHP + Database -->
			<div class="section php-database">
				<div class="row">
					<div class="col col-6 col-m-12">
						<PhpSection
							:php="staticData.php"
							:fpm="staticData.fpm"
							:phpinfo="staticData.phpinfo"
							:phpinfoUrl="staticData.phpinfoUrl" />
					</div>
					<div class="col col-6 col-m-12">
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
					<div class="col col-6 col-l-12">
						<SectionSkeleton />
					</div>
					<div class="col col-6 col-l-12">
						<SectionSkeleton />
					</div>
				</div>
			</div>
			<div class="section server-infos-two">
				<div class="row">
					<div class="col col-6 col-l-12">
						<SectionSkeleton />
					</div>
					<div class="col col-6 col-l-12">
						<SectionSkeleton />
					</div>
				</div>
			</div>
			<SectionSkeleton />
			<SectionSkeleton />
			<div class="section php-database">
				<div class="row">
					<div class="col col-6 col-m-12">
						<SectionSkeleton />
					</div>
					<div class="col col-6 col-m-12">
						<SectionSkeleton />
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import ActiveUsersSection from '../components/ActiveUsersSection.vue'
import CpuChartSection from '../components/CpuChartSection.vue'
import DatabaseSection from '../components/DatabaseSection.vue'
import DiskSection from '../components/DiskSection.vue'
import MemoryChartSection from '../components/MemoryChartSection.vue'
import MonitoringSection from '../components/MonitoringSection.vue'
import NetworkSection from '../components/NetworkSection.vue'
import PhpSection from '../components/PhpSection.vue'
import SectionSkeleton from '../components/SectionSkeleton.vue'
import SharesSection from '../components/SharesSection.vue'
import SystemSection from '../components/SystemSection.vue'
import ThermalSection from '../components/ThermalSection.vue'
import { useLiveData } from '../composables/useLiveData.ts'
import { useStaticData } from '../composables/useStaticData.ts'

defineOptions({ name: 'ServerInfo' })

const { data: staticData } = useStaticData()
const { data: liveData, tick } = useLiveData()
</script>
