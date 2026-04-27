<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later

  Combines: top users by storage, recent activity rate, active connections.
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconUsers from 'vue-material-design-icons/AccountStarOutline.vue'
import IconActivity from 'vue-material-design-icons/Pulse.vue'
import IconConn from 'vue-material-design-icons/Wan.vue'
import SectionCard from './SectionCard.vue'
import { formatBytes } from '../composables/useFormat.ts'
import type { ActivityInfo, ConnectionsInfo, TopUser } from '../types.ts'

const props = defineProps<{
	topUsers: TopUser[]
	activity: ActivityInfo
	connections: ConnectionsInfo
}>()

const maxSize = computed(() => Math.max(1, ...props.topUsers.map((u) => u.sizeBytes)))
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconUsers :size="18" />
				<span>{{ t('serverinfo', 'Usage insights') }}</span>
			</div>
		</template>

		<div :class="$style.grid">
			<!-- Top users by storage -->
			<div :class="$style.panel">
				<div :class="$style.panelHead">
					<IconUsers :size="14" />
					<span>{{ t('serverinfo', 'Storage hogs (top users)') }}</span>
				</div>
				<ul v-if="topUsers.length > 0" :class="$style.userList">
					<li v-for="u in topUsers" :key="u.user" :class="$style.userRow">
						<span :class="$style.userName" :title="u.user">{{ u.user }}</span>
						<div :class="$style.userBar">
							<div :class="$style.userFill" :style="{ width: `${(u.sizeBytes / maxSize) * 100}%` }" />
						</div>
						<span :class="$style.userSize">{{ formatBytes(u.sizeBytes) }}</span>
					</li>
				</ul>
				<p v-else :class="$style.empty">{{ t('serverinfo', 'No user storages found.') }}</p>
			</div>

			<!-- Activity + connections -->
			<div :class="$style.panel">
				<div :class="$style.panelHead">
					<IconActivity :size="14" />
					<span>{{ t('serverinfo', 'Recent activity') }}</span>
				</div>
				<div v-if="activity.installed" :class="$style.miniKpis">
					<div :class="$style.miniKpi">
						<div :class="$style.miniValue">{{ activity.last1h.toLocaleString() }}</div>
						<div :class="$style.miniLabel">{{ t('serverinfo', '1 h') }}</div>
					</div>
					<div :class="$style.miniKpi">
						<div :class="$style.miniValue">{{ activity.last24h.toLocaleString() }}</div>
						<div :class="$style.miniLabel">{{ t('serverinfo', '24 h') }}</div>
					</div>
					<div :class="$style.miniKpi">
						<div :class="$style.miniValue">{{ activity.last7d.toLocaleString() }}</div>
						<div :class="$style.miniLabel">{{ t('serverinfo', '7 d') }}</div>
					</div>
				</div>
				<p v-else :class="$style.empty">{{ t('serverinfo', 'Activity app not installed.') }}</p>

				<div :class="$style.divider" />

				<div :class="$style.panelHead">
					<IconConn :size="14" />
					<span>{{ t('serverinfo', 'Active connections') }}</span>
				</div>
				<div :class="$style.miniKpis">
					<div :class="$style.miniKpi">
						<div :class="$style.miniValue">{{ connections.last5min.toLocaleString() }}</div>
						<div :class="$style.miniLabel">{{ t('serverinfo', '5 min') }}</div>
					</div>
					<div :class="$style.miniKpi">
						<div :class="$style.miniValue">{{ connections.last1h.toLocaleString() }}</div>
						<div :class="$style.miniLabel">{{ t('serverinfo', '1 h') }}</div>
					</div>
					<div :class="$style.miniKpi">
						<div :class="$style.miniValue">{{ connections.totalTokens.toLocaleString() }}</div>
						<div :class="$style.miniLabel">{{ t('serverinfo', 'tokens') }}</div>
					</div>
				</div>
			</div>
		</div>
	</SectionCard>
</template>

<style module lang="scss">
.grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 12px;
}

.panel {
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.panelHead {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.72em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.userList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.userRow {
	display: grid;
	grid-template-columns: 110px 1fr 80px;
	gap: 8px;
	align-items: center;
	font-size: 0.82em;
}

.userName {
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.userBar {
	height: 6px;
	background: var(--color-background-darker);
	border-radius: 999px;
	overflow: hidden;
}

.userFill {
	height: 100%;
	background: linear-gradient(90deg,
		var(--color-primary-element),
		color-mix(in srgb, var(--color-primary-element) 60%, transparent));
	border-radius: 999px;
	transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.userSize {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	text-align: end;
}

.miniKpis {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 6px;
}

.miniKpi {
	background: var(--color-main-background);
	padding: 6px 10px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.miniValue {
	font-size: 1.1em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	line-height: 1.1;
}

.miniLabel {
	font-size: 0.7em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.divider {
	height: 1px;
	background-color: var(--color-border);
	margin: 4px 0;
}

.empty {
	margin: 0;
	font-size: 0.82em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
