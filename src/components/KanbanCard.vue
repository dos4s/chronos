<script setup lang="ts">
import type { BoardCard } from '../types/index.ts'
import type { CardShape } from '../utils/board.ts'

import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import { useNow } from '../composables/useNow.ts'
import { formatDate, formatTimeOfDay } from '../utils/format.ts'
import { isDueToday, isOverdue } from '../utils/tasks.ts'

/**
 * One card on the board: a task, as much of it as is worth seeing at a glance.
 *
 * The whole card is the drag handle, which is why `touch-action: none` sits on
 * it — a finger on a card drags the card, a finger beside one scrolls the
 * column (§15.1). It is also focusable, because every drag has to work from
 * the keyboard (§15.7).
 *
 * A card also says where it sits in the tree the board flattened (§15.9). A
 * subtask directly under its parent is drawn as part of it and needs no label;
 * one that has been dragged away from its parent names it instead, because a
 * card nobody can place is the thing the board got wrong before. A card with
 * subtasks of its own says how much of that branch is done, and — when the
 * board is rolled up and they are not on the screen at all — which columns the
 * rest of it is sitting in.
 *
 * The menu is how a card is worked on rather than moved: opened, given a
 * subtask, deleted. It swallows its own pointer *and* key events, because the
 * card underneath it is a drag handle that answers space, enter and a
 * double-click — and opening a menu is none of those (§14.2).
 */
const props = defineProps<{
	card: BoardCard
	selected: boolean
	dragging: boolean
	readOnly: boolean
	/** How the column decided to draw it; the drag preview has no column. */
	shape?: CardShape
	/** Column names, for saying where a rolled-up card's subtasks are. */
	columnNames?: Record<string, string>
	/** Whether the subtasks are rolled up into this card (§15.9). */
	rolled?: boolean
}>()

const emit = defineEmits<{
	pick: [event: PointerEvent]
	grab: [event: KeyboardEvent]
	open: []
	select: [additive: boolean]
	addSubtask: []
	remove: []
}>()

const { now } = useNow()

const overdue = computed(() => isOverdue(props.card, now.value))
const dueToday = computed(() => isDueToday(props.card, now.value))

const dueLabel = computed(() => {
	const due = props.card.dueAt
	if (due === null) {
		return null
	}
	if (props.card.dueIsDate) {
		return dueToday.value ? t('chronos', 'Today') : formatDate(due, true)
	}
	return dueToday.value ? formatTimeOfDay(due) : `${formatDate(due)} ${formatTimeOfDay(due)}`
})

/** `PRIORITY` 1–4 is high in iCalendar; 5 is normal (§7). */
const highPriority = computed(() => props.card.priority > 0 && props.card.priority <= 4)

const indented = computed(() => props.shape?.indented === true)

/** The line above the summary: whose subtask this is, or which sublist it is in. */
const belongsTo = computed(() => {
	const parent = props.card.parent
	if (parent === null || props.shape?.showsParent !== true) {
		return null
	}

	const name = parent.summary ?? t('chronos', 'Untitled task')

	return parent.type === 'subproject'
		? { name, label: t('chronos', 'In {parent}', { parent: name }), sublist: true }
		: { name, label: t('chronos', 'Subtask of {parent}', { parent: name }), sublist: false }
})

/** How much of this card's own branch is finished (§15.9). */
const progress = computed(() => {
	const subtasks = props.card.subtasks
	if (subtasks === null) {
		return null
	}

	return subtasks.done > 0
		? t('chronos', '{done} of {total} subtasks done', { done: subtasks.done, total: subtasks.total })
		: n('chronos', '%n subtask', '%n subtasks', subtasks.total)
})

/**
 * Where the unfinished part of the branch is sitting. Only worth saying when
 * the board is rolled up: with the subtasks on the screen as cards, the board
 * is already showing it.
 */
const openElsewhere = computed(() => {
	const open = props.card.subtasks?.open ?? {}
	if (props.rolled !== true) {
		return []
	}

	return Object.entries(open).map(([key, count]) => t('chronos', '{count} in {column}', {
		count,
		column: props.columnNames?.[key] ?? key,
	}))
})

const hasMeta = computed(() => dueLabel.value !== null
	|| highPriority.value
	|| props.card.assignee !== null
	|| (props.card.percent !== null && props.card.percent > 0)
	|| progress.value !== null
	|| openElsewhere.value.length > 0)

function onPointerDown(event: PointerEvent): void {
	emit('select', event.shiftKey || event.ctrlKey || event.metaKey)
	if (!props.readOnly) {
		emit('pick', event)
	}
}
</script>

