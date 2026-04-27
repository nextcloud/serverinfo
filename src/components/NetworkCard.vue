<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconNetwork from 'vue-material-design-icons/LanConnect.vue'
import IconLoopback from 'vue-material-design-icons/Reload.vue'
import SectionCard from './SectionCard.vue'
import StatusPill from './StatusPill.vue'
import type { NetworkInfo, NetworkInterfaceInfo } from '../types.ts'

const props = defineProps<{
	networkInfo: NetworkInfo
	interfaces: NetworkInterfaceInfo[]
}>()

const primaryIp = computed(() => {
	for (const iface of props.interfaces) {
		if (iface.loopback || !iface.up) {
			continue
		}
		if (iface.ipv4.length > 0) {
			return iface.ipv4[0]
		}
	}
	for (const iface of props.interfaces) {
		if (iface.loopback || !iface.up) {
			continue
		}
		if (iface.ipv6.length > 0) {
			return iface.ipv6[0]
		}
	}
	return '–'
})
</script>

<template>
	<SectionCard>
		<template #header>
			<div class="title-with-icon">
				<IconNetwork :size="18" />
				<span>{{ t('serverinfo', 'Network') }}</span>
			</div>
		</template>

		<dl :class="$style.summary">
			<div :class="$style.summaryItem">
				<dt>{{ t('serverinfo', 'Hostname') }}</dt>
				<dd>{{ networkInfo.hostname }}</dd>
			</div>
			<div :class="$style.summaryItem">
				<dt>{{ t('serverinfo', 'IP') }}</dt>
				<dd><code :class="$style.code">{{ primaryIp }}</code></dd>
			</div>
			<div :class="$style.summaryItem">
				<dt>{{ t('serverinfo', 'Gateway') }}</dt>
				<dd><code :class="$style.code">{{ networkInfo.gateway || '–' }}</code></dd>
			</div>
			<div :class="$style.summaryItem">
				<dt>{{ t('serverinfo', 'DNS') }}</dt>
				<dd><code :class="$style.code">{{ networkInfo.dns || '–' }}</code></dd>
			</div>
		</dl>

		<div :class="$style.grid">
			<article
				v-for="iface in interfaces"
				:key="iface.name"
				:class="$style.iface">
				<header :class="$style.ifaceHeader">
					<h3 :class="$style.ifaceName">
						<IconLoopback v-if="iface.loopback" :size="14" />
						{{ iface.name }}
					</h3>
					<StatusPill
						:status="iface.up ? 'ok' : 'critical'"
						:label="iface.up ? t('serverinfo', 'Up') : t('serverinfo', 'Down')" />
				</header>
				<dl :class="$style.ifaceMeta">
					<div v-if="iface.speed && iface.speed !== 'unknown'">
						<dt>{{ t('serverinfo', 'Speed') }}</dt>
						<dd>{{ iface.speed }} <span :class="$style.muted">({{ iface.duplex }})</span></dd>
					</div>
					<div v-if="iface.mac">
						<dt>{{ t('serverinfo', 'MAC') }}</dt>
						<dd><code>{{ iface.mac }}</code></dd>
					</div>
					<div v-if="iface.ipv4.length > 0">
						<dt>{{ t('serverinfo', 'IPv4') }}</dt>
						<dd>
							<code v-for="ip in iface.ipv4" :key="ip" :class="$style.ipChip">{{ ip }}</code>
						</dd>
					</div>
					<div v-if="iface.ipv6.length > 0">
						<dt>{{ t('serverinfo', 'IPv6') }}</dt>
						<dd>
							<code v-for="ip in iface.ipv6" :key="ip" :class="$style.ipChip">{{ ip }}</code>
						</dd>
					</div>
				</dl>
			</article>
		</div>
	</SectionCard>
</template>

<style module lang="scss">
.summary {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 20px;
	margin: 0;
	padding-bottom: 8px;
	border-bottom: 1px solid var(--color-border);
}

.summaryItem {
	display: flex;
	align-items: baseline;
	gap: 8px;
	min-width: 0;

	dt {
		color: var(--color-text-maxcontrast);
		font-size: 0.78em;
		text-transform: uppercase;
		letter-spacing: 0.05em;
		font-weight: 600;
		flex-shrink: 0;
	}

	dd {
		margin: 0;
		font-size: 0.9em;
		font-weight: 600;
		color: var(--color-main-text);
		font-variant-numeric: tabular-nums;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
}

.code {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.95em;
}

.grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 8px;
}

.iface {
	padding: 10px 12px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ifaceHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.ifaceName {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0;
	font-size: 0.92em;
	font-weight: 600;
	color: var(--color-main-text);
	font-family: var(--font-face-monospace, monospace);
}

.ifaceMeta {
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;

	div {
		display: grid;
		grid-template-columns: 56px 1fr;
		gap: 8px;
		align-items: baseline;
	}

	dt {
		color: var(--color-text-maxcontrast);
		font-size: 0.78em;
	}

	dd {
		margin: 0;
		font-size: 0.82em;
		display: flex;
		flex-wrap: wrap;
		gap: 3px;
	}
}

.ipChip {
	display: inline-block;
	padding: 0 7px;
	border-radius: 999px;
	background-color: var(--color-background-darker);
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.78em;
}

.muted {
	color: var(--color-text-maxcontrast);
}
</style>
