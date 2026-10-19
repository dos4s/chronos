<script setup lang="ts">
import type { UnixSeconds } from '../api/time.ts'
import type { DragKind } from '../composables/useTimeDrag.ts'
import type { PlanBar, PlanLink, PlanRow, TaskWritePayload } from '../types/index.ts'
import type { TaskFilter } from '../utils/filter.ts'
import type { Zoom } from '../utils/scale.ts'

import { n, t } from '@nextcloud/l10n'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import FilterBar from '../components/FilterBar.vue'
import GanttBar from '../components/GanttBar.vue'
import GanttLinks from '../components/GanttLinks.vue'
import GanttRow from '../components/GanttRow.vue'
import GanttScale from '../components/GanttScale.vue'
import PlanSettingsDialog from '../components/PlanSettingsDialog.vue'
import ProjectHeading from '../components/ProjectHeading.vue'
import ShortcutsDialog from '../components/ShortcutsDialog.vue'
import TaskComposer from '../components/TaskComposer.vue'
import TaskDetailDialog from '../components/TaskDetailDialog.vue'
import { useNow } from '../composables/useNow.ts'
import { useTimeDrag } from '../composables/useTimeDrag.ts'
import { usePlanStore } from '../stores/plan.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTasksStore } from '../stores/tasks.ts'
import { filterRows, NO_FILTER, passes } from '../utils/filter.ts'
import { formatDate, formatDuration, formatTimeOfDay } from '../utils/format.ts'
import { inclusiveDue } from '../utils/planDates.ts'
import { createScale, dayAfter, windowFor } from '../utils/scale.ts'

/**
 * The timeline (§12.5, §15.4).
 *
 * A tree table on the left and a chart on the right, sharing one scroller: the
 * chart is the only element that scrolls, and the table and the header follow
 * it by transform. Everything on the chart is **positioned by arithmetic** —
 * a row's y is its index times the row height, a bar's x comes from the scale
 * — so nothing is ever measured, a thousand rows cost nothing to place, and a
 * drag is exact (§15.2).
 *
 * Only the rows in view are drawn, which is the other half of that: a project
 * with two thousand tasks renders about forty of them.
 *
 * A plan is also *made* here: a task added from the toolbar arrives with no
 * dates, as a row waiting for a bar, and a double-click on its lane gives it
 * one. Before this the timeline could only arrange tasks that had been written
 * somewhere else (§14.2).
 */
const plan = usePlanStore()
const projects = useProjectsStore()
const tasks = useTasksStore()
const route = useRoute()
const { now } = useNow()

const zoom = ref<Zoom>('month')
const editing = ref<PlanRow | null>(null)
const configuring = ref(false)
const shortcuts = ref(false)
/** Whether the composer is open, and what it is adding under (§14.2). */
const adding = ref(false)
const parent = ref<PlanRow | null>(null)
/** The task a *Delete* is waiting on an answer about. */
const deleting = ref<PlanRow | null>(null)

const chart = ref<HTMLElement | null>(null)
const scrollLeft = ref(0)
const scrollTop = ref(0)
const viewport = ref(480)

/** Every row is this tall, in the table and on the chart alike. */
const ROW_HEIGHT = 32

/** How many rows above and below the window to draw, so scrolling is calm. */
const OVERSCAN = 6

/** The project the sidebar is working in (§14.2). */
const project = computed(() => projects.currentProject)
/**
 * What is worth looking at: by default the unfinished work, because two
 * thirds of a real list is usually done, and 54 finished bars crowd a chart
 * as much as they crowd a list (§14.2).
 */
const filter = ref<TaskFilter>({ ...NO_FILTER })

const rows = computed(() => filterRows(plan.rows, filter.value))
const searching = computed(() => filter.value.query.trim() !== '')
const hidden = computed(() => Math.max(0, plan.rows.length - rows.value.length))
const readOnly = computed(() => plan.plan !== null && !plan.writable)

const scale = computed(() => {
	const window = windowFor(plan.plan?.from ?? null, plan.plan?.to ?? null, now.value, zoom.value)

	return createScale(zoom.value, window.from, window.to)
})

const canvasHeight = computed(() => Math.max(viewport.value, rows.value.length * ROW_HEIGHT))

/** Which rows are worth drawing, given where the chart is scrolled to. */
const visible = computed(() => {
	const first = Math.max(0, Math.floor(scrollTop.value / ROW_HEIGHT) - OVERSCAN)
	const count = Math.ceil(viewport.value / ROW_HEIGHT) + OVERSCAN * 2

	return rows.value
		.map((row, index) => ({ row, index }))
		.slice(first, first + count)
})

