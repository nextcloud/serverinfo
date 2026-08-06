<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section network-infos">
		<div class="row">
			<div class="col col-12">
				<SectionHeading :icon="AccountGroup" :title="t('serverinfo', 'Active users')" />
			</div>
			<div class="col col-12">
				<div class="row">
					<div class="col">
						<div class="infobox">
							<div class="interface-wrapper active-users-wrapper">
								<div v-if="activeUsers.last1hour > 0" class="active-users-box">
									{{ t('serverinfo', 'Last hour') }}<br>
									<span class="info">{{ activeUsers.last1hour }}</span><br>
									<em>{{ percentageOfAllUsers(activeUsers.last1hour) }}</em>
								</div>
								<div v-if="activeUsers.last24hours > 0" class="active-users-box">
									{{ t('serverinfo', 'Last 24 Hours') }}<br>
									<span class="info">{{ activeUsers.last24hours }}</span><br>
									<em>{{ percentageOfAllUsers(activeUsers.last24hours) }}</em>
								</div>
								<div v-if="activeUsers.last7days > 0" class="active-users-box">
									{{ t('serverinfo', 'Last 7 Days') }}<br>
									<span class="info">{{ activeUsers.last7days }}</span><br>
									<em>{{ percentageOfAllUsers(activeUsers.last7days) }}</em>
								</div>
								<div v-if="activeUsers.last1month > 0" class="active-users-box">
									{{ t('serverinfo', 'Last 30 Days') }}<br>
									<span class="info">{{ activeUsers.last1month }}</span><br>
									<em>{{ percentageOfAllUsers(activeUsers.last1month) }}</em>
								</div>
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
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import SectionHeading from './SectionHeading.vue'

const props = defineProps<{
	activeUsers: { last1hour: number, last24hours: number, last7days: number, last1month: number }
	numUsers: number
}>()

/**
 * Format the active-user count as its share of all users, e.g. "3% of all users".
 *
 * @param count number of active users in the period
 */
function percentageOfAllUsers(count: number): string {
	const percentage = props.numUsers === 0 ? 0 : Math.round(count * 1000 / props.numUsers) / 10
	return t('serverinfo', '{0}% of all users', [String(percentage)])
}
</script>
