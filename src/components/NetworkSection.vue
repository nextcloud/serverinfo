<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section network-infos">
		<SectionHeading :icon="Lan" :title="t('serverinfo', 'Network')" />
		<div class="row row--tiles">
			<!-- TRANSLATORS: Tile label above the network host name of the server -->
			<StatTile :label="t('serverinfo', 'Hostname')" :value="networkinfo.hostname" />
			<!-- TRANSLATORS: Tile label above the IP address of the default network gateway -->
			<StatTile :label="t('serverinfo', 'Gateway')" :value="networkinfo.gateway" />
			<!-- TRANSLATORS: Tile label above the IP addresses of the configured DNS name servers -->
			<StatTile :label="t('serverinfo', 'DNS')" :value="networkinfo.dns" />
		</div>
		<div class="row row--cards">
			<div v-for="iface in interfaces" :key="iface.name">
				<div class="infobox">
					<div class="interface-wrapper">
						<h3>{{ iface.name }}</h3>
						{{ t('serverinfo', 'Status:') }}
						<span class="info">{{ iface.up ? 'up' : 'down' }}</span><br>
						{{ t('serverinfo', 'Speed:') }}
						<span class="info">{{ iface.speed }} ({{ t('serverinfo', 'Duplex:') }} {{ iface.duplex }})</span><br>
						<template v-if="iface.mac">
							{{ t('serverinfo', 'MAC:') }}
							<span class="info">{{ iface.mac }}</span><br>
						</template>
						{{ t('serverinfo', 'IPv4:') }}
						<span class="info">{{ iface.ipv4.join(', ') }}</span><br>
						{{ t('serverinfo', 'IPv6:') }}
						<span class="info">{{ iface.ipv6.join(', ') }}</span>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import Lan from 'vue-material-design-icons/Lan.vue'
import SectionHeading from './SectionHeading.vue'
import StatTile from './StatTile.vue'

defineProps<{
	networkinfo: { hostname: string, gateway: string, dns: string }
	interfaces: Array<{
		name: string
		up: boolean
		ipv4: string[]
		ipv6: string[]
		mac: string
		speed: string
		duplex: string
		loopback: boolean
	}>
}>()
</script>
