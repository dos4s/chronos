<script setup lang="ts">
import type { BoardCard, BoardColumn } from '../types/index.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import KanbanCard from './KanbanCard.vue'
import TaskComposer from './TaskComposer.vue'
import { cardShapes } from '../utils/board.ts'

/**
 * One column of the board, which is one status (AD7).
 *
 * While a drag is in progress the column shows one of three things: a
 * placeholder at the exact position the cards would land, nothing if it is the
 * column they are already in, or a dimmed column if the workflow does not
 * allow the move — which is where transition rules finally become visible
 * (§15.3, D28).
 *
 * It also decides how each of its cards is drawn: a subtask directly under its
 * parent is part of a group, and one that has been dragged away from it names
 * its parent instead (§15.9).
 *
 * A column is where cards are *made*, not only where they land: the composer
 * at its foot adds a card in this column's status, which is the whole point of
 * adding it here rather than in the list. It sits at the foot because that is
 * where an unranked card appears — a new card goes after the arranged ones, in
 * the list's own order (§15.5) — so the card turns up where the box was.
 */
const props = defineProps<{
	column: BoardColumn
	dragging: string[]
	/** The position a drop would land at, or null if not this column. */
	placeholder: number | null
	/** False when the workflow does not allow a card to come here. */
	allowed: boolean
	selection: string[]
	readOnly: boolean
	/** Column names, for a rolled-up card saying where its subtasks are. */
	columnNames: Record<string, string>
	/** Whether the subtasks are rolled up into their parents (§15.9). */
	rolled: boolean
}>()

const emit = defineEmits<{
	pick: [card: BoardCard, event: PointerEvent]
	grab: [card: BoardCard, event: KeyboardEvent]
	open: [card: BoardCard]
	select: [card: BoardCard, additive: boolean]
	configure: []
	add: [summary: string, parentUid: string | null]
	remove: [card: BoardCard]
}>()

/** Whether the composer is open, and what it is adding under, if anything. */
const adding = ref(false)
const parent = ref<BoardCard | null>(null)

function startAdding(under: BoardCard | null = null): void {
	parent.value = under
	adding.value = true
}

function stopAdding(): void {
	adding.value = false
	parent.value = null
}

/**
 * Adding leaves the composer open: a column is filled in one sitting, and
 * closing it after every card would make that four clicks a task. A subtask,
 * though, is added under one named parent, so that one closes.
 */
function added(summary: string): void {
	const under = parent.value
	emit('add', summary, under?.uid ?? null)
	if (under !== null) {
		stopAdding()
	}
}

const isDragging = computed(() => props.dragging.length > 0)
const dimmed = computed(() => isDragging.value && !props.allowed)

/** The cards being dragged keep their place, faded, until the drop lands. */
const cards = computed(() => props.column.cards)

const shapes = computed(() => cardShapes(cards.value))

const limitLabel = computed(() => {
	const limit = props.column.wipLimit
	if (limit === null || limit <= 0) {
		return null
	}
	return `${props.column.cards.length} / ${limit}`
})
</script>

