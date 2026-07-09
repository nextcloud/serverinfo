<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section network-infos">
		<div class="row">
			<div class="col col-12">
				<SectionHeading :icon="Lan" :title="t('serverinfo', 'Network')" />
			</div>
			<div class="col col-12">
				{{ t('serverinfo', 'Hostname:') }}
				<span class="info">{{ networkinfo.hostname }}</span>
			</div>
			<div class="col col-12">
				{{ t('serverinfo', 'Gateway:') }}
				<span class="info">{{ networkinfo.gateway }}</span>
			</div>
			<div class="col col-12">
				{{ t('serverinfo', 'DNS:') }}
				<span class="info">{{ networkinfo.dns }}</span>
			</div>
			<div class="col col-12">
				<div class="row">
					<div
						v-for="iface in interfaces"
						:key="iface.name"
						class="col col-4 col-l-6 col-m-12">
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
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import Lan from 'vue-material-design-icons/Lan.vue'
import SectionHeading from './SectionHeading.vue'

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
