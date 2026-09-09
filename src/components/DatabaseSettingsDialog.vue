<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:name="t('serverinfo', 'Database connection')"
		:open="true"
		size="normal"
		@update:open="$emit('close')">
		<div class="db-settings">
			<p class="db-settings__intro">
				<!-- TRANSLATORS: Explains the optional database connection used only for the checks -->
				{{ t('serverinfo', 'By default the checks use the same connection as Nextcloud. Give a separate connection here to run them as a user with the rights Nextcloud does not need, for example to read the full server configuration. Leave the host empty to use the default connection.') }}
			</p>

			<NcTextField v-model="form.host" :label="t('serverinfo', 'Host')" />
			<NcTextField v-model="portText" type="number" :label="t('serverinfo', 'Port')" />
			<NcTextField v-model="form.user" :label="t('serverinfo', 'User')" />
			<NcTextField
				v-model="password"
				type="password"
				:label="form.passwordSet ? t('serverinfo', 'Password (stored, leave empty to keep)') : t('serverinfo', 'Password')" />
			<NcTextField v-model="form.database" :label="t('serverinfo', 'Database name')" />

			<NcSelect
				v-model="driver"
				:options="driverOptions"
				:clearable="false"
				:inputLabel="t('serverinfo', 'Driver')" />

			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('serverinfo', 'Cancel') }}
			</NcButton>
			<NcButton variant="secondary" :disabled="busy || form.host === ''" @click="test">
				<!-- TRANSLATORS: Button that dials the given database connection without saving it -->
				{{ t('serverinfo', 'Test connection') }}
			</NcButton>
			<NcButton variant="primary" :disabled="busy" @click="save">
				{{ t('serverinfo', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import type { DatabaseOverride } from '../types.ts'

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const props = defineProps<{ override: DatabaseOverride }>()

const emit = defineEmits<{ close: [], saved: [] }>()

const driverOptions = [
	{ id: 'pdo_mysql', label: 'MySQL / MariaDB' },
	{ id: 'pdo_pgsql', label: 'PostgreSQL' },
]

const form = ref({ ...props.override })
const password = ref('')
const busy = ref(false)
const message = ref('')
const messageType = ref<'success' | 'error'>('success')

const portText = computed({
	get: () => String(form.value.port || ''),
	set: (value: string) => {
		form.value.port = Number.parseInt(value, 10) || 0
	},
})

const driver = computed({
	get: () => driverOptions.find((option) => option.id === form.value.driver) ?? driverOptions[0],
	set: (option: { id: string }) => {
		form.value.driver = option.id
	},
})

/**
 * Reports the outcome of the last action inside the dialog.
 *
 * @param text what to say
 * @param type whether it went well
 */
function report(text: string, type: 'success' | 'error') {
	message.value = text
	messageType.value = type
}

async function test() {
	busy.value = true
	try {
		const response = await axios.post(generateUrl('/apps/serverinfo/database/test-connection'), {
			host: form.value.host,
			port: form.value.port,
			user: form.value.user,
			password: password.value,
			database: form.value.database,
			driver: form.value.driver,
		})
		report(
			response.data.ok
				// TRANSLATORS: Result of a successful connection test. {version} is the database server version.
				? t('serverinfo', 'Connected. Server version: {version}', { version: response.data.version ?? '' })
				: response.data.message,
			response.data.ok ? 'success' : 'error',
		)
	} catch (error) {
		report(String(error), 'error')
	} finally {
		busy.value = false
	}
}

async function save() {
	busy.value = true
	try {
		await axios.put(generateUrl('/apps/serverinfo/database/settings'), {
			host: form.value.host,
			port: form.value.port,
			user: form.value.user,
			database: form.value.database,
			driver: form.value.driver,
			// An empty box means "keep what is stored", except when the host is
			// being cleared, which takes the stored password with it.
			password: password.value === '' ? null : password.value,
			clearPassword: form.value.host === '',
		})
		emit('saved')
		emit('close')
	} catch (error) {
		report(String(error), 'error')
	} finally {
		busy.value = false
	}
}
</script>

<style scoped>
.db-settings {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.db-settings__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
