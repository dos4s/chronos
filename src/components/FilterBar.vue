<script setup lang="ts">
import type { TaskFilter } from '../utils/filter.ts'

import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'

/**
 * Find a task, and decide whether finished work is drawn at all.
 *
 * The same bar in the task list, the board and the timeline, because it is the
 * same question in all three. The board has no *show completed* of its own: a
 * final column is where finished cards live, and hiding them would empty it.
 */
const props = defineProps<{
	modelValue: TaskFilter
	/** How many are hidden right now, for the line underneath. */
	hidden?: number
	/** False on the board, where a final column is the point. */
	completedToggle?: boolean
	placeholder?: string
}>()

const emit = defineEmits<{ 'update:modelValue': [filter: TaskFilter] }>()

const query = computed({
	get: () => props.modelValue.query,
	set: (value: string) => emit('update:modelValue', { ...props.modelValue, query: value }),
})

const showCompleted = computed({
	get: () => props.modelValue.showCompleted,
	set: (value: boolean) => emit('update:modelValue', { ...props.modelValue, showCompleted: value }),
})
</script>

<template>
	<div :class="$style.bar">
		<div :class="$style.field">
			<svg
				:class="$style.icon"
				viewBox="0 0 24 24"
				width="16"
				height="16"
				fill="currentColor"
				aria-hidden="true">
				<path d="M9.5 3A6.5 6.5 0 0 1 16 9.5c0 1.61-.59 3.09-1.56 4.23l.27.27h.79l5 5-1.5 1.5-5-5v-.79l-.27-.27A6.52 6.52 0 0 1 9.5 16 6.5 6.5 0 0 1 3 9.5 6.5 6.5 0 0 1 9.5 3m0 2C7 5 5 7 5 9.5S7 14 9.5 14 14 12 14 9.5 12 5 9.5 5Z" />
			</svg>
			<input
				v-model="query"
				type="text"
				inputmode="search"
				:class="$style.input"
				:placeholder="placeholder ?? t('chronos', 'Find a task…')"
				:aria-label="placeholder ?? t('chronos', 'Find a task')">
			<button
				v-if="query !== ''"
				:class="$style.clear"
				:aria-label="t('chronos', 'Clear the search')"
				@click="query = ''">
				×
			</button>
		</div>

		<label v-if="completedToggle !== false" :class="$style.toggle">
			<input v-model="showCompleted" type="checkbox">
			{{ t('chronos', 'Show completed') }}
		</label>

		<span v-if="hidden" :class="$style.hidden">
			{{ n('chronos', '%n hidden', '%n hidden', hidden) }}
		</span>
	</div>
</template>

<style module>
.bar {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.field {
	position: relative;
	display: flex;
	align-items: center;
	/* Shrinks with the toolbar rather than pushing it apart. */
	flex: 1 1 180px;
	max-width: 280px;
	min-width: 0;
}

.icon {
	position: absolute;
	inset-inline-start: 8px;
	color: var(--color-text-maxcontrast);
	pointer-events: none;
}

/*
 * Qualified with the element for the same reason the buttons are (§15.10):
 * Nextcloud styles every `input`, `select` and `textarea` through a selector
 * with an element in it, which outranks a single class — so this rule's
 * padding, width and height were being ignored.
 */
input.input {
	width: 100%;
	padding-inline: 30px 28px;
	margin: 0;
}

/*
 * Qualified with the element on purpose: Nextcloud styles every plain button as
 * a filled pill through `button:not(.button-vue, …)`, which outranks a single
 * class, so a bespoke control styled by its class alone came out as a green box
 * (§15.10).
 */
button.clear {
	position: absolute;
	min-height: 0;
	width: auto;
	border-radius: 0;
	inset-inline-end: 4px;
	background: transparent;
	border: none;
	font-size: 18px;
	font-weight: normal;
	line-height: 1;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	padding: 2px 6px;
}

.toggle {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
}

.hidden {
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
}
</style>
