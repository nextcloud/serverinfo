<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import IconFed from 'vue-material-design-icons/AccountNetwork.vue'
import IconSent from 'vue-material-design-icons/SendOutline.vue'
import IconReceived from 'vue-material-design-icons/InboxArrowDownOutline.vue'
import IconServers from 'vue-material-design-icons/ServerNetwork.vue'
import SectionCard from './SectionCard.vue'
import type { FederationInfo } from '../types.ts'

defineProps<{
	federation: FederationInfo
}>()
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconFed :size="18" />
				<span>{{ t('serverinfo', 'Federation') }}</span>
			</div>
		</template>

		<p v-if="!federation.enabled" :class="$style.note">
			{{ t('serverinfo', 'Federation apps not installed.') }}
		</p>

		<template v-else>
			<div :class="$style.kpis">
				<div :class="$style.kpi">
					<IconSent :size="18" :class="$style.icon" />
					<div>
						<div :class="$style.value">{{ federation.sharesSent.toLocaleString() }}</div>
						<div :class="$style.label">{{ t('serverinfo', 'Shares sent') }}</div>
					</div>
				</div>
				<div :class="$style.kpi">
					<IconReceived :size="18" :class="$style.icon" />
					<div>
						<div :class="$style.value">{{ federation.sharesReceived.toLocaleString() }}</div>
						<div :class="$style.label">{{ t('serverinfo', 'Shares received') }}</div>
					</div>
				</div>
				<div :class="$style.kpi">
					<IconServers :size="18" :class="$style.icon" />
					<div>
						<div :class="$style.value">{{ federation.trustedServers.toLocaleString() }}</div>
						<div :class="$style.label">{{ t('serverinfo', 'Trusted servers') }}</div>
					</div>
				</div>
			</div>

			<div v-if="federation.topPeers.length > 0">
				<div :class="$style.subLabel">{{ t('serverinfo', 'Most-shared-with peers') }}</div>
				<ul :class="$style.peerList">
					<li v-for="p in federation.topPeers" :key="p.server" :class="$style.peerRow">
						<code :class="$style.peer">{{ p.server }}</code>
						<span :class="$style.peerCount">{{ p.count }}</span>
					</li>
				</ul>
			</div>
		</template>
	</SectionCard>
</template>

<style module lang="scss">
.note {
	margin: 0;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-style: italic;
}

.kpis {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 8px;
}

.kpi {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.icon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.value {
	font-size: 1.2em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	line-height: 1.1;
}

.label {
	font-size: 0.72em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.subLabel {
	font-size: 0.7em;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 6px;
}

.peerList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.peerRow {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	font-size: 0.85em;
}

.peer {
	font-family: var(--font-face-monospace, monospace);
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}

.peerCount {
	font-variant-numeric: tabular-nums;
	color: var(--color-primary-element);
	font-weight: 700;
}
</style>