<template>
	<li
		:class="[$style.card, {
			[$style.selected]: selected,
			[$style.ghost]: dragging,
			[$style.overdue]: overdue,
			[$style.indented]: indented,
		}]"
		:tabindex="readOnly ? -1 : 0"
		role="listitem"
		:aria-grabbed="dragging ? 'true' : 'false'"
		:aria-label="belongsTo
			? `${card.summary || t('chronos', 'Untitled task')} — ${belongsTo.label}`
			: (card.summary || t('chronos', 'Untitled task'))"
		@pointerdown="onPointerDown"
		@keydown.space="emit('grab', $event)"
		@keydown.enter.exact="emit('open')"
		@dblclick="emit('open')">
		<div v-if="belongsTo" :class="$style.belongs" :title="belongsTo.label">
			<span aria-hidden="true">{{ belongsTo.sublist ? '▤' : '↳' }}</span>
			{{ belongsTo.name }}
		</div>

		<div :class="$style.line">
			<div :class="$style.summary">
				{{ card.summary || t('chronos', 'Untitled task') }}
			</div>

			<!--
				`.stop` on the wrapper: the card under this menu is the drag
				handle, and reaching for the menu is not a drag (§15.1).
			-->
			<div
				v-if="!readOnly"
				:class="$style.menu"
				@pointerdown.stop
				@dblclick.stop
				@keydown.stop>
				<NcActions :aria-label="t('chronos', 'Card actions')">
					<NcActionButton @click="emit('open')">
						{{ t('chronos', 'Edit details') }}
					</NcActionButton>
					<NcActionButton @click="emit('addSubtask')">
						{{ t('chronos', 'Add subtask') }}
					</NcActionButton>
					<NcActionButton @click="emit('remove')">
						{{ t('chronos', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</div>
		</div>

		<div v-if="hasMeta" :class="$style.meta">
			<span v-if="dueLabel" :class="[$style.chip, overdue ? $style.late : '']">
				{{ dueLabel }}
			</span>
			<span v-if="highPriority" :class="[$style.chip, $style.priority]">
				{{ t('chronos', 'High') }}
			</span>
			<span v-if="card.percent !== null && card.percent > 0" :class="$style.chip">
				{{ card.percent }}%
			</span>
			<span v-if="progress" :class="[$style.chip, $style.branch]">
				{{ progress }}
			</span>
			<span
				v-for="where in openElsewhere"
				:key="where"
				:class="[$style.chip, $style.branch]">
				{{ where }}
			</span>
			<span v-if="card.assignee" :class="$style.chip" :title="card.assignee">
				{{ card.assignee }}
			</span>
		</div>
	</li>
</template>

<style module>
.card {
	list-style: none;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 10px 12px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	cursor: grab;
	/* A finger on a card drags it; the browser must not also scroll (§15.1). */
	touch-action: none;
	user-select: none;
}

.card:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

/*
 * A subtask sitting directly under its parent is drawn as part of it: a step
 * in from the edge, and a line down the side joining the group (§15.9).
 */
.indented {
	margin-inline-start: 16px;
	border-inline-start: 2px solid var(--color-border-dark);
	border-start-start-radius: 0;
	border-end-start-radius: 0;
}

.selected {
	border-color: var(--color-primary-element);
	box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

/* The card stays in place, faded, while its copy follows the pointer. */
.ghost {
	opacity: 0.35;
}

.overdue {
	border-inline-start: 3px solid var(--color-error);
}

/* Whose subtask this is, for a card that is not sitting under its parent. */
.belongs {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	display: flex;
	gap: 4px;
	align-items: baseline;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.line {
	display: flex;
	align-items: flex-start;
	gap: 4px;
}

/*
 * The menu is quiet until the card is pointed at or focused, so a column of
 * cards reads as a column of names rather than a column of buttons.
 */
.menu {
	flex-shrink: 0;
	margin-block-start: -4px;
	margin-inline-end: -6px;
	opacity: 0;
	transition: opacity 0.1s ease;
}

.card:hover .menu,
.card:focus-within .menu {
	opacity: 1;
}

/* A touch screen has no hover, so there the menu is simply there. */
@media (hover: none) {
	.menu {
		opacity: 1;
	}
}

.summary {
	flex: 1;
	min-width: 0;
	font-size: 14px;
	line-height: 1.35;
	overflow-wrap: anywhere;
}

.meta {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.chip {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1px 6px;
	max-width: 140px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.late {
	color: var(--color-error-text);
}

.priority {
	color: var(--color-warning-text);
}

/* What is under this card, as opposed to what the card itself is. */
.branch {
	border: 1px solid var(--color-border);
	background: transparent;
}
</style>
