<script setup lang="ts">
import type { DragTarget } from '../composables/useDrag.ts'
import type { BoardColumn, Task, TaskWritePayload } from '../types/index.ts'
import type { LabelledProject } from '../utils/projects.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ColumnSettingsDialog from '../components/ColumnSettingsDialog.vue'
import KanbanCard from '../components/KanbanCard.vue'
import KanbanColumn from '../components/KanbanColumn.vue'
import TaskDetailDialog from '../components/TaskDetailDialog.vue'
import { useDrag } from '../composables/useDrag.ts'
import { useBoardStore } from '../stores/board.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTasksStore } from '../stores/tasks.ts'
import { useWorkflowsStore } from '../stores/workflows.ts'
import { allowsMove, cardByUid, columnOf, needsComment } from '../utils/board.ts'

/**
 * The board (§15).
 *
 * Columns are the project's statuses, and moving a card changes the real
 * status — in the Tasks app and on the phone too (AD7). Everything about how
 * a drag behaves lives in `useDrag`; everything about what a drop *means*
 * lives in the board store. This view is the wiring, plus the two questions
 * only it can ask: the note a transition requires, and which cards are
 * selected.
 */
const board = useBoardStore()
const projects = useProjectsStore()
const tasks = useTasksStore()
const workflows = useWorkflowsStore()

const project = ref<LabelledProject | null>(null)
const showArchived = ref(false)
const editing = ref<Task | null>(null)
const configuring = ref<BoardColumn | null>(null)

/** A drop that needs a note waits here until there is one (§12.1). */
const pending = ref<{ uids: string[], column: string, index: number } | null>(null)
const note = ref('')

const lanes = ref<HTMLElement | null>(null)

const options = computed(() => showArchived.value ? projects.all : projects.active)
const columns = computed(() => board.columns)
const readOnly = computed(() => board.board !== null && !board.writable)

/** Subtasks are not cards of their own; a card says how many it has. */
const subtaskCounts = computed(() => {
	const counts: Record<string, number> = {}
	for (const column of columns.value) {
		for (const card of column.cards) {
			if (card.parentUid !== null) {
				counts[card.parentUid] = (counts[card.parentUid] ?? 0) + 1
			}
		}
	}
	return counts
})

const drag = useDrag({
	container: () => lanes.value,
	lanes: () => laneElements().map(({ key, element }) => ({ key, element })),
	items: (lane: string) => cardElements(lane),
	canDrop,
	onDrop: (uids: string[], target: DragTarget) => {
		void dropped(uids, target)
	},
	describe: (target: DragTarget, ids: string[]) => {
		const column = columns.value.find((candidate) => candidate.key === target.lane)
		return t('chronos', '{count} moved to {column}, position {position}', {
			count: ids.length,
			column: column?.name ?? target.lane,
			position: target.index + 1,
		})
	},
})

/**
 * Where the cards being dragged are coming from. A drag out of two columns at
 * once is allowed only where every one of them allows the move.
 */
function dragOrigins(): string[] {
	const current = board.board
	if (current === null) {
		return []
	}

	return [...new Set(drag.dragging.value
		.map((uid) => columnOf(current, uid)?.key)
		.filter((key): key is string => key !== undefined))]
}

/** Whether this column will take what is being dragged (§15.3). */
function canDrop(columnKey: string): boolean {
	const current = board.board
	if (current === null || readOnly.value) {
		return false
	}

	return dragOrigins().every((from) => allowsMove(current, from, columnKey))
}

/** The element the engine moves, which Vue never touches again (§15.2). */
const dragPreview = drag.preview

/** The card that follows the pointer, which is the first one being dragged. */
const previewCard = computed(() => {
	const current = board.board
	const first = drag.dragging.value[0]
	return current === null || first === undefined ? null : cardByUid(current, first)
})

function laneElements(): { key: string, element: HTMLElement }[] {
	const root = lanes.value
	if (root === null) {
		return []
	}

	return columns.value.flatMap((column) => {
		const element = root.querySelector(`[data-column="${column.key}"]`)
		return element instanceof HTMLElement ? [{ key: column.key, element }] : []
	})
}

