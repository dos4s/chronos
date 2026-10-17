<script setup lang="ts">
import type { UnixSeconds } from '../api/time.ts'
import type { DragKind } from '../composables/useTimeDrag.ts'
import type { PlanBar, Session, SessionUpdatePayload } from '../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import BreakMenu from '../components/BreakMenu.vue'
import SessionEditorModal from '../components/SessionEditorModal.vue'
import ShortcutsDialog from '../components/ShortcutsDialog.vue'
import { addDays, asSeconds, startOfDay } from '../api/time.ts'
import { useTimeDrag } from '../composables/useTimeDrag.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTimeStore } from '../stores/time.ts'
import { clampPause, clampSession, endedPauses, pausesAfter, sessionAfter } from '../utils/editor.ts'
import { formatDuration, formatTimeOfDay, formatWeekday } from '../utils/format.ts'
import { formatDate } from '../utils/format.ts'
import { createScale } from '../utils/scale.ts'
import { fromTimeInput, toTimeInput } from '../utils/timeInput.ts'

/**
 * The session editor, rebuilt on the shared engine (§15.4, §15.8).
 *
 * It used to be a calendar library showing blocks nobody could touch: to
 * change a session you opened a dialog and typed. Now a day is a row, a
 * session is a block along it, and the breaks are blocks inside the block —
 * all of them draggable, with the same engine and the same arithmetic as the
 * timeline (`useTimeDrag`, `utils/scale.ts`). The dialog and the break menu
 * are still there, because typing a time is sometimes exactly what you want.
 *
 * What a drag may do lives in `utils/editor.ts` and is applied to the preview
 * as well as to the write, so a block cannot be dragged somewhere it will not
 * be saved: a break stays inside its session and clear of the breaks either
 * side of it, nothing becomes shorter than five minutes, and moving a session
 * carries its breaks along.
 */
const time = useTimeStore()
const projects = useProjectsStore()

const editing = ref<Session | null>(null)
const menu = ref<{
	x: number
	y: number
	session: Session
	from: UnixSeconds
	to: UnixSeconds
	initialStart: string
} | null>(null)

const shortcuts = ref(false)
const chart = ref<HTMLElement | null>(null)
const scrollLeft = ref(0)
const scrollTop = ref(0)

/** Every day is a row this tall, which is also how a block is placed. */
const ROW_HEIGHT = 48

/** A block the pointer can take hold of: a session, or one of its breaks. */
interface Block {
	id: string
	kind: 'session' | 'pause'
	session: Session
	/** Which of the session's breaks, for a break block. */
	index: number
	bar: PlanBar
	dayStart: UnixSeconds
	row: number
	color: string | null
}

function timed(startAt: UnixSeconds, endAt: UnixSeconds): PlanBar {
	return {
		startAt,
		endAt,
		startIsDate: false,
		dueIsDate: false,
		inferred: false,
		isMilestone: false,
	}
}

/** The sessions this editor shows: finished ones, in the chosen projects. */
const shown = computed(() => time.sessions.filter((session) => session.endAt !== null && projects.matches(session.projectUri)))

/** One row per day that has a session, most recent first. */
const days = computed(() => {
	const seen = new Map<number, UnixSeconds>()
	for (const session of shown.value) {
		const day = startOfDay(session.startAt)
		seen.set(day, day)
	}

	return [...seen.values()].sort((a, b) => b - a)
})

const rowOfDay = computed(() => {
	const rows = new Map<number, number>()
	days.value.forEach((day, index) => {
		rows.set(day, index)
	})
	return rows
})

const blocks = computed<Block[]>(() => shown.value.flatMap((session) => {
	const dayStart = startOfDay(session.startAt)
	const row = rowOfDay.value.get(dayStart) ?? 0
	const color = projects.colorFor(session.projectUri)
	const end = session.endAt as UnixSeconds

	const own: Block = {
		id: `session:${session.id}`,
		kind: 'session',
		session,
		index: -1,
		bar: timed(session.startAt, end),
		dayStart,
		row,
		color,
	}

	return [own, ...endedPauses(session).map((pause, index): Block => ({
		id: `pause:${session.id}:${index}`,
		kind: 'pause',
		session,
		index,
		bar: timed(pause.startAt, pause.endAt),
		dayStart,
		row,
		color: null,
	}))]
}))

