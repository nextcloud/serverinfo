<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<SectionHeading :icon="Puzzle" :title="t('serverinfo', 'PHP extensions')" />
	<div class="server-info__tag-wrapper">
		<template v-if="extensions">
			<span
				v-for="ext in extensions"
				:key="ext"
				class="server-info__php-extension-tag">{{ ext }}</span>
		</template>
		<template v-else>
			{{ t('serverinfo', 'Unable to list extensions') }}
		</template>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import Puzzle from 'vue-material-design-icons/Puzzle.vue'
import SectionHeading from './SectionHeading.vue'

defineProps<{
	/** Loaded extensions, or null when the platform would not list them */
	extensions: string[] | null
}>()
</script>