function cardElements(lane: string): { id: string, element: HTMLElement }[] {
	const column = columns.value.find((candidate) => candidate.key === lane)
	const root = lanes.value
	if (column === undefined || root === null) {
		return []
	}

	return column.cards.flatMap((card) => {
		const element = root.querySelector(`[data-card="${card.uid}"]`)
		return element instanceof HTMLElement ? [{ id: card.uid, element }] : []
	})
}

/**
 * A drop, once the engine has said where. If the workflow asks for a note on
 * that move, the drop waits for one rather than being refused by the server
 * after the card has already moved on the screen.
 */
async function dropped(uids: string[], target: DragTarget): Promise<void> {
	const current = board.board
	if (current === null) {
		return
	}

	const asksForNote = dragOrigins().some((from) => needsComment(current, from, target.lane))
		|| uids.some((uid) => needsComment(current, columnOf(current, uid)?.key ?? '', target.lane))

	if (asksForNote) {
		pending.value = { uids, column: target.lane, index: target.index }
		note.value = ''
		return
	}

	await board.drop(uids, target.lane, target.index)
}

async function dropWithNote(): Promise<void> {
	const waiting = pending.value
	if (waiting === null) {
		return
	}

	const comment = note.value.trim()
	if (comment === '') {
		return
	}

	pending.value = null
	await board.drop(waiting.uids, waiting.column, waiting.index, comment)
}

function placeholderFor(column: BoardColumn): number | null {
	const target = drag.target.value
	return target !== null && target.lane === column.key ? target.index : null
}

/** `Ctrl+Z` undoes the last drop, as the toast offers (§15.6). */
function onKeyDown(event: KeyboardEvent): void {
	if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z' && board.undoable !== null) {
		event.preventDefault()
		void board.undo()
	}
}

watch(project, (selected) => {
	void board.select(selected?.uri ?? null)
})

watch(options, (available) => {
	const current = project.value
	if (current === null) {
		project.value = available[0] ?? null
		return
	}
	project.value = available.find((candidate) => candidate.uri === current.uri) ?? available[0] ?? null
})

onMounted(async () => {
	window.addEventListener('keydown', onKeyDown)

	if (projects.projects.length === 0) {
		await projects.refresh()
	}
	project.value ??= options.value[0] ?? null
	// The column settings dialog edits the workflow, so its statuses have to
	// be at hand before one is opened.
	await workflows.ensureLoaded()
})

onBeforeUnmount(() => {
	window.removeEventListener('keydown', onKeyDown)
})

/** A card edited in the dialog is saved through the task store, as in the list. */
async function saveDetails(changes: Partial<TaskWritePayload>): Promise<void> {
	const card = editing.value
	if (card === null) {
		return
	}

	if (await tasks.save(card, changes)) {
		editing.value = null
		await board.refresh()
	}
}

async function columnSaved(): Promise<void> {
	configuring.value = null
	await board.refresh()
}
</script>