const indexOf = computed(() => {
	const all: Record<string, number> = {}
	rows.value.forEach((row, index) => {
		all[row.uid] = index
	})
	return all
})

const drag = useTimeDrag({
	container: () => chart.value,
	scale: () => scale.value,
	barOf: (uid: string) => plan.bars[uid] ?? null,
	canDrag,
	rowAt,
	onMove: (uid: string, kind: DragKind, bar: PlanBar) => {
		void plan.move(uid, bar)
	},
	onLink: (predecessorUid: string, successorUid: string) => {
		void plan.link(predecessorUid, successorUid)
	},
	describe: (uid: string, kind: DragKind, bar: PlanBar) => t('chronos', '{name}: {from} to {to}', {
		name: plan.rowOf[uid]?.summary || t('chronos', 'Untitled task'),
		from: when(bar.startAt, bar.startIsDate),
		to: when(inclusiveDue(bar.endAt, bar.dueIsDate), bar.dueIsDate),
	}),
})

/** A summary bar is derived and a read-only list is nobody's to change. */
function canDrag(uid: string): boolean {
	if (readOnly.value) {
		return false
	}
	const row = plan.rowOf[uid]

	return row !== undefined && !row.isSummary && plan.bars[uid] !== undefined
}

/** Which row a pointer is over, worked out rather than measured. */
function rowAt(clientY: number): string | null {
	const element = chart.value
	if (element === null) {
		return null
	}

	const index = Math.floor((clientY - element.getBoundingClientRect().top + element.scrollTop) / ROW_HEIGHT)

	return rows.value[index]?.uid ?? null
}

/**
 * The bars as they are being dragged: the one under the pointer where it would
 * land, and — in cascade mode — ghosts where its dependents would follow
 * (§15.3). Worked out by the same function the server will use (AD16).
 */
const live = computed(() => {
	const current = drag.dragging.value
	const preview = drag.preview.value

	if (current === null || preview === null) {
		return { bars: plan.bars, ghosts: new Set<string>(), broken: plan.broken }
	}

	const result = plan.preview(current.uid, preview)

	return {
		bars: { ...plan.bars, ...result.bars },
		ghosts: new Set(Object.keys(result.bars).filter((uid) => uid !== current.uid)),
		broken: new Set(result.conflicts.map((conflict) => `${conflict.predecessorUid}>${conflict.successorUid}`)),
	}
})

/** The bar a row draws right now, which during a drag is the preview. */
function barOf(row: PlanRow): PlanBar | null {
	return live.value.bars[row.uid] ?? row.bar
}

const todayX = computed(() => scale.value.xOf(now.value, false))
const stripes = computed(() => scale.value.stripes(plan.settings.workingDays))

/** What the drag says it will do, pinned above the bar it is moving. */
const tooltip = computed(() => {
	const current = drag.dragging.value
	const bar = drag.preview.value
	if (current === null || bar === null || current.kind === 'link') {
		return null
	}

	const place = scale.value.place(bar)
	const extra = live.value.ghosts.size

	return {
		x: place.x,
		y: (indexOf.value[current.uid] ?? 0) * ROW_HEIGHT,
		text: t('chronos', '{from} → {to} · {length}', {
			from: when(bar.startAt, bar.startIsDate),
			to: when(inclusiveDue(bar.endAt, bar.dueIsDate), bar.dueIsDate),
			length: formatDuration(bar.endAt - bar.startAt),
		}),
		extra: extra === 0 ? null : n('chronos', '%n task follows', '%n tasks follow', extra),
	}
})

function when(at: UnixSeconds, isDate: boolean): string {
	return isDate ? formatDate(at, true) : `${formatDate(at)} ${formatTimeOfDay(at)}`
}

function onScroll(): void {
	const element = chart.value
	if (element === null) {
		return
	}

	scrollLeft.value = element.scrollLeft
	scrollTop.value = element.scrollTop
	viewport.value = element.clientHeight
}

/**
 * A double-click on an empty row gives the task a day to live in. Most tasks
 * in a real list have no dates at all, and dragging a bar out of nothing is
 * how a plan starts (§12.5).
 */
function onLaneDoubleClick(event: MouseEvent, row: PlanRow): void {
	const element = chart.value
	if (element === null || readOnly.value || row.bar !== null || row.isSummary) {
		return
	}

	const x = event.clientX - element.getBoundingClientRect().left + element.scrollLeft
	const at = scale.value.atOf(x, true)

	void plan.move(row.uid, {
		startAt: at,
		endAt: dayAfter(at, true),
		startIsDate: true,
		dueIsDate: true,
		inferred: false,
		isMilestone: false,
	})
}

