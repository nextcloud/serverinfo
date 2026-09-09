<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- TRANSLATORS: Accessible name of the switch between the two Monitoring pages, noun -->
	<NcRadioGroup
		v-model="view"
		class="settings-navigation"
		:label="t('serverinfo', 'Monitoring pages')"
		labelHidden>
		<!-- TRANSLATORS: Name of the page showing the server's current state: CPU, memory, disks, network, noun -->
		<NcRadioGroupButton value="status" :label="t('serverinfo', 'Status')">
			<template #icon>
				<Monitor :size="20" />
			</template>
		</NcRadioGroupButton>
		<!-- TRANSLATORS: Name of the page showing how the server's background jobs are doing, noun -->
		<NcRadioGroupButton value="background-jobs" :label="t('serverinfo', 'Background jobs')">
			<template #icon>
				<Update :size="20" />
			</template>
		</NcRadioGroupButton>
	</NcRadioGroup>
</template>

<script setup lang="ts">
import type { SettingsView } from '../types.ts'

import { t } from '@nextcloud/l10n'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import Monitor from 'vue-material-design-icons/Monitor.vue'
import Update from 'vue-material-design-icons/Update.vue'

const view = defineModel<SettingsView>({ required: true })
</script>

<style scoped>
.settings-navigation {
	/* Core floats #app-navigation-toggle, the button that collapses the settings
	   navigation, over the top-inline-start corner of the content area. Its own
	   sections clear it vertically with their 30px padding rather than dodging it
	   sideways, so do the same: reserve the toggle's height above the switch and
	   line the buttons up with the section content below. */
	width: fit-content;
	padding-block-start: var(--default-clickable-area, 44px);
	padding-inline-start: 30px;
	margin-block-end: 12px;
}

/* "Background jobs" otherwise wraps onto a second line and leaves the two
   buttons looking mismatched. */
.settings-navigation :deep([class*='radioGroupButton']) {
	white-space: nowrap;
}
</style>