<template>
	<div :class="$style.view">
		<div class="chronos-header">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Board') }}
			</h2>
			<span v-if="board.cardCount" :class="$style.count">
				{{ n('chronos', '%n card', '%n cards', board.cardCount) }}
			</span>
		</div>

		<div :class="$style.picker">
			<NcSelect
				v-model="project"
				:class="$style.select"
				:options="options"
				label="label"
				:reduce="(option: LabelledProject) => option"
				:clearable="false"
				:inputLabel="t('chronos', 'Project')"
				:placeholder="t('chronos', 'Pick a project')" />
			<label :class="$style.archived">
				<input v-model="showArchived" type="checkbox">
				{{ t('chronos', 'Show archived') }}
			</label>
			<div :class="$style.spacer" />
			<NcButton
				v-if="board.undoable"
				variant="secondary"
				:disabled="board.saving"
				@click="board.undo()">
				{{ t('chronos', 'Undo move') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="readOnly" type="info">
			{{ t('chronos', 'This list is shared with you read-only, so its cards cannot be moved here.') }}
		</NcNoteCard>

		<NcNoteCard v-else-if="board.board && board.board.workflowId === null" type="info">
			{{ t('chronos', 'This project has no workflow, so the board shows the four standard statuses. Give it one in the project settings to use statuses of your own.') }}
		</NcNoteCard>

		<div v-if="board.initialLoading" :class="$style.loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="columns.length" ref="lanes" :class="$style.lanes">
			<KanbanColumn
				v-for="column in columns"
				:key="column.key"
				:column="column"
				:dragging="drag.dragging.value"
				:placeholder="placeholderFor(column)"
				:allowed="canDrop(column.key)"
				:selection="board.selection"
				:readOnly="readOnly"
				:subtaskCounts="subtaskCounts"
				@pick="(card, event) => drag.pointerDown(event, card.uid, board.selection)"
				@grab="(card, event) => drag.keyDown(event, card.uid, board.selection)"
				@open="editing = $event"
				@select="(card, additive) => board.toggleSelection(card.uid, additive)"
				@configure="configuring = column" />
		</div>

		<NcEmptyContent
			v-else-if="project"
			:class="$style.empty"
			:name="t('chronos', 'Nothing to show on the board')"
			:description="t('chronos', 'Add a task in the Tasks view, and it becomes a card here.')" />

		<NcEmptyContent
			v-else
			:class="$style.empty"
			:name="t('chronos', 'No task lists yet')"
			:description="t('chronos', 'Create a list in the Tasks app, and it becomes a project here.')" />

		<p v-if="board.selection.length > 1" :class="$style.hint">
			{{ n('chronos', '%n card selected — dragging one moves them all', '%n cards selected — dragging one moves them all', board.selection.length) }}
		</p>

		<p v-else :class="$style.hint">
			{{ t('chronos', 'Drag a card, or focus one and press space: the arrow keys move it, space drops it, escape puts it back. Ctrl or shift picks more than one card.') }}
		</p>

		<!--
			The copy that follows the pointer. Its transform is written
			directly in a frame callback, never through Vue (§15.2).
		-->
		<ul
			v-if="previewCard"
			ref="dragPreview"
			:class="$style.preview"
			aria-hidden="true">
			<KanbanCard
				:card="previewCard"
				:selected="false"
				:dragging="false"
				:readOnly="true"
				:subtaskCount="subtaskCounts[previewCard.uid] ?? 0" />
			<li v-if="drag.dragging.value.length > 1" :class="$style.stacked">
				{{ n('chronos', 'and %n more', 'and %n more', drag.dragging.value.length - 1) }}
			</li>
		</ul>

		<!-- What a screen reader hears while a card is in the air (§15.7). -->
		<div :class="$style.live" aria-live="polite" role="status">
			{{ drag.announcement.value }}
		</div>

		<NcModal
			v-if="pending"
			:name="t('chronos', 'This move needs a note')"
			@close="pending = null">
			<div :class="$style.noteBody">
				<label :class="$style.label" for="chronos-move-note">
					{{ t('chronos', 'Why is it moving?') }}
				</label>
				<textarea
					id="chronos-move-note"
					v-model="note"
					rows="3"
					:placeholder="t('chronos', 'Kept with the status change, in the task’s history')" />
				<div :class="$style.actions">
					<NcButton variant="tertiary" @click="pending = null">
						{{ t('chronos', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="note.trim() === ''" @click="dropWithNote">
						{{ t('chronos', 'Move the card') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<TaskDetailDialog
			v-if="editing"
			:task="editing"
			@close="editing = null"
			@save="saveDetails" />

		<ColumnSettingsDialog
			v-if="configuring && board.board"
			:board="board.board"
			:column="configuring"
			@close="configuring = null"
			@saved="columnSaved" />
	</div>
</template>

<style module>
.view {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.count {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.picker {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.select {
	flex: 0 1 420px;
}

.spacer {
	flex: 1;
}

.archived {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.loading {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.lanes {
	display: flex;
	gap: 12px;
	align-items: flex-start;
	overflow-x: auto;
	overflow-y: hidden;
	padding-bottom: 8px;
	min-height: 320px;
	max-height: calc(100vh - 320px);
}

.empty {
	padding: 24px 0;
}

.hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.preview {
	position: fixed;
	inset-block-start: 0;
	inset-inline-start: 0;
	z-index: 10000;
	width: 280px;
	margin: 0;
	padding: 0;
	pointer-events: none;
	opacity: 0.95;
	filter: drop-shadow(0 6px 12px rgb(0 0 0 / 25%));
	transform: translate3d(-9999px, -9999px, 0);
}

.stacked {
	list-style: none;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding-block-start: 4px;
}

.live {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.noteBody {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.label {
	font-size: 13px;
	font-weight: 600;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
