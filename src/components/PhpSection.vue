<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<SectionHeading :icon="LanguagePhp" :title="t('serverinfo', 'PHP')" />
	<div class="server-info-table">
		<table>
			<tbody>
				<tr>
					<td>{{ t('serverinfo', 'Version:') }}</td>
					<td class="info">
						{{ php.version }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Memory limit:') }}</td>
					<td class="info">
						{{ formatBytes(php.memory_limit) }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Max execution time:') }}</td>
					<td class="info">
						{{ php.max_execution_time }} {{ t('serverinfo', 'seconds') }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Upload max size:') }}</td>
					<td class="info">
						{{ formatBytes(php.upload_max_filesize) }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'OPcache Revalidate Frequency:') }}</td>
					<td class="info">
						{{ php.opcache_revalidate_freq }} {{ t('serverinfo', 'seconds') }}
					</td>
				</tr>
				<tr>
					<td>{{ t('serverinfo', 'Extensions:') }}</td>
					<td class="info">
						<div class="server-info__tag-wrapper">
							<template v-if="php.extensions">
								<span
									v-for="ext in php.extensions"
									:key="ext"
									class="server-info__php-extension-tag">{{ ext }}</span>
							</template>
							<template v-else>
								{{ t('serverinfo', 'Unable to list extensions') }}
							</template>
						</div>
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
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import Cogs from 'vue-material-design-icons/Cogs.vue'
import LanguagePhp from 'vue-material-design-icons/LanguagePhp.vue'
import SectionHeading from './SectionHeading.vue'
import { formatBytes } from '../utils.ts'

defineProps<{
	php: {
		version: string
		memory_limit: number
		max_execution_time: number
		upload_max_filesize: number
		opcache_revalidate_freq: number
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
</script>