const byId = computed(() => {
	const all = new Map<string, Block>()
	for (const block of blocks.value) {
		all.set(block.id, block)
	}
	return all
})

/** A day is 24 hours wide; the scale is the timeline's, at its day zoom. */
function scaleFor(dayStart: UnixSeconds) {
	return createScale('day', dayStart, addDays(dayStart, 1))
}

const scale = computed(() => scaleFor(days.value[0] ?? startOfDay(asSeconds(Date.now() / 1000))))
const width = computed(() => scale.value.width)
const height = computed(() => Math.max(ROW_HEIGHT * 2, days.value.length * ROW_HEIGHT))

const drag = useTimeDrag({
	container: () => chart.value,
	scale: () => {
		const current = drag.dragging.value
		const block = current === null ? null : byId.value.get(current.uid)

		return scaleFor(block?.dayStart ?? days.value[0] ?? asSeconds(0))
	},
	barOf: (id: string) => byId.value.get(id)?.bar ?? null,
	canDrag: (id: string) => byId.value.has(id),
	rowAt: () => null,
	onMove: (id: string, kind: DragKind, bar: PlanBar) => {
		void write(id, bar)
	},
	onLink: () => {},
	clamp: (id: string, kind: DragKind, bar: PlanBar) => {
		const block = byId.value.get(id)
		if (block === undefined || kind === 'link') {
			return bar
		}

		return block.kind === 'session'
			? clampSession(block.session, kind, bar)
			: clampPause(block.session, block.index, kind, bar)
	},
	describe: (id: string, kind: DragKind, bar: PlanBar) => {
		const block = byId.value.get(id)

		return t('chronos', '{what}: {from} to {to}, {length}', {
			what: block?.kind === 'pause' ? t('chronos', 'Break') : block?.session.projectName ?? t('chronos', 'Session'),
			from: formatTimeOfDay(bar.startAt),
			to: formatTimeOfDay(bar.endAt),
			length: formatDuration(bar.endAt - bar.startAt),
		})
	},
})

/** The bar a block draws right now, which during a drag is the preview. */
function barOf(block: Block): PlanBar {
	const current = drag.dragging.value
	const preview = drag.preview.value
	if (current === null || preview === null) {
		return block.bar
	}

	if (current.uid === block.id) {
		return preview
	}

	// A session being moved carries its breaks, so they move on the screen too.
	const dragged = byId.value.get(current.uid)
	if (dragged?.kind === 'session' && dragged.session.id === block.session.id && block.kind === 'pause') {
		const delta = preview.startAt - dragged.bar.startAt
		const sameLength = preview.endAt - dragged.bar.endAt === delta

		return sameLength
			? timed(asSeconds(block.bar.startAt + delta), asSeconds(block.bar.endAt + delta))
			: block.bar
	}

	return block.bar
}

function place(block: Block) {
	const bar = barOf(block)
	const own = scaleFor(block.dayStart)
	const x = own.xOf(bar.startAt, false)
	const right = own.xOf(bar.endAt, false)

	return {
		x: Math.max(0, x),
		width: Math.max(4, Math.min(right, own.width) - Math.max(0, x)),
		// A session that runs past midnight is drawn to the end of its row.
		clipped: right > own.width + 1,
	}
}

