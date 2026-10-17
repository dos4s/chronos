<script setup lang="ts">
import type { BoardColumn, Task } from '../types/index.ts'

import { n, t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import KanbanCard from './KanbanCard.vue'

/**
 * One column of the board, which is one status (AD7).
 *
 * While a drag is in progress the column shows one of three things: a
 * placeholder at the exact position the cards would land, nothing if it is the
 * column they are already in, or a dimmed column if the workflow does not
 * allow the move — which is where transition rules finally become visible
 * (§15.3, D28).
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
	subtaskCounts: Record<string, number>
}>()

const emit = defineEmits<{
	pick: [card: Task, event: PointerEvent]
	grab: [card: Task, event: KeyboardEvent]
	open: [card: Task]
	select: [card: Task, additive: boolean]
	configure: []
}>()

const isDragging = computed(() => props.dragging.length > 0)
const dimmed = computed(() => isDragging.value && !props.allowed)

/** The cards being dragged keep their place, faded, until the drop lands. */
const cards = computed(() => props.column.cards)

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
					:subtaskCount="subtaskCounts[card.uid] ?? 0"
					:data-card="card.uid"
					@pick="emit('pick', card, $event)"
					@grab="emit('grab', card, $event)"
					@open="emit('open', card)"
					@select="emit('select', card, $event)" />
				<li
					v-if="placeholder === index + 1"
					:class="$style.placeholder"
					aria-hidden="true" />
			</template>

			<li v-if="cards.length === 0 && placeholder === null" :class="$style.empty">
				{{ t('chronos', 'Nothing here') }}
			</li>
		</ul>
	</section>
</template>

<style module>
.column {
	flex: 0 0 300px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 10px;
	min-height: 160px;
	max-height: 100%;
	transition: opacity 0.12s ease;
}

/* A column the workflow will not take cards from here (§15.3). */
.dimmed {
	opacity: 0.4;
}

.over {
	border-color: var(--color-warning);
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
	font-size: 14px;
	font-weight: 600;
	margin: 0;
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.count {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.countOver {
	color: var(--color-warning-text);
	font-weight: 600;
}

.warning {
	font-size: 11px;
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
	border-radius: var(--border-radius-large);
	background: var(--color-primary-element-light);
}

.empty {
	list-style: none;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 12px 0;
}
</style>
