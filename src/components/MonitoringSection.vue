<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section monitoring">
		<SectionHeading :icon="Connection" :title="t('serverinfo', 'External monitoring tool')" />
		<p>{{ t('serverinfo', 'Use this end point to connect an external monitoring tool:') }}</p>

		<div class="monitoring-wrapper">
			<input type="text" readonly :value="endpointUrl">
		</div>

		<div class="monitoring-url-params">
			<div class="monitoring-url-param">
				<input
					id="format_json"
					v-model="formatJson"
					type="checkbox"
					class="update-monitoring-endpoint-url"
					name="format_json">
				<label for="format_json">{{ t('serverinfo', 'Output in JSON') }}</label>
			</div>
			<div class="monitoring-url-param">
				<input
					id="skip_apps"
					v-model="skipApps"
					type="checkbox"
					class="update-monitoring-endpoint-url"
					name="skip_apps">
				<label for="skip_apps">{{ t('serverinfo', 'Skip apps section (including apps section will send an external request to the app store)') }}</label>
			</div>
			<div class="monitoring-url-param">
				<input
					id="skip_update"
					v-model="skipUpdate"
					type="checkbox"
					class="update-monitoring-endpoint-url"
					name="skip_update">
				<label for="skip_update">{{ t('serverinfo', 'Skip server update') }}</label>
			</div>
		</div>

		<p>{{ t('serverinfo', 'To use an access token, please generate one then set it using the following command:') }}</p>
		<div>
			<i>occ config:app:set serverinfo token --value yourtoken</i>
		</div>
		<p>{{ t('serverinfo', 'Then pass the token with the "NC-Token" header when querying the above URL.') }}</p>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import Connection from 'vue-material-design-icons/Connection.vue'
import SectionHeading from './SectionHeading.vue'

const props = defineProps<{ ocs: string }>()

const formatJson = ref(false)
const skipApps = ref(true)
const skipUpdate = ref(true)

const endpointUrl = computed(() => {
	try {
		const url = new URL(props.ocs)
		if (formatJson.value) {
			url.searchParams.set('format', 'json')
		}
		if (!skipApps.value) {
			url.searchParams.set('skipApps', 'false')
		}
		if (!skipUpdate.value) {
			url.searchParams.set('skipUpdate', 'false')
		}
		return url.toString()
	} catch {
		// props.ocs is not a valid absolute URL; fall back to showing it as-is.
		return props.ocs
	}
})
</script>