/** Saves whatever a drag changed, as one update of the whole session. */
async function write(id: string, bar: PlanBar): Promise<void> {
	const block = byId.value.get(id)
	if (block === undefined) {
		return
	}

	const session = block.session
	if (block.kind === 'session') {
		const after = sessionAfter(session, bar)
		await time.update(session.id, {
			startAt: after.startAt,
			endAt: after.endAt,
			note: session.note,
			pauses: after.pauses,
		})
		return
	}

	await time.update(session.id, {
		startAt: session.startAt,
		endAt: session.endAt,
		note: session.note,
		pauses: pausesAfter(session, block.index, bar),
	})
}

function onScroll(): void {
	const element = chart.value
	if (element === null) {
		return
	}
	scrollLeft.value = element.scrollLeft
	scrollTop.value = element.scrollTop
}

function openMenu(event: MouseEvent, block: Block): void {
	if (block.kind !== 'session' || block.session.endAt === null) {
		return
	}

	menu.value = {
		x: event.clientX,
		y: event.clientY,
		session: block.session,
		from: block.session.startAt,
		to: block.session.endAt,
		// Half an hour in, which is usually about right.
		initialStart: toTimeInput(asSeconds(block.session.startAt + 1800)),
	}
}

async function addBreak(startTime: string, minutes: number): Promise<void> {
	const context = menu.value
	if (context === null) {
		return
	}

	const startAt = fromTimeInput(startTime, context.from)
	if (startAt === null) {
		showError(t('chronos', 'Enter a valid start time'))
		return
	}
	const endAt = asSeconds(startAt + minutes * 60)

	if (startAt <= context.from || endAt >= context.to) {
		showError(t('chronos', 'The break has to fit inside the session you clicked'))
		return
	}

	const session = context.session
	menu.value = null

	await time.update(session.id, {
		startAt: session.startAt,
		endAt: session.endAt,
		note: session.note,
		pauses: [...endedPauses(session), { startAt, endAt }],
	})
}

async function save(payload: SessionUpdatePayload): Promise<void> {
	const session = editing.value
	if (session === null) {
		return
	}
	if (await time.update(session.id, payload)) {
		editing.value = null
	}
}

/**
 * Opens on the working day rather than on midnight — once there is something
 * to open: the sessions are loaded by the app, so the chart does not exist
 * when this view is mounted.
 */
watch(days, async (loaded, before) => {
	if (loaded.length === 0 || (before ?? []).length > 0) {
		return
	}

	await nextTick()
	onScroll()
	const element = chart.value
	if (element !== null) {
		element.scrollLeft = 7 * scale.value.pxPerDay / 24
	}
}, { immediate: true })

onMounted(() => {
	window.addEventListener('resize', onScroll)
})

onBeforeUnmount(() => {
	window.removeEventListener('resize', onScroll)
})
</script>

