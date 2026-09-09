<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<article class="rule-card" :class="`rule-card--${rule.status}`">
		<header class="rule-card__header">
			<span class="rule-card__dot" :class="`rule-card__dot--${rule.severity}`" />
			<div class="rule-card__title-block">
				<h4 class="rule-card__title">
					{{ rule.name }}
				</h4>
				<span class="rule-card__meta">
					{{ severityLabel }}
					<span aria-hidden="true">·</span>
					<code>{{ rule.id }}</code>
				</span>
			</div>
			<span class="rule-card__chip" :class="`rule-card__chip--${rule.status}`">{{ statusLabel }}</span>
		</header>

		<p v-if="rule.status === 'fail' && rule.issue" class="rule-card__issue">
			{{ rule.issue }}
		</p>
		<p v-if="rule.justification" class="rule-card__line">
			{{ rule.justification }}
		</p>
		<p v-else-if="rule.skipReason" class="rule-card__line rule-card__line--muted">
			{{ rule.skipReason }}
		</p>

		<ul v-if="rule.details?.length" class="rule-card__affected">
			<li v-for="name in rule.details" :key="name">
				<code>{{ name }}</code>
			</li>
		</ul>

		<p v-if="rule.status === 'fail' && rule.recommendation" class="rule-card__line">
			{{ rule.recommendation }}
		</p>

		<dl v-if="hasDetails" class="rule-card__details">
			<template v-if="rule.value !== null">
				<!-- TRANSLATORS: Label for the measured number a database check looked at -->
				<dt>{{ t('serverinfo', 'Value') }}</dt>
				<dd>{{ formattedValue }}</dd>
			</template>
			<template v-if="rule.apply">
				<!-- TRANSLATORS: Label for the database setting a check suggests changing -->
				<dt>{{ t('serverinfo', 'Setting') }}</dt>
				<dd>
					<code>{{ rule.apply.variable }}</code>
					<span aria-hidden="true">→</span>
					<code>{{ rule.apply.recommendedValue }}</code>
					<span v-if="!rule.apply.runtimeWritable" class="rule-card__tag">
						<!-- TRANSLATORS: Tag meaning the database must be restarted for this setting to take effect -->
						{{ t('serverinfo', 'restart required') }}
					</span>
				</dd>
			</template>
			<template v-if="rule.apply?.note">
				<!-- TRANSLATORS: Label for a caveat attached to a suggested database setting -->
				<dt>{{ t('serverinfo', 'Note') }}</dt>
				<dd>{{ rule.apply.note }}</dd>
			</template>
		</dl>

		<footer v-if="(rule.status === 'fail' && rule.apply) || rule.docUrl" class="rule-card__actions">
			<NcButton v-if="rule.status === 'fail' && rule.apply" variant="secondary" @click="$emit('snippet', rule)">
				<template #icon>
					<CodeJson :size="20" />
				</template>
				<!-- TRANSLATORS: Button that shows the config file lines needed to fix a check -->
				{{ t('serverinfo', 'Show config snippet') }}
			</NcButton>
			<a
				v-if="rule.docUrl"
				:href="rule.docUrl"
				target="_blank"
				rel="noreferrer noopener"
				class="rule-card__doc">
				<!-- TRANSLATORS: Link to the database vendor's documentation for a setting -->
				{{ t('serverinfo', 'Documentation ↗') }}
			</a>
		</footer>
	</article>
</template>

<script setup lang="ts">
import type { DatabaseRule } from '../types.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import CodeJson from 'vue-material-design-icons/CodeJson.vue'

const props = defineProps<{ rule: DatabaseRule }>()

defineEmits<{ snippet: [rule: DatabaseRule] }>()

const hasDetails = computed(() => props.rule.value !== null || props.rule.apply !== null)