function scrollToToday(): void {
	const element = chart.value
	if (element !== null) {
		element.scrollLeft = Math.max(0, todayX.value - element.clientWidth / 2)
	}
}

function removeLink(link: PlanLink): void {
	void plan.unlink(link.predecessorUid, link.successorUid)
}

function startAdding(under: PlanRow | null = null): void {
	parent.value = under
	adding.value = true
}

function stopAdding(): void {
	adding.value = false
	parent.value = null
}

/**
 * A task added from here has no dates: most tasks in a real list have none,
 * and a plan starts by dragging a bar out of a row that has none (§12.5). It
 * arrives as a row, in the tree under its parent if it was given one.
 */
async function addTask(summary: string): Promise<void> {
	const into = project.value
	if (into === null) {
		return
	}

	const under = parent.value
	const written = await tasks.add(into.uri, summary, { parentUid: under?.uid ?? null })
	if (written === null) {
		return
	}

	if (under !== null) {
		stopAdding()
	}

	// A task added while a search is on would be hidden by that search the
	// moment it arrived, which looks like a write that failed.
	if (searching.value && !passes(written, filter.value)) {
		filter.value = { ...filter.value, query: '' }
	}

	await plan.refresh()
}

/**
 * Taking a task's dates off, which is how a bar leaves the chart. There is no
 * gesture for it — a bar can be dragged anywhere except out of existence.
 */
async function clearDates(row: PlanRow): Promise<void> {
	const cleared = await tasks.save(row, {
		startAt: null,
		startIsDate: true,
		dueAt: null,
		dueIsDate: true,
	})

	if (cleared) {
		await plan.refresh()
	}
}

async function confirmDelete(): Promise<void> {
	const row = deleting.value
	if (row === null) {
		return
	}

	if (await tasks.remove(row)) {
		deleting.value = null
		if (editing.value?.uid === row.uid) {
			editing.value = null
		}
		await plan.refresh()
	}
}

async function saveDetails(changes: Partial<TaskWritePayload>): Promise<void> {
	const row = editing.value
	if (row === null) {
		return
	}

	if (await tasks.save(row, changes)) {
		editing.value = null
		await plan.refresh()
	}
}

async function saveSettings(next: { mode: 'warn' | 'cascade', workingDays: number[] }): Promise<void> {
	if (await plan.configure(next)) {
		configuring.value = false
	}
}

watch(project, (selected) => {
	void plan.select(selected?.uri ?? null)
}, { immediate: true })

watch(() => plan.plan, async (loaded) => {
	if (loaded === null) {
		return
	}

	// `nextTick`, not a microtask: the chart is behind a `v-else-if` on the
	// rows, so it does not exist until Vue has rendered the plan that just
	// arrived — and scrolling an element that is not there does nothing at
	// all, quietly.
	await nextTick()
	onScroll()
	scrollToToday()
})

onMounted(async () => {
	window.addEventListener('resize', onScroll)

	if (projects.projects.length === 0) {
		await projects.refresh()
	}

	// A roadmap line opens the timeline it is about (§12.6), which means
	// working in that project — the sidebar follows, because it is the one
	// place a project is chosen.
	const asked = route.query.project
	if (typeof asked === 'string' && projects.find(asked) !== null) {
		projects.choose(asked)
	}
})

onBeforeUnmount(() => {
	window.removeEventListener('resize', onScroll)
})
</script>

