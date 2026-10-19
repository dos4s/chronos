<script setup lang="ts">
import type { Roadmap, RoadmapMilestone, RoadmapRow, Task, TaskWritePayload } from '../types/index.ts'
import type { Zoom } from '../utils/scale.ts'

import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import GanttScale from '../components/GanttScale.vue'
import ShortcutsDialog from '../components/ShortcutsDialog.vue'
import TaskDetailDialog from '../components/TaskDetailDialog.vue'
import { messageFor } from '../api/client.ts'
import { fetchRoadmap } from '../api/roadmap.ts'
import { fetchTasks } from '../api/tasks.ts'
import { useNow } from '../composables/useNow.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTasksStore } from '../stores/tasks.ts'
import { milestoneOn } from '../utils/compose.ts'
import { formatDate } from '../utils/format.ts'
import { createScale, windowFor } from '../utils/scale.ts'

/**
 * The roadmap (§12.6).
 *
 * The timeline asks when a task runs; this asks the same thing about the
 * projects, which is a different screen: one line each, grouped by area, with
 * the milestones marked on it and nothing to drag. Clicking a line opens its
 * timeline, which is where the tasks — and the dragging — are.
 *
 * It shares the timeline's scale and header, so a project read here and opened
 * there is drawn by the same arithmetic (§15.4).
 *
 * The one thing it writes is the one thing it is about: a milestone is a date
 * something has to be true by, and this is the screen where those dates are
 * decided. Double-clicking a project's lane puts one there and opens it to be
 * named; clicking a diamond opens it again, to move, rename or delete (§14.2).
 */
const router = useRouter()
const projects = useProjectsStore()
const tasks = useTasksStore()
const { now } = useNow()

const roadmap = ref<Roadmap | null>(null)
const loading = ref(false)
const zoom = ref<Zoom>('quarter')
const shortcuts = ref(false)

/** The milestone being edited, which is a task like any other (§6). */
const editing = ref<Task | null>(null)
/** The one a *Delete* is waiting on an answer about. */
const deleting = ref<Task | null>(null)

const chart = ref<HTMLElement | null>(null)
const scrollLeft = ref(0)
const scrollTop = ref(0)

/** Every line is this tall, in the list and on the chart alike. */
const ROW_HEIGHT = 34

/**
 * Every project the sidebar has in scope. With nothing selected that is all of
 * them, which is the roadmap's usual case — it is the cross-project view — and
 * with a selection it is those, because the sidebar scopes every view (§14.2).
 */
const rows = computed(() => (roadmap.value?.rows ?? []).filter((row) => projects.matches(row.ref)))

const scale = computed(() => {
	const window = windowFor(roadmap.value?.from ?? null, roadmap.value?.to ?? null, now.value, zoom.value)

	return createScale(zoom.value, window.from, window.to)
})

/**
 * The lines with their area headings worked in, so the chart and the list can
 * walk the same sequence and stay aligned.
 */
const lines = computed(() => {
	const areas = roadmap.value?.areas ?? []
	const out: ({ kind: 'area', name: string } | { kind: 'row', row: RoadmapRow })[] = []

	// With no areas of their own the projects all land in the one catch-all
	// group, and a heading over every line in the chart says nothing (§14.2).
	const grouped = areas.some((area) => area.name !== null)

	for (const area of areas) {
		const mine = rows.value.filter((row) => row.areaId === area.id)
		if (mine.length === 0) {
			continue
		}

		if (grouped) {
			out.push({ kind: 'area', name: area.name ?? t('chronos', 'No area') })
		}
		for (const row of mine) {
			out.push({ kind: 'row', row })
		}
	}

	return out
})

const height = computed(() => lines.value.length * ROW_HEIGHT)
const todayX = computed(() => scale.value.xOf(now.value, false))

function placeOf(row: RoadmapRow) {
	return row.span === null ? null : scale.value.place(row.span)
}

function onScroll(): void {
	const element = chart.value
	if (element === null) {
		return
	}
	scrollLeft.value = element.scrollLeft
	scrollTop.value = element.scrollTop
}

/** Whether this project's list can be written to; a shared one may not be. */
function canWrite(row: RoadmapRow): boolean {
	return projects.find(row.ref)?.writable === true
}

/**
 * A milestone put on a lane at the date it was dropped on.
 *
 * It is created named *New milestone* and opened straight away, because the
 * date is the half the gesture knows and the name is the half it does not. On
 * a subproject's line it belongs to that subproject, which is the line that
 * was pointed at (§12.6).
 */
async function addMilestone(event: MouseEvent, row: RoadmapRow): Promise<void> {
	const element = chart.value
	if (element === null || !canWrite(row)) {
		return
	}

	const x = event.clientX - element.getBoundingClientRect().left + element.scrollLeft
	const written = await tasks.add(row.ref, t('chronos', 'New milestone'), {
		...milestoneOn(scale.value.atOf(x, true)),
		parentUid: row.parentUid,
	})

	if (written !== null) {
		await load()
		editing.value = written
	}
}

