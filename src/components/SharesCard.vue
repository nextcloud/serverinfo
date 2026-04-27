<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import IconShare from 'vue-material-design-icons/ShareVariant.vue'
import IconUser from 'vue-material-design-icons/AccountOutline.vue'
import IconGroup from 'vue-material-design-icons/AccountGroupOutline.vue'
import IconLink from 'vue-material-design-icons/LinkVariant.vue'
import IconMail from 'vue-material-design-icons/EmailOutline.vue'
import IconRoom from 'vue-material-design-icons/Forum.vue'
import IconFedSent from 'vue-material-design-icons/SendOutline.vue'
import IconFedReceived from 'vue-material-design-icons/InboxArrowDownOutline.vue'
import SectionCard from './SectionCard.vue'
import type { ShareStats } from '../types.ts'

const props = defineProps<{
	shares: ShareStats
}>()

interface ShareTile {
	icon: typeof IconUser
	label: string
	count: number
}

const tiles = computed<ShareTile[]>(() => {
	const list: ShareTile[] = []
	const s = props.shares
	if (s.num_shares_user > 0) {
		list.push({ icon: IconUser, label: t('serverinfo', 'User'), count: s.num_shares_user })
	}
	if (s.num_shares_groups > 0) {
		list.push({ icon: IconGroup, label: t('serverinfo', 'Group'), count: s.num_shares_groups })
	}
	if (s.num_shares_link > 0) {
		list.push({ icon: IconLink, label: t('serverinfo', 'Link'), count: s.num_shares_link })
	}
	if (s.num_shares_mail > 0) {
		list.push({ icon: IconMail, label: t('serverinfo', 'Email'), count: s.num_shares_mail })
	}
	if (s.num_shares_room > 0) {
		list.push({ icon: IconRoom, label: t('serverinfo', 'Talk'), count: s.num_shares_room })
	}
	if (s.num_fed_shares_sent > 0) {
		list.push({ icon: IconFedSent, label: t('serverinfo', 'Federated sent'), count: s.num_fed_shares_sent })
	}
	if (s.num_fed_shares_received > 0) {
		list.push({ icon: IconFedReceived, label: t('serverinfo', 'Federated received'), count: s.num_fed_shares_received })
	}
	return list
})
</script>

<template>
	<SectionCard v-if="shares.num_shares > 0">
		<template #header>
			<div class="title-with-icon">
				<IconShare :size="18" />
				<span>{{ t('serverinfo', 'Shares') }}</span>
			</div>
		</template>

		<div :class="$style.summary">
			<span :class="$style.totalNumber">{{ shares.num_shares.toLocaleString() }}</span>
			<span :class="$style.totalLabel">{{ t('serverinfo', 'shares in total') }}</span>
		</div>

		<div :class="$style.grid">
			<div v-for="tile in tiles" :key="tile.label" :class="$style.tile">
				<component :is="tile.icon" :size="22" :class="$style.tileIcon" />
				<div>
					<div :class="$style.tileValue">{{ tile.count.toLocaleString() }}</div>
					<div :class="$style.tileLabel">{{ tile.label }}</div>
				</div>
			</div>
		</div>
	</SectionCard>
</template>

<style module lang="scss">
.summary {
	display: flex;
	align-items: baseline;
	gap: 8px;
}

.totalNumber {
	font-size: 1.5em;
	font-weight: 700;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	line-height: 1.1;
}

.totalLabel {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
	gap: 6px;
}

.tile {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 10px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.tileIcon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.tileValue {
	font-size: 1em;
	font-weight: 600;
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	line-height: 1.1;
}

.tileLabel {
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
}
</style>
