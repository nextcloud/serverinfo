<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section network-infos">
		<div class="row">
			<div class="col col-12">
				<SectionHeading :icon="ShareVariant" :title="t('serverinfo', 'Shares')" />
			</div>
			<div class="col col-12">
				<div class="row">
					<div class="col col-4 col-l-6 col-m-12">
						<div class="infobox">
							<div class="interface-wrapper">
								<template v-if="shares.num_shares_user > 0">
									{{ t('serverinfo', 'Users:') }}
									<span class="info">{{ shares.num_shares_user }}</span><br>
								</template>
								<template v-if="shares.num_shares_groups > 0">
									{{ t('serverinfo', 'Groups:') }}
									<span class="info">{{ shares.num_shares_groups }}</span><br>
								</template>
								<template v-if="shares.num_shares_link > 0">
									{{ t('serverinfo', 'Links:') }}
									<span class="info">{{ shares.num_shares_link }}</span><br>
								</template>
								<template v-if="shares.num_shares_mail > 0">
									{{ t('serverinfo', 'Emails:') }}
									<span class="info">{{ shares.num_shares_mail }}</span><br>
								</template>
								<template v-if="shares.num_fed_shares_sent > 0">
									{{ t('serverinfo', 'Federated sent:') }}
									<span class="info">{{ shares.num_fed_shares_sent }}</span><br>
								</template>
								<template v-if="shares.num_fed_shares_received > 0">
									{{ t('serverinfo', 'Federated received:') }}
									<span class="info">{{ shares.num_fed_shares_received }}</span><br>
								</template>
								<template v-if="shares.num_shares_room > 0">
									{{ t('serverinfo', 'Talk conversations:') }}
									<span class="info">{{ shares.num_shares_room }}</span><br>
								</template>
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
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import SectionHeading from './SectionHeading.vue'

defineProps<{
	shares: {
		num_shares: number
		num_shares_user: number
		num_shares_groups: number
		num_shares_link: number
		num_shares_mail: number
		num_fed_shares_sent: number
		num_fed_shares_received: number
		num_shares_room: number
	}
}>()
</script>