/**
 * Opening a diamond. The roadmap carries a milestone's date and name but not
 * the task behind it — it reads every project at once, and sending every
 * field of every task for a screen of diamonds would be a waste — so the one
 * that was clicked is read from its own list.
 */
async function openMilestone(milestone: RoadmapMilestone, row: RoadmapRow): Promise<void> {
	try {
		const found = (await fetchTasks(row.ref)).find((task) => task.uid === milestone.uid)
		if (found !== undefined) {
			editing.value = found
		}
	} catch (error) {
		showError(messageFor(error))
	}
}

async function saveMilestone(changes: Partial<TaskWritePayload>): Promise<void> {
	const task = editing.value
	if (task !== null && await tasks.save(task, changes)) {
		editing.value = null
		await load()
	}
}

async function confirmDelete(): Promise<void> {
	const task = deleting.value
	if (task === null) {
		return
	}

	if (await tasks.remove(task)) {
		deleting.value = null
		editing.value = null
		await load()
	}
}

function open(row: RoadmapRow): void {
	// Opening a line means working in that project, so the sidebar follows.
	projects.choose(row.ref)
	void router.push({ name: 'timeline', query: { project: row.ref } })
}

function label(row: RoadmapRow): string {
	if (row.tasks === 0) {
		return t('chronos', 'Nothing in it yet')
	}

	return row.open === 0
		? n('chronos', '%n task, all done', '%n tasks, all done', row.tasks)
		: t('chronos', '{open} of {total} open', { open: row.open, total: row.tasks })
}

async function load(): Promise<void> {
	loading.value = true
	try {
		roadmap.value = await fetchRoadmap(projects.showArchived)
	} catch (error) {
		showError(messageFor(error))
	} finally {
		loading.value = false
	}
}

// The sidebar decides whether the archived projects are in play (§14.2), and
// that is a question for the server: it reads the lists either way.
watch(() => projects.showArchived, () => {
	void load()
})

onMounted(async () => {
	window.addEventListener('resize', onScroll)
	await load()

	// The chart only exists once there is something to draw, which is after
	// Vue has rendered what `load()` fetched.
	await nextTick()
	const element = chart.value
	if (element !== null) {
		element.scrollLeft = Math.max(0, todayX.value - element.clientWidth / 3)
	}
})

onBeforeUnmount(() => {
	window.removeEventListener('resize', onScroll)
})
</script>

<template>
	<div :class="$style.view">
		<div class="chronos-view-head">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Roadmap') }}
			</h2>
			<span v-if="rows.length" :class="$style.count">
				{{ n('chronos', '%n project', '%n projects', rows.length) }}
			</span>
		</div>

		<div :class="$style.toolbar">
			<div :class="$style.spacer" />
			<NcButton
				variant="tertiary"
				:aria-label="t('chronos', 'Keyboard and gestures')"
				:title="t('chronos', 'Keyboard and gestures')"
				@click="shortcuts = true">
				?
			</NcButton>
			<div :class="$style.zooms" role="group" :aria-label="t('chronos', 'Zoom')">
				<NcButton
					v-for="level in (['quarter', 'month', 'week'] as Zoom[])"
					:key="level"
					:variant="zoom === level ? 'primary' : 'tertiary'"
					@click="zoom = level">
					{{ level === 'quarter' ? t('chronos', 'Quarters') : level === 'month' ? t('chronos', 'Months') : t('chronos', 'Weeks') }}
				</NcButton>
			</div>
		</div>

		<div v-if="loading" :class="$style.loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="lines.length" :class="$style.grid">
			<div :class="[$style.cell, $style.corner]">
				{{ t('chronos', 'Project') }}
			</div>

			<div :class="[$style.cell, $style.scaleHead]">
				<div :style="{ transform: `translateX(${-scrollLeft}px)` }">
					<GanttScale :scale="scale" />
				</div>
			</div>

			<div :class="[$style.cell, $style.list]">
				<div :style="{ transform: `translateY(${-scrollTop}px)` }">
					<template v-for="(line, index) in lines" :key="index">
						<div v-if="line.kind === 'area'" :class="[$style.line, $style.areaRow]">
							{{ line.name }}
						</div>
						<button
							v-else
							:class="[$style.line, $style.project]"
							:style="{ paddingInlineStart: `${10 + line.row.depth * 14}px` }"
							@click="open(line.row)">
							<span :class="$style.name">{{ line.row.name }}</span>
							<span :class="$style.meta">{{ label(line.row) }}</span>
						</button>
					</template>
				</div>
			</div>

			<div
				ref="chart"
				:class="[$style.cell, $style.chart]"
				@scroll.passive="onScroll">
				<div
					:class="$style.canvas"
					:style="{ width: `${scale.width}px`, height: `${height}px` }">
					<span
						:class="$style.today"
						:style="{ insetInlineStart: `${todayX}px` }"
						:title="t('chronos', 'Today')"
						aria-hidden="true" />

					<div
						v-for="(line, index) in lines"
						:key="index"
						:class="$style.lane"
						:style="{ insetBlockStart: `${index * ROW_HEIGHT}px` }"
						@dblclick="line.kind === 'row' && addMilestone($event, line.row)">
						<template v-if="line.kind === 'row'">
							<span
								v-if="placeOf(line.row)"
								:class="$style.span"
								:style="{
									insetInlineStart: `${placeOf(line.row)!.x}px`,
									width: `${placeOf(line.row)!.width}px`,
									background: line.row.color || 'var(--color-primary-element)',
								}"
								:title="line.row.name" />
							<button
								v-for="milestone in line.row.milestones"
								:key="milestone.uid"
								:class="[$style.milestone, { [$style.met]: milestone.done }]"
								:style="{ insetInlineStart: `${scale.xOf(milestone.at, milestone.isDate)}px` }"
								:title="`${milestone.summary || t('chronos', 'Milestone')} — ${formatDate(milestone.at, milestone.isDate)}`"
								:aria-label="t('chronos', 'Milestone {name} on {date}', {
									name: milestone.summary || t('chronos', 'Untitled'),
									date: formatDate(milestone.at, milestone.isDate),
								})"
								@click.stop="openMilestone(milestone, line.row)"
								@dblclick.stop />
						</template>
					</div>
				</div>
			</div>
		</div>

		<NcEmptyContent
			v-else
			:class="$style.empty"
			:name="t('chronos', 'No projects to map')"
			:description="t('chronos', 'Create a list in the Tasks app, and it becomes a project here.')" />

		<p :class="$style.hint">
			{{ t('chronos', 'Each line is a project or one of its subprojects, from its earliest task to its latest. Diamonds are milestones; a hollow one has been met. Press ? for what can be done here.') }}
		</p>

		<TaskDetailDialog
			v-if="editing"
			:task="editing"
			deletable
			@close="editing = null"
			@save="saveMilestone"
			@remove="deleting = editing" />

		<ConfirmDialog
			v-if="deleting"
			:name="t('chronos', 'Delete this milestone?')"
			:message="t('chronos', '“{name}” will be deleted from its list, and from the Tasks app and your phone with it. This cannot be undone.', { name: deleting.summary || t('chronos', 'Untitled task') })"
			:busy="tasks.loading"
			@close="deleting = null"
			@confirm="confirmDelete" />

		<ShortcutsDialog v-if="shortcuts" @close="shortcuts = false" />
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

