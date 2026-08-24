<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<SectionHeading :icon="LanguagePhp" :title="t('serverinfo', 'PHP')" />
	<div class="row row--tiles">
		<StatTile :label="t('serverinfo', 'Version')" :value="php.version" />
		<StatTile :label="t('serverinfo', 'Memory limit')" :value="formatBytes(php.memoryLimit)" />
	</div>
	<div class="server-info-table">
		<table>
			<tbody>
				<tr>
					<td>{{ t('serverinfo', 'Max execution time:') }}</td>
					<td class="info">
						{{ php.maxExecutionTime }} {{ t('serverinfo', 'seconds') }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Upload max size:') }}</td>
					<td class="info">
						{{ formatBytes(php.uploadMaxFilesize) }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Post max size:') }}</td>
					<td class="info">
						{{ formatBytes(php.postMaxSize) }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'SAPI:') }}</td>
					<td class="info">
						{{ php.sapi }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Extensions:') }}</td>
					<td class="info">
						<NcButton
							class="extensions-button"
							variant="tertiary"
							size="small"
							@click="showExtensions = true">
							{{ extensionsCountText }}
						</NcButton>
					</td>
				</tr>
				<tr v-if="phpinfo">
					<td>{{ t('serverinfo', 'PHP Info:') }}</td>
					<td>
						<a
							class="info"
							target="_blank"
							rel="noopener noreferrer"
							:href="phpinfoUrl">{{ t('serverinfo', 'Show phpinfo') }}</a>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<template v-if="fpm !== false">
		<SectionHeading :icon="Cogs" :title="t('serverinfo', 'FPM worker pool')" />
		<div class="server-info-table">
			<table>
				<tbody>
					<tr>
						<td>{{ t('serverinfo', 'Pool name:') }}</td>
						<td class="info">
							{{ fpm.pool }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Pool type:') }}</td>
						<td class="info">
							{{ fpm['process-manager'] }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Start time:') }}</td>
						<td class="info">
							{{ fpm['start-time'] }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Accepted connections:') }}</td>
						<td class="info">
							{{ fpm['accepted-conn'] }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Total processes:') }}</td>
						<td class="info">
							{{ fpm['total-processes'] }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Active processes:') }}</td>
						<td class="info">
							{{ fpm['active-processes'] }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Idle processes:') }}</td>
						<td class="info">
							{{ fpm['idle-processes'] }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Listen queue:') }}</td>
						<td class="info">
							{{ fpm['listen-queue'] }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Slow requests:') }}</td>
						<td class="info">
							{{ fpm['slow-requests'] }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Max listen queue:') }}</td>
						<td class="info">
							{{ fpm['max-listen-queue'] }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Max active processes:') }}</td>
						<td class="info">
							{{ fpm['max-active-processes'] }}
						</td>
					</tr>
					<tr>
						<td>{{ t('serverinfo', 'Max children reached:') }}</td>
						<td class="info">
							{{ fpm['max-children-reached'] }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</template>

	<PhpExtensionsDialog
		v-if="showExtensions"
		:extensions="php.extensions"
		@close="showExtensions = false" />
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import Cogs from 'vue-material-design-icons/Cogs.vue'
import LanguagePhp from 'vue-material-design-icons/LanguagePhp.vue'
import PhpExtensionsDialog from './PhpExtensionsDialog.vue'
import SectionHeading from './SectionHeading.vue'
import StatTile from './StatTile.vue'
import { formatBytes } from '../utils.ts'

const props = defineProps<{
	php: {
		version: string
		sapi: string
		memoryLimit: number
		maxExecutionTime: number
		uploadMaxFilesize: number
		postMaxSize: number
		extensions: string[] | null
	}
	fpm: {
		pool: string
		'process-manager': string
		'start-time': string
		'accepted-conn': number
		'total-processes': number
		'active-processes': number
		'idle-processes': number
		'listen-queue': number
		'slow-requests': number
		'max-listen-queue': number
		'max-active-processes': number
		'max-children-reached': number
	} | false
	phpinfo: boolean
	phpinfoUrl: string
}>()

const showExtensions = ref(false)

const extensionsCountText = computed(() => props.php.extensions === null
	// TRANSLATORS: Shown when PHP forbids listing its loaded extensions
	? t('serverinfo', 'Unable to list extensions')
	// TRANSLATORS: {count} is how many PHP extensions are loaded
	: t('serverinfo', '{count} loaded', { count: props.php.extensions.length }))
</script>

<style scoped>
.extensions-button {
	/* Cancels NcButton's own inline padding (2x grid baseline + element radius)
	   so its label lines up with the plain text in the rows above it. */
	margin-inline-start: calc(-2 * var(--default-grid-baseline) - var(--border-radius-element));
}
</style>