const statusLabel = computed(() => {
	switch (props.rule.status) {
		// TRANSLATORS: Status of a database check that found nothing wrong
		case 'ok': return t('serverinfo', 'Passing')
		// TRANSLATORS: Status of a database check that could not run, e.g. the value was unavailable
		case 'skipped': return t('serverinfo', 'Skipped')
		// TRANSLATORS: Status of a database check that found a problem
		default: return t('serverinfo', 'Failing')
	}
})

const severityLabel = computed(() => {
	switch (props.rule.severity) {
		// TRANSLATORS: Severity of a database finding, the most serious of the three
		case 'alert': return t('serverinfo', 'Alert')
		// TRANSLATORS: Severity of a database finding, the least serious of the three
		case 'notice': return t('serverinfo', 'Notice')
		// TRANSLATORS: Severity of a database finding, between alert and notice
		default: return t('serverinfo', 'Warning')
	}
})

// Whole numbers are counts and read better without decimals; ratios keep two.
const formattedValue = computed(() => {
	const value = props.rule.value
	if (value === null) {
		return ''
	}

	return Number.isInteger(value) ? value.toLocaleString() : value.toFixed(2)
})
</script>

<style scoped>
.rule-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	background: var(--color-main-background);
}

/* A red edge is enough to pick the failures out; filling the whole card turns
   the page into a wall of colour and swallows the status chip. */
.rule-card--fail {
	border-inline-start: 4px solid var(--color-error);
}

.rule-card--skipped {
	opacity: 0.7;
}

.rule-card__header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.rule-card__dot {
	inline-size: 10px;
	block-size: 10px;
	border-radius: 50%;
	flex: 0 0 auto;
	background: var(--color-text-maxcontrast);
}

/* The -text variants, because --color-error/-warning are pale fills in most
   themes and a 10px pale dot reads as no dot at all. */
.rule-card__dot--alert { background: var(--color-error-text, var(--color-error)); }
.rule-card__dot--warning { background: var(--color-warning-text, var(--color-warning)); }
.rule-card__dot--notice { background: var(--color-text-maxcontrast); }

.rule-card__title-block { flex: 1 1 auto; min-inline-size: 0; }

.rule-card__title {
	margin: 0;
	font-size: 1rem;
	font-weight: bold;
}

.rule-card__meta {
	display: flex;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.rule-card__chip {
	border-radius: var(--border-radius-pill);
	padding: 2px 10px;
	font-size: 0.85rem;
	background: var(--color-background-dark);
	white-space: nowrap;
}

/* --color-error/-success are light fills in most themes, so the text colour
   has to be the accessible -text variant rather than the primary one. */
.rule-card__chip--fail { background: var(--color-error-hover, var(--color-background-dark)); color: var(--color-error-text, var(--color-error)); }
.rule-card__chip--ok { background: var(--color-success-hover, var(--color-background-dark)); color: var(--color-success-text, var(--color-success)); }

.rule-card__issue { margin: 8px 0 0; font-weight: bold; }
.rule-card__line { margin: 6px 0 0; }
.rule-card__line, .rule-card__issue { text-wrap: pretty; }
.rule-card__line--muted { color: var(--color-text-maxcontrast); }

.rule-card__affected {
	margin: 6px 0 0;
	padding: 0;
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	list-style: none;
}

.rule-card__details {
	margin: 10px 0 0;
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 12px;
	font-size: 0.9rem;
	/* Core styles bare dl/dt/dd with 12px of padding and a fixed 130px dt, which
	   turned each of these two-line strips into a third of the card. */
	padding: 0;
}

.rule-card__details dt {
	color: var(--color-text-maxcontrast);
	padding: 0;
	inline-size: auto;
	white-space: nowrap;
	text-align: start;
}

.rule-card__details dd {
	margin: 0;
	padding: 0;
}

.rule-card__tag {
	margin-inline-start: 6px;
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.rule-card__actions {
	margin-block-start: 10px;
	display: flex;
	align-items: center;
	gap: 12px;
}

.rule-card__doc { color: var(--color-primary-element); }
</style>
