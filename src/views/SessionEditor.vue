<script setup lang="ts">
import type { UnixSeconds } from '../api/time.ts'
import type { Session, SessionUpdatePayload } from '../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import VueCal from 'vue-cal'
import BreakMenu from '../components/BreakMenu.vue'
import SessionEditorModal from '../components/SessionEditorModal.vue'
import { asSeconds, toDate } from '../api/time.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTimeStore } from '../stores/time.ts'
import { sessionChunks } from '../utils/sessions.ts'
import { fromTimeInput, toTimeInput } from '../utils/timeInput.ts'

import 'vue-cal/dist/vuecal.css'

interface CalendarEvent {
	id: string
	start: Date
	end: Date
	title: string
	content: string
	class: string
	session: Session
	projectColor: string | null
	isPause: boolean
}

const time = useTimeStore()
const projects = useProjectsStore()

const editing = ref<Session | null>(null)
const menu = ref<{ x: number, y: number, session: Session, from: UnixSeconds, to: UnixSeconds, initialStart: string } | null>(null)

const windowHeight = ref(window.innerHeight)
function onResize() {
	windowHeight.value = window.innerHeight
}
onMounted(() => window.addEventListener('resize', onResize))
onBeforeUnmount(() => window.removeEventListener('resize', onResize))

const calendarHeight = computed(() => Math.max(500, windowHeight.value - 200))

/**
 * One block per stretch of work, with the breaks drawn as hatched blocks in
 * between. Both come straight from the session's pause rows, so the picture
 * always matches what is stored.
 */
const events = computed<CalendarEvent[]>(() => {
	const result: CalendarEvent[] = []

	for (const session of time.sessions) {
		if (session.endAt === null || !projects.matches(session.projectUri)) {
			continue
		}

		const color = projects.colorFor(session.projectUri)
		let index = 0

		for (const chunk of sessionChunks(session, session.endAt)) {
			result.push({
				id: `${session.id}-work-${index++}`,
				start: toDate(chunk.startAt),
				end: toDate(chunk.endAt),
				title: session.projectName ?? t('chronos', 'No project'),
				content: session.note ?? '',
				class: 'chronos-work-event',
				session,
				projectColor: color,
				isPause: false,
			})
		}

		for (const pause of session.pauses) {
			if (pause.endAt === null) {
				continue
			}
			result.push({
				id: `${session.id}-break-${pause.id}`,
				start: toDate(pause.startAt),
				end: toDate(pause.endAt),
				title: t('chronos', 'Break'),
				content: '',
				class: 'chronos-pause-event',
				session,
				projectColor: null,
				isPause: true,
			})
		}
	}

	return result
})

function openEditor(event: CalendarEvent) {
	if (event.isPause) {
		return
	}
	editing.value = event.session
}

function openMenu(mouseEvent: MouseEvent, event: CalendarEvent) {
	if (event.isPause) {
		return
	}

	const from = asSeconds(event.start.getTime() / 1000)
	const to = asSeconds(event.end.getTime() / 1000)
	menu.value = {
		x: mouseEvent.clientX,
		y: mouseEvent.clientY,
		session: event.session,
		from,
		to,
		// Half an hour into the block, which is usually about right.
		initialStart: toTimeInput(asSeconds(from + 1800)),
	}
}

async function addBreak(startTime: string, minutes: number) {
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
		showError(t('chronos', 'The break has to fit inside the block you clicked'))
		return
	}

	const session = context.session
	menu.value = null

	await time.update(session.id, {
		startAt: session.startAt,
		endAt: session.endAt,
		note: session.note,
		pauses: [
			...session.pauses.map((pause) => ({ startAt: pause.startAt, endAt: pause.endAt })),
			{ startAt, endAt },
		],
	})
}

async function save(payload: SessionUpdatePayload) {
	const session = editing.value
	if (session === null) {
		return
	}
	if (await time.update(session.id, payload)) {
		editing.value = null
	}
}
</script>

<template>
	<div :class="$style.view">
		<div class="chronos-header">
			<h2 class="chronos-section-title">
				{{ t('chronos', 'Session editor') }}
			</h2>
		</div>

		<div class="chronos-panel" :class="[$style.panel]">
			<p :class="$style.subtitle">
				{{ t('chronos', 'Double-click a block to edit its times, or right-click it to add a break.') }}
			</p>

			<VueCal
				class="vuecal--blue-theme"
				:class="[$style.calendar]"
				hideViewSelector
				:timeFrom="6 * 60"
				:timeTo="24 * 60"
				:timeStep="30"
				scrollTo="08:00"
				:events="events"
				:editableEvents="{ title: false, drag: false, resize: false, delete: false, create: false }"
				:style="{ height: calendarHeight + 'px', width: '100%', borderRadius: '8px' }">
				<template #event="{ event }">
					<div
						:class="$style.event"
						:style="{ borderLeft: `5px solid ${event.projectColor || 'var(--color-primary)'}` }"
						@contextmenu.prevent.stop="openMenu($event, event)"
						@dblclick.prevent.stop="openEditor(event)">
						<strong>{{ event.title }}</strong>
						<small v-if="event.content">{{ event.content }}</small>
					</div>
				</template>
			</VueCal>

			<BreakMenu
				v-if="menu"
				:x="menu.x"
				:y="menu.y"
				:initialStart="menu.initialStart"
				@close="menu = null"
				@add="addBreak" />

			<SessionEditorModal
				v-if="editing"
				:session="editing"
				@close="editing = null"
				@save="save" />
		</div>
	</div>
</template>

<style module>
.view {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.panel {
	width: 100%;
	padding: 24px;
	box-sizing: border-box;
	align-items: stretch;
}

.subtitle {
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px;
	font-size: 13px;
}

.calendar {
	border-radius: var(--border-radius-large);
	border-color: var(--color-border);
	background: var(--color-main-background);
}

.event {
	background: var(--color-background-dark);
	border-radius: 4px;
	height: 100%;
	padding: 6px 8px;
	color: var(--color-main-text);
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
	overflow: hidden;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	justify-content: flex-start;
	cursor: pointer;
	user-select: none;
}
</style>
