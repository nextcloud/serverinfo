<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'

interface Item {
	id: string
	emoji: string
	title: string
	description: string
	earned: boolean
}

const props = defineProps<{
	items: Item[]
}>()

const open = ref(false)
const earnedCount = computed(() => props.items.filter((i) => i.earned).length)
const total = computed(() => props.items.length)
const percent = computed(() => total.value > 0 ? Math.round((earnedCount.value / total.value) * 100) : 0)

const earned = computed(() => props.items.filter((i) => i.earned))
const locked = computed(() => props.items.filter((i) => !i.earned))
</script>

<template>
	<div :class="$style.wrap" @mouseenter="open = true" @mouseleave="open = false" @focusin="open = true" @focusout="open = false">
		<button
			type="button"
			:class="$style.badge"
			:title="t('serverinfo', 'Earned {n} of {total} achievements ({pct}%)', { n: earnedCount, total, pct: percent })"
			:aria-expanded="open"
			:aria-label="t('serverinfo', 'Achievements')">
			<span aria-hidden="true">🏅</span>
			<span :class="$style.count">{{ earnedCount }} / {{ total }}</span>
			<span :class="$style.bar">
				<span :class="$style.fill" :style="{ width: `${percent}%` }" />
			</span>
		</button>

		<Transition
			:enter-from-class="$style.popEnterFrom"
			:enter-active-class="$style.popEnterActive"
			:leave-active-class="$style.popLeaveActive"
			:leave-to-class="$style.popLeaveTo">
			<div v-if="open" :class="$style.popover" role="dialog">
				<div :class="$style.popHead">
					<div :class="$style.popTitle">{{ t('serverinfo', 'Achievements') }}</div>
					<div :class="$style.popSub">{{ earnedCount }} / {{ total }} unlocked</div>
				</div>

				<div v-if="earned.length > 0" :class="$style.section">
					<div :class="$style.sectionLabel">{{ t('serverinfo', 'Unlocked') }}</div>
					<ul :class="$style.list">
						<li v-for="a in earned" :key="a.id" :class="[$style.item, $style.item_earned]">
							<span :class="$style.emoji" aria-hidden="true">{{ a.emoji }}</span>
							<div :class="$style.text">
								<div :class="$style.itemTitle">{{ a.title }}</div>
								<div :class="$style.itemDesc">{{ a.description }}</div>
							</div>
						</li>
					</ul>
				</div>

				<div v-if="locked.length > 0" :class="$style.section">
					<div :class="$style.sectionLabel">{{ t('serverinfo', 'Locked') }}</div>
					<ul :class="$style.list">
						<li v-for="a in locked" :key="a.id" :class="[$style.item, $style.item_locked]">
							<span :class="$style.emoji" aria-hidden="true">{{ a.emoji }}</span>
							<div :class="$style.text">
								<div :class="$style.itemTitle">{{ a.title }}</div>
								<div :class="$style.itemDesc">{{ a.description }}</div>
							</div>
						</li>
					</ul>
				</div>
			</div>
		</Transition>
	</div>
</template>

<style module lang="scss">
.wrap {
	position: relative;
	display: inline-block;
	align-self: flex-end;
	margin-top: -8px;
}

.badge {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 4px 14px 4px 12px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 10%, var(--color-main-background));
	border: 1px solid color-mix(in srgb, var(--color-primary-element) 22%, var(--color-border));
	color: var(--color-primary-element);
	font-size: 0.78em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	cursor: pointer;
	transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.badge:hover {
	transform: translateY(-1px);
	box-shadow: 0 4px 12px color-mix(in srgb, var(--color-primary-element) 16%, transparent);
}

.count {
	letter-spacing: 0.02em;
}

.bar {
	display: inline-block;
	width: 38px;
	height: 4px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 16%, transparent);
	overflow: hidden;
}

.fill {
	display: block;
	height: 100%;
	background: linear-gradient(90deg, var(--color-primary-element), color-mix(in srgb, var(--color-primary-element) 55%, transparent));
	transition: width 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.popover {
	position: absolute;
	right: 0;
	top: calc(100% + 8px);
	width: 360px;
	max-height: 60vh;
	overflow-y: auto;
	z-index: 50;
	padding: 14px 16px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
}

.popover::before {
	content: '';
	position: absolute;
	right: 18px;
	top: -7px;
	width: 12px;
	height: 12px;
	background: var(--color-main-background);
	border-top: 1px solid var(--color-border);
	border-left: 1px solid var(--color-border);
	transform: rotate(45deg);
}

.popHead {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 12px;
	padding-bottom: 10px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 10px;
}

.popTitle {
	font-weight: 700;
	color: var(--color-main-text);
	font-size: 0.98em;
}

.popSub {
	font-size: 0.82em;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.section { margin-bottom: 12px; }
.section:last-child { margin-bottom: 0; }

.sectionLabel {
	font-size: 0.7em;
	text-transform: uppercase;
	letter-spacing: 0.07em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	margin-bottom: 6px;
}

.list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 10px;
	border-radius: var(--border-radius);
}

.item_earned {
	background-color: color-mix(in srgb, var(--color-success) 12%, transparent);
}

.item_locked {
	background-color: var(--color-background-hover);
	opacity: 0.55;
	filter: grayscale(0.6);
}

.emoji {
	font-size: 22px;
	line-height: 1;
	flex-shrink: 0;
	width: 28px;
	text-align: center;
}

.text { min-width: 0; flex: 1; }

.itemTitle {
	font-weight: 600;
	font-size: 0.88em;
	color: var(--color-main-text);
	line-height: 1.2;
}

.itemDesc {
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
	margin-top: 1px;
	line-height: 1.3;
}

.popEnterFrom    { opacity: 0; transform: translateY(-6px); }
.popLeaveTo      { opacity: 0; transform: translateY(-6px); }
.popEnterActive,
.popLeaveActive  { transition: opacity 0.18s ease, transform 0.22s cubic-bezier(0.22, 1, 0.36, 1); }
</style>
