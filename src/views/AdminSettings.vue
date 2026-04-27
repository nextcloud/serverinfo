<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref, toRef } from 'vue'
import AchievementsBadge from '../components/AchievementsBadge.vue'
import AchievementToasts from '../components/AchievementToasts.vue'
import ActiveUsersCard from '../components/ActiveUsersCard.vue'
import AppUpdatesCard from '../components/AppUpdatesCard.vue'
import CacheStatsCard from '../components/CacheStatsCard.vue'
import CronStatusCard from '../components/CronStatusCard.vue'
import DiskCard from '../components/DiskCard.vue'
import DiskPredictionCard from '../components/DiskPredictionCard.vue'
import DraggableCard from '../components/DraggableCard.vue'
import EolWarningsCard from '../components/EolWarningsCard.vue'
import FederationCard from '../components/FederationCard.vue'
import InfraGridCard from '../components/InfraGridCard.vue'
import JobQueueCard from '../components/JobQueueCard.vue'
import KpiStrip from '../components/KpiStrip.vue'
import LiveLoadCard from '../components/LiveLoadCard.vue'
import LoginActivityCard from '../components/LoginActivityCard.vue'
import MonitoringEndpointCard from '../components/MonitoringEndpointCard.vue'
import NetworkCard from '../components/NetworkCard.vue'
import OsUpdatesCard from '../components/OsUpdatesCard.vue'
import PhpDatabaseCard from '../components/PhpDatabaseCard.vue'
import RecentErrorsCard from '../components/RecentErrorsCard.vue'
import SharesCard from '../components/SharesCard.vue'
import SystemHealthCard from '../components/SystemHealthCard.vue'
import SystemInfoCard from '../components/SystemInfoCard.vue'
import ThermalCard from '../components/ThermalCard.vue'
import UsersInsightsCard from '../components/UsersInsightsCard.vue'
import { useAchievements } from '../composables/useAchievements.ts'
import { useKonami } from '../composables/useKonami.ts'
import { useLayout } from '../composables/useLayout.ts'
import { useLiveData } from '../composables/useLiveData.ts'
import type { ServerInfoState } from '../types.ts'

const props = defineProps<{
	state: ServerInfoState
}>()

const {
	system,
	thermalZones,
	cpuHistory,
	memHistory,
	swapHistory,
	failed,
	uptimeSeconds,
} = useLiveData(props.state.updateUrl, props.state.system, props.state.thermalzones)

const stateRef = toRef(props, 'state')
const { toastQueue, dismissToast, catalog } = useAchievements(stateRef, system, uptimeSeconds)
const { dadMode } = useKonami()
const { visibleOrder } = useLayout()

const now = ref(new Date())
let clockTimer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
	clockTimer = setInterval(() => {
		now.value = new Date()
	}, 1000)
})

onBeforeUnmount(() => {
	if (clockTimer !== undefined) {
		clearInterval(clockTimer)
	}
})

const hasThermal = computed(() => thermalZones.value.length > 0)
const hasShares = computed(() => props.state.shares.num_shares > 0)
</script>