.grid {
	display: grid;
	grid-template-columns: clamp(130px, 32vw, 300px) 1fr;
	grid-template-rows: 44px 1fr;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	/* As tall as its lines, and never taller than the window (§14.2). */
	max-height: max(240px, calc(100vh - 300px));
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

.list {
	border-inline-end: 1px solid var(--color-border);
}

.chart {
	overflow: auto;
	position: relative;
}

.canvas {
	position: relative;
}

.line {
	height: 34px;
	display: flex;
	flex-direction: column;
	justify-content: center;
	box-sizing: border-box;
	border-block-end: 1px solid var(--color-border-dark);
	padding-inline: 10px;
	width: 100%;
	text-align: start;
}

.areaRow {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
}

/*
 * Qualified with the element on purpose: Nextcloud styles every plain button as
 * a filled pill through `button:not(.button-vue, …)`, which outranks a single
 * class, so a bespoke control styled by its class alone came out as a green box
 * (§15.10).
 */
button.project {
	background: transparent;
	border-inline: none;
	border-block-start: none;
	border-radius: 0;
	min-height: 0;
	width: 100%;
	padding-block: 0;
	cursor: pointer;
	font: inherit;
	color: inherit;
}

.project:hover {
	background: var(--color-background-hover);
}

.name {
	font-size: 13px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.meta {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
}

/* Narrow: the project's name is what the line is for. */
@media (max-width: 700px) {
	.meta {
		display: none;
	}

	.project {
		justify-content: center;
	}
}

.lane {
	position: absolute;
	inset-inline: 0;
	height: 34px;
	border-block-end: 1px solid var(--color-border-dark);
	box-sizing: border-box;
}

.span {
	position: absolute;
	inset-block-start: 12px;
	height: 10px;
	border-radius: 5px;
	opacity: 0.85;
}

/*
 * Qualified with the element on purpose: Nextcloud styles every plain button as
 * a filled pill through `button:not(.button-vue, …)`, which outranks a single
 * class, so a bespoke control styled by its class alone came out as a green box
 * (§15.10).
 */
button.milestone {
	position: absolute;
	min-height: 0;
	padding: 0;
	border: none;
	border-radius: 0;
	inset-block-start: 10px;
	width: 12px;
	height: 12px;
	margin-inline-start: -6px;
	background: var(--color-warning-text);
	transform: rotate(45deg);
}

/* A milestone that has been met is hollow: it is history, not a deadline. */
button.met {
	background: transparent;
	border: 2px solid var(--color-success-text);
}

.today {
	position: absolute;
	inset-block: 0;
	width: 2px;
	background: var(--color-error-text);
	opacity: 0.6;
	pointer-events: none;
}

.hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
