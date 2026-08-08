<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section monitoring">
		<SectionHeading :icon="Connection" :title="t('serverinfo', 'External monitoring API')" />

		<NcFormBox>
			<!-- TRANSLATORS: Label of a read-only field holding the URL an external monitoring tool should query -->
			<NcFormBoxCopyButton
				:label="t('serverinfo', 'Endpoint URL')"
				:value="endpointUrl" />
		</NcFormBox>

		<!-- TRANSLATORS: Heading above the switches that change the endpoint URL, noun -->
		<NcFormGroup :label="t('serverinfo', 'Configuration')">
			<NcFormBox>
				<!-- TRANSLATORS: Switch label, turns on JSON instead of XML as the response format -->
				<NcFormBoxSwitch
					v-model="formatJson"
					:label="t('serverinfo', 'Output in JSON')" />
				<!-- TRANSLATORS: Switch label, omits the list of installed apps from the response -->
				<NcFormBoxSwitch
					v-model="skipApps"
					:label="t('serverinfo', 'Skip apps section')"
					:description="t('serverinfo', 'Including the apps section sends an external request to the app store')" />
				<!-- TRANSLATORS: Switch label, omits the check for available server updates from the response -->
				<NcFormBoxSwitch
					v-model="skipUpdate"
					:label="t('serverinfo', 'Skip server update')" />
			</NcFormBox>
		</NcFormGroup>

		<!-- TRANSLATORS: Heading above the access token an external monitoring tool has to send, noun. {header} is the literal HTTP header name "NC-Token". -->
		<NcFormGroup
			:label="t('serverinfo', 'Authentication')"
			:description="t('serverinfo', 'This token was generated in your browser and is not stored until you run the command below. Send it in the {header} header with every request.', { header: TOKEN_HEADER })">
			<NcFormBox>
				<!-- TRANSLATORS: Label of a read-only field holding a shell command to copy and run on the server -->
				<NcFormBoxCopyButton
					:label="t('serverinfo', 'Command to store the token')"
					:value="`occ config:app:set serverinfo token --value ${suggestedToken}`" />
				<!-- TRANSLATORS: Label of a read-only field holding an HTTP header, noun — not an instruction to request something -->
				<NcFormBoxCopyButton
					:label="t('serverinfo', 'Request header')"
					:value="`${TOKEN_HEADER}: ${suggestedToken}`" />
			</NcFormBox>
		</NcFormGroup>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcFormBox from '@nextcloud/vue/components/NcFormBox'
import NcFormBoxCopyButton from '@nextcloud/vue/components/NcFormBoxCopyButton'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcFormGroup from '@nextcloud/vue/components/NcFormGroup'
import Connection from 'vue-material-design-icons/Connection.vue'
import SectionHeading from './SectionHeading.vue'

const props = defineProps<{ ocs: string }>()

const TOKEN_HEADER = 'NC-Token'

// Suggesting a strong token beats "yourtoken", which invites a weak one. Held
// in a const so it stays stable while the page is open; the admin still has to
// run occ, nothing is stored from here.
const suggestedToken = Array.from(
	crypto.getRandomValues(new Uint8Array(32)),
	(byte) => byte.toString(16).padStart(2, '0'),
).join('')

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

<style scoped>
/* NcFormGroup spaces its own contents, but not the groups against each other. */
.monitoring {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}
</style>