<template>
	<div :class="$style.view">
		<div class="chronos-header">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Timeline') }}
			</h2>
			<span v-if="rows.length" :class="$style.count">
				{{ n('chronos', '%n task', '%n tasks', rows.length) }}
			</span>
		</div>

		<div :class="$style.toolbar">
			<ProjectHeading />
			<FilterBar
				v-if="plan.plan"
				v-model="filter"
				:hidden="hidden"
				:placeholder="t('chronos', 'Find a task…')" />

			<div :class="$style.spacer" />

			<div :class="$style.zooms" role="group" :aria-label="t('chronos', 'Zoom')">
				<NcButton
					v-for="level in (['month', 'week', 'day'] as Zoom[])"
					:key="level"
					:variant="zoom === level ? 'primary' : 'tertiary'"
					@click="zoom = level">
					{{ level === 'month' ? t('chronos', 'Months') : level === 'week' ? t('chronos', 'Weeks') : t('chronos', 'Days') }}
				</NcButton>
			</div>
			<NcButton
				v-if="plan.plan && !readOnly"
				variant="secondary"
				@click="adding ? stopAdding() : startAdding()">
				{{ t('chronos', 'Add a task') }}
			</NcButton>
			<NcButton variant="tertiary" @click="scrollToToday">
				{{ t('chronos', 'Today') }}
			</NcButton>
			<NcButton
				variant="tertiary"
				:aria-label="t('chronos', 'Keyboard and gestures')"
				:title="t('chronos', 'Keyboard and gestures')"
				@click="shortcuts = true">
				?
			</NcButton>
			<NcButton
				v-if="plan.plan"
				variant="secondary"
				@click="configuring = true">
				{{ plan.settings.mode === 'cascade' ? t('chronos', 'Moves cascade') : t('chronos', 'Moves warn') }}
			</NcButton>
		</div>

		<div v-if="adding" :class="$style.adding">
			<span v-if="parent" :class="$style.under">
				{{ t('chronos', 'Subtask of “{parent}”', { parent: parent.summary || t('chronos', 'Untitled task') }) }}
			</span>
			<TaskComposer
				autofocus
				:busy="tasks.loading"
				:placeholder="parent ? t('chronos', 'Add a subtask…') : t('chronos', 'Add a task — give it dates by dragging out a bar')"
				@submit="addTask"
				@cancel="stopAdding" />
		</div>

		<NcNoteCard v-if="readOnly" type="info">
			{{ t('chronos', 'This list is shared with you read-only, so its bars cannot be moved here.') }}
		</NcNoteCard>

		<div v-if="plan.initialLoading" :class="$style.loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="rows.length" :class="$style.grid">
			<div :class="[$style.cell, $style.corner]">
				{{ t('chronos', 'Task') }}
			</div>

			<div :class="[$style.cell, $style.scaleHead]">
				<div :style="{ transform: `translateX(${-scrollLeft}px)` }">
					<GanttScale :scale="scale" />
				</div>
			</div>

			<div :class="[$style.cell, $style.table]">
				<div :class="$style.tableInner" :style="{ transform: `translateY(${-scrollTop}px)` }">
					<GanttRow
						v-for="{ row, index } in visible"
						:key="row.uid"
						:row="row"
						:readOnly="readOnly"
						:style="{ insetBlockStart: `${index * ROW_HEIGHT}px`, height: `${ROW_HEIGHT}px` }"
						@open="editing = row"
						@addSubtask="startAdding(row)"
						@clearDates="clearDates(row)"
						@remove="deleting = row" />
				</div>
			</div>

			<div
				ref="chart"
				:class="[$style.cell, $style.chart]"
				@scroll.passive="onScroll">
				<div
					:class="$style.canvas"
					:style="{ width: `${scale.width}px`, height: `${canvasHeight}px` }">
					<span
						v-for="stripe in stripes"
						:key="stripe.x"
						:class="$style.stripe"
						:style="{ insetInlineStart: `${stripe.x}px`, width: `${stripe.width}px` }"
						aria-hidden="true" />

					<span
						:class="$style.today"
						:style="{ insetInlineStart: `${todayX}px` }"
						:title="t('chronos', 'Today')"
						aria-hidden="true" />

					<div
						v-for="{ row, index } in visible"
						:key="row.uid"
						:class="[$style.lane, {
							[$style.linkTarget]: drag.linkTarget.value === row.uid,
						}]"
						:style="{ insetBlockStart: `${index * ROW_HEIGHT}px` }"
						@dblclick="onLaneDoubleClick($event, row)">
						<GanttBar
							v-if="barOf(row)"
							:row="row"
							:bar="barOf(row)!"
							:scale="scale"
							:readOnly="readOnly"
							:ghost="live.ghosts.has(row.uid)"
							:dragging="drag.dragging.value?.uid === row.uid"
							:conflicted="plan.conflicts.some((conflict) => conflict.successorUid === row.uid)"
							@pick="(kind, event) => drag.pointerDown(event, row.uid, kind)"
							@grab="drag.keyDown($event, row.uid)"
							@open="editing = row" />
					</div>

					<GanttLinks
						:links="plan.links"
						:bars="live.bars"
						:indexOf="indexOf"
						:scale="scale"
						:rowHeight="ROW_HEIGHT"
						:broken="live.broken"
						:width="scale.width"
						:height="canvasHeight"
						@remove="removeLink" />

					<div
						v-if="tooltip"
						:class="$style.tooltip"
						:style="{ insetInlineStart: `${tooltip.x}px`, insetBlockStart: `${tooltip.y - 26}px` }">
						{{ tooltip.text }}
						<em v-if="tooltip.extra">· {{ tooltip.extra }}</em>
					</div>
				</div>
			</div>
		</div>

		<NcEmptyContent
			v-else-if="project"
			:class="$style.empty"
			:name="t('chronos', 'Nothing to put on a timeline')"
			:description="searching
				? t('chronos', 'Nothing here matches what you typed.')
				: t('chronos', 'A task added here arrives as a row; drag a bar across it to say when it runs.')">
			<template v-if="!readOnly && !searching" #action>
				<NcButton variant="primary" @click="startAdding()">
					{{ t('chronos', 'Add a task') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else
			:class="$style.empty"
			:name="t('chronos', 'No task lists yet')"
			:description="t('chronos', 'Create a list in the Tasks app, and it becomes a project here.')" />

		<p :class="$style.hint">
			{{ t('chronos', 'Drag a bar to move it, its edges to change a date, and the dot on its right onto another task. Press ? for the rest.') }}
		</p>

		<!-- What a screen reader hears while a bar is in the air (§15.7). -->
		<div :class="$style.live" aria-live="polite" role="status">
			{{ drag.announcement.value }}
		</div>

		<TaskDetailDialog
			v-if="editing"
			:task="editing"
			:deletable="!readOnly"
			@close="editing = null"
			@save="saveDetails"
			@remove="deleting = editing" />

		<ConfirmDialog
			v-if="deleting"
			:name="t('chronos', 'Delete this task?')"
			:message="t('chronos', '“{name}” will be deleted from this list, and from the Tasks app and your phone with it. Anything depending on it loses that link. This cannot be undone.', { name: deleting.summary || t('chronos', 'Untitled task') })"
			:busy="tasks.loading"
			@close="deleting = null"
			@confirm="confirmDelete" />

		<ShortcutsDialog v-if="shortcuts" @close="shortcuts = false" />

		<PlanSettingsDialog
			v-if="configuring"
			:settings="plan.settings"
			:saving="plan.saving"
			@close="configuring = false"
			@save="saveSettings" />
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

.toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.spacer {
	flex: 1;
}

.zooms {
	display: flex;
	gap: 2px;
}

.loading {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.empty {
	padding: 24px 0;
}

/*
 * Two columns and two rows: the table and the chart, with one header strip
 * above each. The chart is the only thing that scrolls; the other three follow
 * it by transform, which is what keeps them in step at any scroll position.
 */
.grid {
	display: grid;
	/*
	 * The table takes a third of a narrow window and 320px of a wide one, so
	 * the chart always has most of the room — on a phone it had none (§14.2).
	 */
	grid-template-columns: clamp(120px, 34vw, 320px) 1fr;
	grid-template-rows: 44px 1fr;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	min-height: 320px;
	/* Never shorter than a few rows, however short the window is. */
	max-height: max(260px, calc(100vh - 340px));
}

.cell {
	overflow: hidden;
}

.corner {
	display: flex;
	align-items: flex-end;
	padding: 0 8px 4px;
	font-size: 12px;
	font-weight: 600;
	border-inline-end: 1px solid var(--color-border);
	border-block-end: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.scaleHead {
	border-block-end: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.table {
	position: relative;
	border-inline-end: 1px solid var(--color-border);
}

.tableInner {
	position: relative;
	height: 100%;
}

.adding {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.under {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.chart {
	overflow: auto;
	position: relative;
}

.canvas {
	position: relative;
}

.stripe {
	position: absolute;
	inset-block: 0;
	background: var(--color-background-dark);
	pointer-events: none;
}

.today {
	position: absolute;
	inset-block: 0;
	width: 2px;
	background: var(--color-error);
	opacity: 0.6;
	pointer-events: none;
}

.lane {
	position: absolute;
	inset-inline: 0;
	height: 32px;
	border-block-end: 1px solid var(--color-border-dark);
	box-sizing: border-box;
}

/* The row a dependency is being dragged onto (§15.3). */
.linkTarget {
	background: var(--color-primary-element-light);
}

.tooltip {
	position: absolute;
	z-index: 5;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 2px 6px;
	font-size: 11px;
	white-space: nowrap;
	pointer-events: none;
	box-shadow: 0 2px 6px rgb(0 0 0 / 20%);
}

.hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.live {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}
</style>