<template>
	<div :class="[$style.app, 'serverinfo-app', dadMode && $style.dadMode]">
		<SystemHealthCard
			:hostname="state.hostname"
			:system="system"
			:disks="state.disks"
			:uptime-seconds="uptimeSeconds"
			:failed="failed" />

		<AchievementsBadge :items="catalog" />

		<KpiStrip
			:system="system"
			:disks="state.disks"
			:cpu-history="cpuHistory"
			:mem-history="memHistory"
			:active-users="state.activeUsers"
			:storage="state.storage" />

		<template v-for="cardId in visibleOrder" :key="cardId">
			<DraggableCard v-if="cardId === 'liveLoad'" :id="cardId">
				<LiveLoadCard
					:cpu="state.cpu"
					:system="system"
					:cpu-history="cpuHistory"
					:mem-history="memHistory"
					:swap-history="swapHistory" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'recentErrors'" :id="cardId">
				<RecentErrorsCard :data="state.recentErrors" :log-url="state.logSettingsUrl" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'osUpdates'" :id="cardId">
				<OsUpdatesCard :updates="state.osUpdates" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'eolWarnings'" :id="cardId">
				<EolWarningsCard :eol="state.eol" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'cronAndApps'" :id="cardId">
				<div :class="$style.twoCol">
					<CronStatusCard
						:cron="state.cron"
						:settings-url="state.backgroundJobsUrl"
						:overview-url="state.overviewUrl" />
					<AppUpdatesCard
						:apps="state.apps"
						:manage-url="state.appsAdminUrl" />
				</div>
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'jobQueue'" :id="cardId">
				<JobQueueCard
					:jobs="state.jobQueue"
					:log-url="state.logSettingsUrl"
					:settings-url="state.serverSettingsUrl" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'cacheStats'" :id="cardId">
				<CacheStatsCard :caching="state.caching" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'infraGrid'" :id="cardId">
				<InfraGridCard
					:slowest-jobs="state.slowestJobs"
					:db-health="state.dbHealth"
					:external-storages="state.externalStorages"
					:app-store="state.appStore" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'loginActivity'" :id="cardId">
				<LoginActivityCard :logins="state.logins" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'usersInsights'" :id="cardId">
				<UsersInsightsCard
					:top-users="state.topUsers"
					:activity="state.activity"
					:connections="state.connections" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'systemAndThermal'" :id="cardId">
				<div :class="$style.twoCol">
					<SystemInfoCard
						:hostname="state.hostname"
						:osname="state.osname"
						:cpu="state.cpu"
						:memory="state.memory"
						:now="now" />
					<ThermalCard
						v-if="hasThermal"
						:zones="thermalZones" />
				</div>
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'disks'" :id="cardId">
				<DiskCard
					:disks="state.disks"
					:storage="state.storage"
					:system-freespace="state.system.freespace" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'diskPrediction'" :id="cardId">
				<DiskPredictionCard :growth="state.diskGrowth" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'network'" :id="cardId">
				<NetworkCard
					:network-info="state.networkinfo"
					:interfaces="state.interfaces" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'usersAndShares'" :id="cardId">
				<div :class="$style.twoCol">
					<ActiveUsersCard
						:active-users="state.activeUsers"
						:total-users="state.storage.num_users" />
					<SharesCard
						v-if="hasShares"
						:shares="state.shares" />
				</div>
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'federation'" :id="cardId">
				<FederationCard :federation="state.federation" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'phpDatabase'" :id="cardId">
				<PhpDatabaseCard
					:php="state.php"
					:fpm="state.fpm"
					:database="state.database"
					:phpinfo-enabled="state.phpinfoEnabled"
					:phpinfo-url="state.phpinfoUrl" />
			</DraggableCard>

			<DraggableCard v-else-if="cardId === 'monitoring'" :id="cardId">
				<MonitoringEndpointCard
					:endpoint="state.monitoringEndpoint" />
			</DraggableCard>
		</template>

		<p :class="$style.foot">
			{{ t('serverinfo', 'Live data refreshes every few seconds.') }}
			<span v-if="dadMode" :class="$style.dadFlag">🤓 dad mode</span>
		</p>

		<AchievementToasts :items="toastQueue" :dismiss="dismissToast" />
	</div>
</template>

<style module lang="scss">
.app {
	display: flex;
	flex-direction: column;
	gap: var(--si-section-gap);
	max-width: 1400px;
	padding: 44px var(--si-page-padding-x) 0;

	--si-page-padding-x: 24px;
	--si-section-gap: 18px;
	--si-card-padding-x: 22px;
	--si-card-padding-y: 20px;
	--si-card-gap: 14px;
	--si-gap: 14px;
	--si-kpi-min-height: 178px;
}

.twoCol {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: var(--si-gap);
}

.foot {
	color: var(--color-text-maxcontrast);
	font-size: 0.78em;
	text-align: center;
	padding: 8px 0 4px;
	margin: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
}

.dadFlag {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 1px 8px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 14%, transparent);
	color: var(--color-primary-element);
	font-weight: 700;
}

.dadMode {
	font-family: 'Comic Sans MS', 'Comic Sans', cursive, var(--font-face);
}

/*
 * Reset hostile global Nextcloud admin styles inside the dashboard.
 * Core sets dl/dt/dd to inline-block + 12px padding which destroys
 * grid layouts and inflates row spacing.
 */
:global(.serverinfo-app dl),
:global(.serverinfo-app dt),
:global(.serverinfo-app dd) {
	padding: 0;
	margin: 0;
	display: block;
	width: auto;
	text-align: start;
	white-space: normal;
}

:global(.serverinfo-app .title-with-icon) {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 0.95em;
	font-weight: 600;
	color: var(--color-main-text);
}

:global(.serverinfo-app .title-with-icon > span) {
	display: inline;
}

:global(.serverinfo-app .title-with-icon .material-design-icon) {
	color: var(--color-primary-element);
}

:global(.serverinfo-app .sr-only) {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}
</style>
