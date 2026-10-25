<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="section os-updates">
		<SectionHeading :icon="PackageUp" :title="t('serverinfo', 'Pending updates')" />

		<NcNoteCard v-if="status.updates === null" type="warning">
			<p>
				<!-- TRANSLATORS: Shown when the package manager could not be asked for pending updates -->
				{{ t('serverinfo', 'Pending updates could not be determined. The diagnostics at the bottom of the page say why.') }}
			</p>
			<template v-if="status.helper.state !== 'ok'">
				<p>{{ t('serverinfo', 'The root helper can report them instead.') }}</p>
				<NcButton @click="$emit('helper')">
					{{ t('serverinfo', 'Set up the root helper') }}
				</NcButton>
			</template>
		</NcNoteCard>

		<template v-else>
			<div class="row row--tiles os-updates__tiles">
				<StatTile
					v-if="status.securityClassified"
					:label="t('serverinfo', 'Security')"
					:value="security.length"
					:status="securityStatus" />
				<StatTile
					:label="status.securityClassified ? t('serverinfo', 'Other') : t('serverinfo', 'Updates')"
					:value="others.length + status.updatesOmitted" />
				<StatTile
					:label="t('serverinfo', 'Nextcloud stack')"
					:value="stack.length" />
				<StatTile
					:label="t('serverinfo', 'Held back')"
					:value="status.held === null ? '–' : status.held.length" />
			</div>

			<NcEmptyContent
				v-if="status.updates.length === 0"
				class="os-updates__empty"
				:name="t('serverinfo', 'Everything is up to date')"
				:description="listsDescription">
				<template #icon>
					<CheckCircle />
				</template>
			</NcEmptyContent>

			<template v-else>
				<FilterPills v-model="filter" :label="t('serverinfo', 'Filter updates')" :options="filters" />

				<div class="os-updates__table-wrapper">
					<table class="os-updates__table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('serverinfo', 'Package') }}
								</th>
								<th scope="col">
									{{ t('serverinfo', 'Version') }}
								</th>
								<th scope="col" class="os-updates__origin">
									{{ t('serverinfo', 'Source') }}
								</th>
								<th scope="col">
									{{ t('serverinfo', 'Waiting') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="update in visible" :key="update.name + update.candidate">
								<td>
									<div class="os-updates__name">
										<ShieldAlert
											v-if="update.security"
											class="os-updates__shield"
											:size="18"
											:title="t('serverinfo', 'Security update')" />
										<span class="os-updates__package">{{ update.name }}</span>
										<span v-if="update.kind === 'patch'" class="os-tag">
											<!-- TRANSLATORS: Tag for a SUSE patch, which bundles updates to several packages -->
											{{ t('serverinfo', 'patch') }}
										</span>
										<span v-if="update.stack" class="os-tag os-tag--primary">
											<!-- TRANSLATORS: Tag for packages Nextcloud itself relies on, such as PHP or the database -->
											{{ t('serverinfo', 'Nextcloud stack') }}
										</span>
										<span v-if="update.severity" class="os-tag" :class="severityClass(update.severity)">
											{{ update.severity }}
										</span>
									</div>
									<div v-if="advisories(update).length" class="os-updates__advisories">
										{{ advisories(update).join(', ') }}
									</div>
								</td>
								<td v-if="update.kind === 'patch'" class="os-updates__summary">
									{{ update.summary }}
								</td>
								<td v-else class="os-updates__version">
									<template v-if="update.installed">
										<span class="os-updates__old">{{ update.installed }}</span>
										<span aria-hidden="true"> → </span>
										<span class="hidden-visually">{{ t('serverinfo', 'to') }}</span>
									</template>
									<span class="os-updates__new">{{ update.candidate }}</span>
								</td>
								<td class="os-updates__origin">
									{{ update.origin ?? '' }}
								</td>
								<td class="os-updates__waiting">
									{{ formatDays(daysSince(now, update.since)) }}
								</td>
							</tr>
							<tr v-if="visible.length === 0">
								<td colspan="4" class="os-updates__none">
									{{ t('serverinfo', 'No pending update matches this filter.') }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<NcButton v-if="filtered.length > limit" variant="tertiary" @click="expanded = !expanded">
					<template #icon>
						<ChevronUp v-if="expanded" :size="20" />
						<ChevronDown v-else :size="20" />
					</template>
					{{ expanded
						? t('serverinfo', 'Show fewer')
						: n('serverinfo', 'Show all %n update', 'Show all %n updates', filtered.length) }}
				</NcButton>
				<p v-if="status.updatesOmitted > 0" class="os-muted">
					{{ n('serverinfo', '%n more update is not listed.', '%n more updates are not listed.', status.updatesOmitted) }}
				</p>
				<p v-if="status.securityClassified && security.length === 0" class="os-muted">
					{{ t('serverinfo', 'Regular updates carry fixes too, for example Debian point releases, so install them as well.') }}
				</p>

				<p class="os-muted">
					{{ listsDescription }}
				</p>
			</template>

			<div v-if="status.upgradeCommands.all" class="os-updates__commands">
				<OsCommand
					v-if="status.upgradeCommands.security && security.length"
					:label="t('serverinfo', 'Install the security updates')"
					:command="status.upgradeCommands.security" />
				<OsCommand
					v-if="status.updates.length"
					:label="t('serverinfo', 'Install all updates')"
					:command="status.upgradeCommands.all" />
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { OsConcern, OsPendingUpdate, OsStatus } from '../../types.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import PackageUp from 'vue-material-design-icons/PackageUp.vue'
import ShieldAlert from 'vue-material-design-icons/ShieldAlert.vue'
import FilterPills from '../FilterPills.vue'
import SectionHeading from '../SectionHeading.vue'
import StatTile from '../StatTile.vue'
import OsCommand from './OsCommand.vue'
import { daysSince, formatDays, PLAIN } from '../../osUtils.ts'

const props = defineProps<{
	status: OsStatus
	concern: OsConcern | null
	now: number
}>()

defineEmits<{ helper: [] }>()

/** Rows shown before "Show all" */
const limit = 12

const updates = computed<OsPendingUpdate[]>(() => props.status.updates ?? [])
const security = computed(() => updates.value.filter((update) => update.security))

/** Security updates first where there are any, everything otherwise */
const defaultFilter = () => (props.status.securityClassified && security.value.length > 0 ? 'security' : 'all')

const filter = ref<'security' | 'stack' | 'all'>(defaultFilter())
const expanded = ref(false)

// A new check brings a new list, which may not have what the filter shows.
watch(() => props.status.collectedAt, () => {
	filter.value = defaultFilter()
	expanded.value = false
})
const others = computed(() => updates.value.filter((update) => !update.security))
const stack = computed(() => updates.value.filter((update) => update.stack))

const filters = computed(() => [
	...(props.status.securityClassified
		// TRANSLATORS: Filter showing only security updates
		? [{ id: 'security' as const, label: t('serverinfo', 'Security'), count: security.value.length }]
		: []),
	// TRANSLATORS: Filter showing updates to packages Nextcloud relies on, such as PHP or the database
	{ id: 'stack' as const, label: t('serverinfo', 'Nextcloud stack'), count: stack.value.length },
	// TRANSLATORS: Filter showing every pending update
	{ id: 'all' as const, label: t('serverinfo', 'All'), count: updates.value.length },
])

const filtered = computed(() => {
	switch (filter.value) {
		case 'security':
			return security.value
		case 'stack':
			return stack.value
		default:
			return updates.value
	}
})

const visible = computed(() => (expanded.value ? filtered.value : filtered.value.slice(0, limit)))

const securityStatus = computed(() => {
	switch (props.concern?.level) {
		case 'error':
			return 'critical'
		case 'warning':
			return 'warning'
		default:
			return security.value.length === 0 ? 'ok' : undefined
	}
})

const listsDescription = computed(() => {
	const manager = props.status.packageManager ?? ''
	if (props.status.updatesSource === 'helper') {
		// TRANSLATORS: {tool} is a package manager such as apt or dnf
		return t('serverinfo', 'As reported by the root helper using {tool}.', { tool: manager }, PLAIN)
	}
	if (props.status.listsRefreshedAt === null) {
		// TRANSLATORS: {tool} is a package manager such as apt or dnf
		return t('serverinfo', 'As {tool} sees it.', { tool: manager }, PLAIN)
	}
	const days = daysSince(props.now, props.status.listsRefreshedAt) ?? 0
	return days === 0
		// TRANSLATORS: {tool} is a package manager such as apt or dnf
		? t('serverinfo', 'As {tool} sees it. Package lists were refreshed today.', { tool: manager }, PLAIN)
		: n('serverinfo', 'As {tool} sees it. Package lists were refreshed %n day ago.', 'As {tool} sees it. Package lists were refreshed %n days ago.', days, { tool: manager }, PLAIN)
})

/**
 * Advisory and CVE identifiers, without a patch's own name, which is shown already.
 *
 * @param update the pending update
 */
function advisories(update: OsPendingUpdate): string[] {
	return update.advisories.filter((id) => id !== update.name)
}

/**
 * @param severity advisory severity as the distribution names it
 */
function severityClass(severity: string): string {
	const value = severity.toLowerCase()
	if (value === 'critical' || value === 'important' || value === 'high') {
		return 'os-tag--error'
	}
	return value === 'moderate' || value === 'medium' ? 'os-tag--warning' : ''
}
</script>

<style scoped>
.os-updates__tiles {
	max-inline-size: 640px;
}

.os-updates__empty {
	margin-block: 24px;
}

.os-updates__table-wrapper {
	overflow-x: auto;
}

/* Core's reset makes every table nowrap, which would push the source column off-screen. */
.os-updates__table {
	inline-size: 100%;
	border-collapse: collapse;
	white-space: normal;
}

.os-updates__table th {
	text-align: start;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-block-end: 1px solid var(--color-border);
}

.os-updates__table td {
	padding: 8px;
	vertical-align: top;
	border-block-end: 1px solid var(--color-border-dark, var(--color-border));
}

.os-updates__table tbody tr:hover {
	background: var(--color-background-hover);
}

.os-updates__name {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
}

.os-updates__shield {
	color: var(--color-warning-text, var(--color-warning));
}

.os-updates__package {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.os-updates__advisories,
.os-updates__origin,
.os-updates__waiting {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.os-updates__waiting {
	white-space: nowrap;
}

.os-updates__version {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.85rem;
	overflow-wrap: anywhere;
}

.os-updates__summary {
	overflow-wrap: anywhere;
}

.os-updates__old {
	color: var(--color-text-maxcontrast);
}

.os-updates__new {
	font-weight: bold;
}

.os-updates__commands {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr));
	gap: 12px;
	margin-block-start: 16px;
}

.os-updates__none {
	color: var(--color-text-maxcontrast);
	text-align: center;
}

@media (width <= 700px) {
	.os-updates__origin {
		display: none;
	}
}
</style>