<template>
	<section
		:class="[$style.column, { [$style.dimmed]: dimmed, [$style.over]: column.overWipLimit }]"
		:data-column="column.key"
		:aria-label="column.name">
		<header :class="$style.head">
			<span
				:class="$style.dot"
				:style="{ background: column.color || 'var(--color-primary-element)' }"
				aria-hidden="true" />
			<h3 :class="$style.name">
				{{ column.name }}
			</h3>
			<span :class="[$style.count, column.overWipLimit ? $style.countOver : '']">
				{{ limitLabel ?? column.cards.length }}
			</span>
			<NcButton
				v-if="!readOnly"
				variant="tertiary"
				:aria-label="t('chronos', 'Add a card to {column}', { column: column.name })"
				:title="t('chronos', 'Add a card to {column}', { column: column.name })"
				@click="startAdding()">
				<template #icon>
					<svg
						viewBox="0 0 24 24"
						width="18"
						height="18"
						fill="currentColor"
						aria-hidden="true">
						<path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2Z" />
					</svg>
				</template>
			</NcButton>
			<NcButton
				variant="tertiary"
				:aria-label="t('chronos', 'Column settings')"
				@click="emit('configure')">
				<template #icon>
					<svg
						viewBox="0 0 24 24"
						width="16"
						height="16"
						fill="currentColor"
						aria-hidden="true">
						<path d="M12 15.5A3.5 3.5 0 0 1 8.5 12 3.5 3.5 0 0 1 12 8.5a3.5 3.5 0 0 1 3.5 3.5 3.5 3.5 0 0 1-3.5 3.5m7.43-2.53c.04-.32.07-.64.07-.97 0-.33-.03-.66-.07-1l2.11-1.63c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.31-.61-.22l-2.49 1c-.52-.39-1.06-.73-1.69-.98l-.37-2.65A.506.506 0 0 0 14 2h-4c-.25 0-.46.18-.5.42l-.37 2.65c-.63.25-1.17.59-1.69.98l-2.49-1c-.22-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64L4.57 11c-.04.34-.07.67-.07 1 0 .33.03.65.07.97l-2.11 1.66c-.19.15-.25.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1.01c.52.4 1.06.74 1.69.99l.37 2.65c.04.24.25.42.5.42h4c.25 0 .46-.18.5-.42l.37-2.65c.63-.26 1.17-.59 1.69-.99l2.49 1.01c.22.08.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.66Z" />
					</svg>
				</template>
			</NcButton>
		</header>

		<p v-if="column.overWipLimit" :class="$style.warning">
			{{ n('chronos', 'Over its limit of %n card', 'Over its limit of %n cards', column.wipLimit ?? 0) }}
		</p>

		<ul :class="$style.cards" role="list">
			<li
				v-if="placeholder === 0"
				:class="$style.placeholder"
				aria-hidden="true" />
			<template v-for="(card, index) in cards" :key="card.uid">
				<KanbanCard
					:card="card"
					:selected="selection.includes(card.uid)"
					:dragging="dragging.includes(card.uid)"
					:readOnly="readOnly"
					:shape="shapes[card.uid]"
					:columnNames="columnNames"
					:rolled="rolled"
					:data-card="card.uid"
					@pick="emit('pick', card, $event)"
					@grab="emit('grab', card, $event)"
					@open="emit('open', card)"
					@select="emit('select', card, $event)"
					@addSubtask="startAdding(card)"
					@remove="emit('remove', card)" />
				<li
					v-if="placeholder === index + 1"
					:class="$style.placeholder"
					aria-hidden="true" />
			</template>

			<li v-if="cards.length === 0 && placeholder === null && !adding" :class="$style.empty">
				{{ t('chronos', 'Nothing here') }}
			</li>

			<li v-if="adding" :class="$style.adding">
				<span v-if="parent" :class="$style.under">
					{{ t('chronos', 'Subtask of “{parent}”', { parent: parent.summary || t('chronos', 'Untitled task') }) }}
				</span>
				<TaskComposer
					compact
					autofocus
					:placeholder="parent ? t('chronos', 'Add a subtask…') : t('chronos', 'Add a card…')"
					@submit="added"
					@cancel="stopAdding" />
			</li>
		</ul>

		<button
			v-if="!readOnly && !adding"
			:class="$style.addRow"
			@click="startAdding()">
			{{ t('chronos', '+ Add a card') }}
		</button>
	</section>
</template>

<style module>
.column {
	/*
	 * A column fills most of a phone and the next one peeks past it, which is
	 * how a reader knows the board goes on (§14.2). It never shrinks below that
	 * basis here, so a narrow window scrolls rather than squeezes; wider than a
	 * phone the columns share the room instead (below).
	 */
	flex: 1 0 clamp(240px, 78vw, 300px);
	max-width: 420px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	background: transparent;
	border: 1px solid var(--color-border);
	border-radius: var(--chronos-radius-card);
	padding: var(--chronos-space-2);
	min-height: 160px;
	max-height: 100%;
	transition: opacity 0.12s ease;
}

/* A column the workflow will not take cards from here (§15.3). */
.dimmed {
	opacity: 0.4;
}

.over {
	border-color: var(--color-warning-text);
}

/*
 * On anything wider than a phone the columns share the room rather than keep a
 * width of their own: a four-status board used to overflow a desktop window and
 * cut its last column in half (§14.2).
 */
@media (min-width: 701px) {
	.column {
		flex: 1 1 240px;
		/*
		 * Without a floor a flex item will not shrink past its own content,
		 * so four columns of long card titles overflowed a 1400px window and
		 * the fourth was cut in half. Below this they stop shrinking and the
		 * board scrolls instead, which is what a seven-status workflow needs.
		 */
		min-width: 200px;
	}
}

.head {
	display: flex;
	align-items: center;
	gap: 8px;
}

.dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
}

.name {
	font-size: var(--chronos-text);
	font-weight: 600;
	margin: 0;
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.count {
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
}

.countOver {
	color: var(--color-warning-text);
	font-weight: 600;
}

.warning {
	font-size: var(--chronos-text-small);
	color: var(--color-warning-text);
	margin: 0;
}

.cards {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 2px;
	overflow-y: auto;
	flex: 1;
}

/* Exactly where the cards would land (§15.3). */
.placeholder {
	list-style: none;
	height: 42px;
	border: 2px dashed var(--color-primary-element);
	border-radius: var(--chronos-radius-card);
	background: var(--color-primary-element-light);
}

.empty {
	list-style: none;
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 12px 0;
}

.adding {
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.under {
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/*
 * Quiet until it is wanted: a dashed line that reads as the place a card
 * would go, not as a fifth button in the header.
 */
/*
 * Qualified with the element on purpose: Nextcloud styles every plain button as
 * a filled pill through `button:not(.button-vue, …)`, which outranks a single
 * class, so a bespoke control styled by its class alone came out as a green box
 * (§15.10).
 */
button.addRow {
	background: transparent;
	min-height: var(--chronos-control);
	width: 100%;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--chronos-radius-card);
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	font-size: var(--chronos-text-small);
	font-weight: normal;
	padding: 6px;
	text-align: start;
	padding-inline-start: 12px;
}

button.addRow:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}
</style>