<template>
	<div :class="$style.view">
		<div class="chronos-header">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Session editor') }}
			</h2>
			<span v-if="shown.length" :class="$style.count">
				{{ n('chronos', '%n session', '%n sessions', shown.length) }}
			</span>
			<NcButton
				variant="tertiary"
				:aria-label="t('chronos', 'Keyboard and gestures')"
				:title="t('chronos', 'Keyboard and gestures')"
				@click="shortcuts = true">
				?
			</NcButton>
		</div>

		<div v-if="days.length" :class="$style.grid">
			<div :class="[$style.cell, $style.corner]">
				{{ t('chronos', 'Day') }}
			</div>

			<div :class="[$style.cell, $style.hours]">
				<div :style="{ transform: `translateX(${-scrollLeft}px)`, width: `${width}px` }">
					<span
						v-for="tick in scale.ticks()"
						:key="tick.at"
						:class="[$style.tick, { [$style.major]: tick.major }]"
						:style="{ insetInlineStart: `${tick.x}px` }">
						{{ tick.label }}
					</span>
				</div>
			</div>

			<div :class="[$style.cell, $style.days]">
				<div :style="{ transform: `translateY(${-scrollTop}px)` }">
					<div
						v-for="(day, index) in days"
						:key="day"
						:class="$style.day"
						:style="{ insetBlockStart: `${index * ROW_HEIGHT}px` }">
						<span :class="$style.weekday">{{ formatWeekday(day) }}</span>
						<span :class="$style.date">{{ formatDate(day) }}</span>
					</div>
				</div>
			</div>

			<div
				ref="chart"
				:class="[$style.cell, $style.chart]"
				@scroll.passive="onScroll">
				<div :class="$style.canvas" :style="{ width: `${width}px`, height: `${height}px` }">
					<span
						v-for="tick in scale.ticks()"
						:key="tick.at"
						:class="$style.gridline"
						:style="{ insetInlineStart: `${tick.x}px` }"
						aria-hidden="true" />

					<div
						v-for="(day, index) in days"
						:key="day"
						:class="$style.lane"
						:style="{ insetBlockStart: `${index * ROW_HEIGHT}px` }"
						aria-hidden="true" />

					<template v-for="block in blocks" :key="block.id">
						<div
							:class="[
								block.kind === 'session' ? $style.session : $style.pause,
								{
									[$style.dragging]: drag.dragging.value?.uid === block.id,
									[$style.clipped]: place(block).clipped,
								},
							]"
							:style="{
								insetInlineStart: `${place(block).x}px`,
								width: `${place(block).width}px`,
								insetBlockStart: `${block.row * ROW_HEIGHT + (block.kind === 'session' ? 8 : 14)}px`,
								borderInlineStartColor: block.color ?? undefined,
							}"
							:tabindex="0"
							role="button"
							:aria-label="block.kind === 'session'
								? `${block.session.projectName ?? t('chronos', 'No project')} ${formatTimeOfDay(barOf(block).startAt)}`
								: t('chronos', 'Break')"
							:title="`${formatTimeOfDay(barOf(block).startAt)} – ${formatTimeOfDay(barOf(block).endAt)}`"
							@pointerdown="drag.pointerDown($event, block.id, 'move')"
							@keydown="drag.keyDown($event, block.id)"
							@dblclick.stop="block.kind === 'session' ? editing = block.session : null"
							@contextmenu.prevent.stop="openMenu($event, block)">
							<span v-if="block.kind === 'session'" :class="$style.label">
								{{ block.session.projectName ?? t('chronos', 'No project') }}
							</span>
							<span
								:class="[$style.grip, $style.gripStart]"
								@pointerdown.stop="drag.pointerDown($event, block.id, 'start')" />
							<span
								:class="[$style.grip, $style.gripEnd]"
								@pointerdown.stop="drag.pointerDown($event, block.id, 'end')" />
						</div>
					</template>

					<div
						v-if="drag.preview.value && drag.dragging.value"
						:class="$style.tooltip"
						:style="{
							insetInlineStart: `${place(byId.get(drag.dragging.value.uid)!).x}px`,
							insetBlockStart: `${(byId.get(drag.dragging.value.uid)!.row * ROW_HEIGHT) - 6}px`,
						}">
						{{ formatTimeOfDay(drag.preview.value.startAt) }} –
						{{ formatTimeOfDay(drag.preview.value.endAt) }} ·
						{{ formatDuration(drag.preview.value.endAt - drag.preview.value.startAt) }}
					</div>
				</div>
			</div>
		</div>

		<p v-else :class="$style.empty">
			{{ t('chronos', 'No finished sessions to edit yet. Check in, work, check out — and this is where you can tidy up what was recorded.') }}
		</p>

		<p :class="$style.hint">
			{{ t('chronos', 'Drag a session or one of its breaks; double-click for the exact times. A break stays inside its session, and moving a session takes its breaks with it.') }}
		</p>

		<!-- What a screen reader hears while a block is in the air (§15.7). -->
		<div :class="$style.live" aria-live="polite" role="status">
			{{ drag.announcement.value }}
		</div>

		<ShortcutsDialog v-if="shortcuts" @close="shortcuts = false" />

		<BreakMenu
			v-if="menu"
			:x="menu.x"
			:y="menu.y"
			:initialStart="menu.initialStart"
			@add="addBreak"
			@close="menu = null" />

		<SessionEditorModal
			v-if="editing"
			:session="editing"
			@close="editing = null"
			@save="save" />
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

/*
 * The same two-pane grid as the timeline: the chart is the only element that
 * scrolls, and the day column and the hour strip follow it by transform.
 */
.grid {
	display: grid;
	grid-template-columns: clamp(86px, 22vw, 140px) 1fr;
	grid-template-rows: 28px 1fr;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	min-height: 240px;
	max-height: max(240px, calc(100vh - 300px));
}

.cell {
	overflow: hidden;
}

.corner {
	display: flex;
	align-items: center;
	padding-inline: 8px;
	font-size: 12px;
	font-weight: 600;
	border-inline-end: 1px solid var(--color-border);
	border-block-end: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.hours {
	position: relative;
	border-block-end: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.tick {
	position: absolute;
	font-size: 10px;
	line-height: 27px;
	color: var(--color-text-maxcontrast);
	padding-inline-start: 3px;
	border-inline-start: 1px solid var(--color-border);
	white-space: nowrap;
}

.major {
	color: var(--color-main-text);
	font-weight: 600;
}

.days {
	position: relative;
	border-inline-end: 1px solid var(--color-border);
}

.day {
	position: absolute;
	inset-inline: 0;
	height: 48px;
	display: flex;
	flex-direction: column;
	justify-content: center;
	padding-inline: 8px;
	box-sizing: border-box;
	border-block-end: 1px solid var(--color-border-dark);
}

.weekday {
	font-size: 13px;
	font-weight: 600;
}

.date {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
}

/* Narrow: the weekday is enough to know which row you are on. */
@media (max-width: 700px) {
	.date {
		display: none;
	}

	.day {
		justify-content: center;
	}
}

.chart {
	overflow: auto;
	position: relative;
}

.canvas {
	position: relative;
}

.gridline {
	position: absolute;
	inset-block: 0;
	width: 1px;
	background: var(--color-border);
	opacity: 0.6;
}

.lane {
	position: absolute;
	inset-inline: 0;
	height: 48px;
	border-block-end: 1px solid var(--color-border-dark);
	box-sizing: border-box;
}

.session {
	position: absolute;
	height: 32px;
	border-radius: var(--border-radius);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-inline-start: 4px solid var(--color-primary-element-light);
	display: flex;
	align-items: center;
	cursor: grab;
	touch-action: none;
	user-select: none;
	box-sizing: border-box;
	overflow: hidden;
}

.pause {
	position: absolute;
	height: 20px;
	border-radius: 3px;
	background: repeating-linear-gradient(
		45deg,
		var(--color-background-dark),
		var(--color-background-dark) 4px,
		var(--color-background-darker, var(--color-border-dark)) 4px,
		var(--color-background-darker, var(--color-border-dark)) 8px
	);
	border: 1px solid var(--color-border-dark);
	cursor: grab;
	touch-action: none;
	box-sizing: border-box;
	z-index: 2;
}

.session:focus-visible,
.pause:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 1px;
}

.dragging {
	box-shadow: 0 2px 8px rgb(0 0 0 / 30%);
	z-index: 3;
}

/* A session that runs past midnight is drawn to the end of its row. */
.clipped {
	border-start-end-radius: 0;
	border-end-end-radius: 0;
	border-inline-end: 3px dotted var(--color-main-background);
}

.label {
	font-size: 11px;
	padding-inline: 6px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	pointer-events: none;
}

.grip {
	position: absolute;
	inset-block: 0;
	width: 6px;
	cursor: ew-resize;
	touch-action: none;
}

.gripStart {
	inset-inline-start: 0;
}

.gripEnd {
	inset-inline-end: 0;
}

.tooltip {
	position: absolute;
	z-index: 5;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 1px 6px;
	font-size: 11px;
	white-space: nowrap;
	pointer-events: none;
}

.empty {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0;
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
