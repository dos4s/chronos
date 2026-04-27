<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcModal from '@nextcloud/vue/components/NcModal'
import { getDialogBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import VueCal from 'vue-cal'
import 'vue-cal/dist/vuecal.css'

interface Entry {
	id: number
	userId: string
	startTime: number
	endTime: number | null
	note: string | null
	pausedAt: number | null
	pausedDuration: number
	projectUri: string | null
	projectName: string | null
	pausesBlob: string | null
}

interface TaskList {
	uri: string
	name: string
	color: string | null
}

const appBase = '/apps/chronos'
const entries = ref<Entry[]>([])
const active = ref<Entry | null>(null)
const taskLists = ref<TaskList[]>([])
const note = ref('')
const selectedProject = ref<TaskList | null>(null)
const loading = ref(false)
const initialLoading = ref(true)
const now = ref(Date.now())

const currentView = ref<'timer' | 'analytics' | 'calendar'>('timer')
const projectFilters = ref<string[]>([])

const projectGoals = ref<Record<string, number>>(
	JSON.parse(localStorage.getItem('chronos_goals') || '{}')
)
watch(projectGoals, (newVals) => {
	localStorage.setItem('chronos_goals', JSON.stringify(newVals))
}, { deep: true })

// Dynamic calendar height: fills the remaining viewport
const windowHeight = ref(window.innerHeight)
window.addEventListener('resize', () => { windowHeight.value = window.innerHeight })
const calHeight = computed(() => Math.max(500, windowHeight.value - 200))

let tickTimer: ReturnType<typeof setInterval> | null = null

function generateUrl(path: string): string {
	// @ts-expect-error OC is a Nextcloud global
	return OC.generateUrl(path)
}

function csrfToken(): string {
	// @ts-expect-error OC is a Nextcloud global
	return OC.requestToken
}

async function api<T>(
	method: 'GET' | 'POST' | 'PUT' | 'DELETE',
	path: string,
	body?: unknown,
): Promise<T> {
	const res = await fetch(generateUrl(appBase + path), {
		method,
		credentials: 'include',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			requesttoken: csrfToken(),
		},
		body: body ? JSON.stringify(body) : undefined,
	})
	if (!res.ok && res.status !== 409) {
		throw new Error(`HTTP ${res.status}`)
	}
	return (await res.json()) as T
}

async function refresh() {
	try {
		const [activeRes, listRes, listsRes] = await Promise.all([
			api<Entry | null>('GET', '/entries/active'),
			api<Entry[]>('GET', '/entries'),
			api<TaskList[]>('GET', '/tasklists'),
		])
		active.value = activeRes
		entries.value = listRes
		taskLists.value = listsRes
	} catch (e) {
		showError('Could not load data')
		console.error(e)
	} finally {
		initialLoading.value = false
	}
}

async function runAction(
	endpoint: string,
	body: unknown,
	successMsg: string,
	errorMsg: string,
) {
	loading.value = true
	try {
		await api<Entry>('POST', endpoint, body)
		await refresh()
		showSuccess(successMsg)
	} catch (e) {
		showError(errorMsg)
		console.error(e)
	} finally {
		loading.value = false
	}
}

function checkIn() {
	const body: Record<string, string> = {}
	if (note.value.trim()) body.note = note.value.trim()
	if (selectedProject.value) {
		body.projectUri = selectedProject.value.uri
		body.projectName = selectedProject.value.name
	}
	runAction('/entries/check-in', body, 'Checked in', 'Check-in failed').then(() => {
		note.value = ''
	})
}

// ──────────────────────────────────────────────
// SESSION EDITOR MODAL STATE
// ──────────────────────────────────────────────
const showEditorModal = ref(false)
const editorData = ref<{
	entry: Entry | null,
	dateString: string,
	startTime: string,
	endTime: string,
	pauses: { id: number, start: string, end: string }[]
}>({
	entry: null,
	dateString: '',
	startTime: '',
	endTime: '',
	pauses: []
})

let editorPauseIdCounter = 0

function msToTimeString(ms: number): string {
	if (!ms) return ''
	const d = new Date(ms)
	const hh = String(d.getHours()).padStart(2, '0')
	const mm = String(d.getMinutes()).padStart(2, '0')
	return `${hh}:${mm}`
}

function timeStringToMs(timeStr: string, baseDateStr: string): number {
	const defaultVal = Date.now()
	if (!timeStr) return defaultVal
	const [hh, mm] = timeStr.split(':')
	const t = new Date(baseDateStr)
	t.setHours(parseInt(hh, 10), parseInt(mm, 10), 0, 0)
	return t.getTime()
}

function openSessionEditorModal(calEvent: any) {
	const entry = calEvent.originalEntry
	if (!entry) return
	
	const baseDate = new Date(entry.startTime)
	const yyyy = baseDate.getFullYear()
	const mm = String(baseDate.getMonth() + 1).padStart(2, '0')
	const dd = String(baseDate.getDate()).padStart(2, '0')
	const dateStr = `${yyyy}-${mm}-${dd}`

	editorData.value.entry = entry
	editorData.value.dateString = dateStr
	editorData.value.startTime = msToTimeString(entry.startTime)
	editorData.value.endTime = entry.endTime ? msToTimeString(entry.endTime) : ''
	
	editorData.value.pauses = []
	if (entry.pausesBlob) {
		try {
			const p = JSON.parse(entry.pausesBlob)
			if (Array.isArray(p)) {
				editorData.value.pauses = p.map(pause => ({
					id: editorPauseIdCounter++,
					start: msToTimeString(pause.start),
					end: msToTimeString(pause.end)
				}))
			}
		} catch(e) {}
	}
	showEditorModal.value = true
}

function addEditorPause() {
	editorData.value.pauses.push({
		id: editorPauseIdCounter++,
		start: '',
		end: ''
	})
}

function removeEditorPause(id: number) {
	editorData.value.pauses = editorData.value.pauses.filter(p => p.id !== id)
}

function saveEditorModal() {
	const entry = editorData.value.entry
	if (!entry) return
	
	const baseDateStr = editorData.value.dateString
	const startMs = timeStringToMs(editorData.value.startTime, baseDateStr)
	const endMs = editorData.value.endTime ? timeStringToMs(editorData.value.endTime, baseDateStr) : (entry.endTime || Date.now())
	
	if (editorData.value.endTime && endMs <= startMs) {
		showError("La hora de fin debe ser posterior a la de inicio.")
		return
	}

	const pausesParsed = editorData.value.pauses
		.filter(p => p.start && p.end)
		.map(p => ({
			start: timeStringToMs(p.start, baseDateStr),
			end: timeStringToMs(p.end, baseDateStr)
		}))
		.filter(p => p.start < p.end && p.start >= startMs && p.end <= endMs)
		.sort((a,b) => a.start - b.start)
	
	submitCalibrationEdit(entry, startMs, endMs, pausesParsed).then(() => {
		showEditorModal.value = false
	})
}

const pause = () => runAction('/entries/pause', {}, 'Paused', 'Pause failed')
const resume = () => runAction('/entries/resume', {}, 'Resumed', 'Resume failed')
const checkOut = () => runAction('/entries/check-out', {}, 'Checked out', 'Check-out failed')

async function deleteEntry(entry: Entry) {
	const confirmed = await new Promise<boolean>((resolve) => {
		const dialog = getDialogBuilder('Delete session?')
			.setText(
				entry.note
					? `This will permanently delete "${entry.note}". This cannot be undone.`
					: 'This will permanently delete this session. This cannot be undone.',
			)
			.setButtons([
				{ label: 'Cancel', type: 'secondary', callback: () => resolve(false) },
				{ label: 'Delete', type: 'error', callback: () => resolve(true) },
			])
			.build()
		dialog.show().catch(() => resolve(false))
	})
	if (!confirmed) return

	loading.value = true
	try {
		await api<{ ok: true }>('DELETE', `/entries/${entry.id}`)
		await refresh()
		showSuccess('Session deleted')
	} catch (e) {
		showError('Delete failed')
		console.error(e)
	} finally {
		loading.value = false
	}
}

function effectiveDuration(e: Entry, nowMs: number): number {
	const end = e.endTime ?? nowMs
	let d = end - e.startTime - e.pausedDuration
	if (e.pausedAt !== null && e.endTime === null) {
		d -= nowMs - e.pausedAt
	}
	return Math.max(0, d)
}

function formatLiveDuration(ms: number): string {
	const totalSec = Math.max(0, Math.floor(ms / 1000))
	const h = Math.floor(totalSec / 3600)
	const m = Math.floor((totalSec % 3600) / 60)
	const s = totalSec % 60
	const pad = (n: number) => String(n).padStart(2, '0')
	return `${pad(h)}:${pad(m)}:${pad(s)}`
}

function formatRelativeDuration(ms: number): string {
	const totalSec = Math.max(0, Math.floor(ms / 1000))
	if (totalSec < 60) return `${totalSec}s`
	const h = Math.floor(totalSec / 3600)
	const m = Math.floor((totalSec % 3600) / 60)
	if (h === 0) return `${m}m`
	if (m === 0) return `${h}h`
	return `${h}h ${m}m`
}

function formatTime(ms: number): string {
	return new Date(ms).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatDate(ms: number): string {
	return new Date(ms).toLocaleDateString([], { day: '2-digit', month: 'short', year: 'numeric' })
}

const isPaused = computed(() => !!(active.value && active.value.pausedAt !== null))

const elapsed = computed(() =>
	active.value ? formatLiveDuration(effectiveDuration(active.value, now.value)) : '00:00:00',
)

const totalToday = computed(() => {
	const startOfDay = new Date()
	startOfDay.setHours(0, 0, 0, 0)
	const startMs = startOfDay.getTime()
	let total = 0
	for (const e of filteredEntries.value) {
		if (e.startTime < startMs) continue
		total += effectiveDuration(e, now.value)
	}
	return total
})

const weekStartMs = computed(() => {
	const today = new Date()
	today.setHours(0, 0, 0, 0)
	const weekStart = new Date(today)
	weekStart.setDate(today.getDate() - 6)
	return weekStart.getTime()
})

const weeklyTotalMs = computed(() => {
	const startMs = weekStartMs.value
	let total = 0
	for (const e of filteredEntries.value) {
		const end = e.endTime ?? now.value
		if (end < startMs) continue
		total += effectiveDuration(e, now.value)
	}
	return total
})

interface ProjectSummary {
	uri: string | null
	name: string
	color: string | null
	weekMs: number
	entries: number
}

// ------------------------------------
// VUE CALENDAR: CHUNKS LOGIC
// ------------------------------------
interface VueCalEvent {
	start: Date
	end: Date
	title: string
	content: string
	class: string
	id: string // Format: `entryId_chunkIndex` to rebuild later
	originalEntry: Entry
	projectColor: string | null
}

// Live preview override during top-resize drag
const activeTopResizeStart = ref<{ entryId: number; startMs: number } | null>(null)

const calendarEvents = computed<VueCalEvent[]>(() => {
	const preview = activeTopResizeStart.value // reactive dependency for live preview
	const evts: VueCalEvent[] = []
	for (const e of filteredEntries.value) {
		if (!e.endTime) continue
		const pColor = taskLists.value.find(t => t.uri === e.projectUri)?.color || null
		const baseClass = pColor ? 'cal-project-event' : 'cal-default-event'

		let pauses: { start: number; end: number }[] = []
		if (e.pausesBlob) {
			try {
				const p = JSON.parse(e.pausesBlob)
				if (Array.isArray(p)) {
					pauses = p.filter(x => x.start && x.end).sort((a, b) => a.start - b.start)
				}
			} catch (err) {}
		}

		// Apply live preview override for the first chunk's start
		const effectiveStart = (preview && preview.entryId === e.id) ? preview.startMs : e.startTime

		const chunks: any[] = []
		let pointer = effectiveStart
		let chunkIdx = 0
		for (const p of pauses) {
			if (p.start > pointer) {
				chunks.push({
					id: `${e.id}_${chunkIdx++}`,
					start: new Date(pointer),
					end: new Date(p.start),
					title: e.projectName || 'Unassigned',
					content: e.note || '',
					class: baseClass,
					originalEntry: e,
					projectColor: pColor,
					draggable: true,
					resizable: true,
				})
			}
			
			// Visual Pause event
			if (p.end > p.start) {
				evts.push({
					id: `${e.id}_pause_${chunkIdx++}`,
					start: new Date(p.start),
					end: new Date(p.end),
					title: 'Break',
					content: 'Rest',
					class: 'calPauseEvent',
					originalEntry: e,
					isPause: true,
					draggable: false,
					resizable: false,
				})
			}
			pointer = Math.max(pointer, p.end)
		}
		if (pointer < e.endTime) {
			chunks.push({
				id: `${e.id}_${chunkIdx++}`,
				start: new Date(pointer),
				end: new Date(e.endTime),
				title: e.projectName || 'Unassigned',
				content: e.note || '',
				class: baseClass,
				originalEntry: e,
				projectColor: pColor,
				draggable: true,
				resizable: true,
			})
		}

		if (chunks.length > 0) {
			chunks[0].isFirstChunk = true
			chunks[chunks.length - 1].isLastChunk = true
			evts.push(...chunks)
		}
	}
	return evts
})

async function submitCalibrationEdit(entry: Entry, newStart: number, newEnd: number, newPauses: {start:number, end:number}[]) {
	const body = {
		startTime: newStart,
		endTime: newEnd,
		pausesBlob: JSON.stringify(newPauses),
		note: entry.note
	}
	loading.value = true
	try {
		await api<Entry>('PUT', `/entries/${entry.id}`, body)
		await refresh()
		showSuccess('Session updated')
	} catch (e) {
		showError('Failed to update session')
		console.error(e)
	} finally {
		loading.value = false
	}
}

// ── Calendar events are now read-only visuals.
// ── Editing is done via double-click → openSessionEditorModal()

// ──────────────────────────────────────────────
// CONTEXT MENU – right-click on event
// ──────────────────────────────────────────────
interface CalCtxMenu {
	visible: boolean
	x: number
	y: number
	event: VueCalEvent | null
	breakStartTime: string
	breakDuration: number
}

const contextMenu = ref<CalCtxMenu>({
	visible: false, x: 0, y: 0, event: null,
	breakStartTime: '', breakDuration: 30,
})

function showContextMenu(mouseEvt: MouseEvent, calEvt: any) {
	mouseEvt.preventDefault()
	mouseEvt.stopPropagation()
	const ev: VueCalEvent = calEvt
	// Default break start = 30 min after event start
	const defaultBreakStart = new Date(ev.start.getTime() + 30 * 60 * 1000)
	const hh = String(defaultBreakStart.getHours()).padStart(2, '0')
	const mm = String(defaultBreakStart.getMinutes()).padStart(2, '0')
	contextMenu.value = {
		visible: true,
		x: mouseEvt.clientX,
		y: mouseEvt.clientY,
		event: ev,
		breakStartTime: `${hh}:${mm}`,
		breakDuration: 30,
	}
}

function closeContextMenu() {
	contextMenu.value.visible = false
}

function addBreakFromMenu() {
	const ev = contextMenu.value.event
	if (!ev) return
	closeContextMenu()

	const entry = ev.originalEntry
	const [hh, mm] = contextMenu.value.breakStartTime.split(':').map(Number)
	const dayStart = new Date(ev.start)
	dayStart.setHours(0, 0, 0, 0)
	const breakStart = dayStart.getTime() + hh * 3600000 + mm * 60000
	const breakEnd = breakStart + contextMenu.value.breakDuration * 60000

	// Validate it fits inside this chunk
	if (breakStart <= ev.start.getTime() || breakEnd >= ev.end.getTime()) {
		showError('Break must fit inside the session block')
		return
	}

	let pauses: { start: number; end: number }[] = []
	if (entry.pausesBlob) {
		try {
			const p = JSON.parse(entry.pausesBlob)
			if (Array.isArray(p)) pauses = p
		} catch {}
	}
	pauses.push({ start: breakStart, end: breakEnd })
	pauses.sort((a, b) => a.start - b.start)

	submitCalibrationEdit(entry, entry.startTime, entry.endTime!, pauses)
}

const projectSummary = computed<ProjectSummary[]>(() => {
	const startMs = weekStartMs.value
	const buckets = new Map<string, ProjectSummary>()
	for (const e of filteredEntries.value) {
		const end = e.endTime ?? now.value
		if (end < startMs) continue
		const key = e.projectUri ?? '__none__'
		if (!buckets.has(key)) {
			const list = e.projectUri
				? taskLists.value.find((l) => l.uri === e.projectUri)
				: null
			buckets.set(key, {
				uri: e.projectUri,
				name: e.projectName ?? list?.name ?? (e.projectUri ? 'Unknown list' : 'No project'),
				color: list?.color ?? null,
				weekMs: 0,
				entries: 0,
			})
		}
		const b = buckets.get(key)!
		b.weekMs += effectiveDuration(e, now.value)
		b.entries += 1
	}
	return Array.from(buckets.values()).sort((a, b) => b.weekMs - a.weekMs)
})

const projectMax = computed(() => {
	const max = projectSummary.value.reduce((acc, p) => Math.max(acc, p.weekMs), 0)
	return max > 0 ? max : 1
})

const sessionCount = computed(() => filteredEntries.value.filter((e) => e.endTime !== null).length)

function lookupColor(uri: string | null): string | null {
	if (!uri) return null
	return taskLists.value.find((l) => l.uri === uri)?.color ?? null
}

const filteredEntries = computed(() => {
	if (projectFilters.value.length === 0) return entries.value
	return entries.value.filter((e) => e.projectUri && projectFilters.value.includes(e.projectUri))
})

function toggleFilter(uri: string) {
	const idx = projectFilters.value.indexOf(uri)
	if (idx > -1) {
		projectFilters.value.splice(idx, 1)
	} else {
		projectFilters.value.push(uri)
	}
}

const activeTaskLists = computed(() => {
	const activeUris = new Set<string>()
	for (const e of entries.value) {
		if (e.projectUri) activeUris.add(e.projectUri)
	}
	return taskLists.value.filter((l) => activeUris.has(l.uri))
})

interface DailyStats {
	dateLabel: string
	totalMs: number
	projects: { uri: string | null, ms: number, color: string | null }[]
}

const last7Days = computed<DailyStats[]>(() => {
	const days: DailyStats[] = []
	const todayStart = new Date()
	todayStart.setHours(0, 0, 0, 0)

	for (let i = 6; i >= 0; i--) {
		const d = new Date(todayStart)
		d.setDate(d.getDate() - i)
		const startMs = d.getTime()
		const endMs = startMs + 86400000

		const dayLabel = d.toLocaleDateString([], { weekday: 'short' })
		let dayTotal = 0
		const pMap = new Map<string, { ms: number, color: string | null }>()

		for (const e of filteredEntries.value) {
			if (e.startTime >= startMs && e.startTime < endMs) {
				const dur = effectiveDuration(e, now.value)
				dayTotal += dur
				const key = e.projectUri || '__none__'
				if (!pMap.has(key)) pMap.set(key, { ms: 0, color: lookupColor(e.projectUri) })
				pMap.get(key)!.ms += dur
			}
		}

		// Sort segments to render consistently
		const projs = Array.from(pMap.entries())
			.map(([k, v]) => ({ uri: k === '__none__' ? null : k, ms: v.ms, color: v.color }))
			.sort((a, b) => b.ms - a.ms)

		days.push({
			dateLabel: dayLabel,
			totalMs: dayTotal,
			projects: projs,
		})
	}
	return days
})

const dailyMaxMs = computed(() => {
	const m = Math.max(...last7Days.value.map((d) => d.totalMs))
	return m > 0 ? m : 1
})

const donutGradient = computed(() => {
	let css = ''
	let currentDegree = 0
	const total = projectSummary.value.reduce((acc, p) => acc + p.weekMs, 0)
	if (total === 0) return 'conic-gradient(var(--color-border) 0 100%)'

	for (const p of projectSummary.value) {
		const percent = (p.weekMs / total) * 360
		const color = p.color || 'var(--color-text-maxcontrast)'
		css += `${color} ${currentDegree}deg ${currentDegree + percent}deg, `
		currentDegree += percent
	}
	return `conic-gradient(${css.slice(0, -2)})`
})

onMounted(() => {
	refresh()
	tickTimer = setInterval(() => {
		now.value = Date.now()
	}, 1000)
})

onBeforeUnmount(() => {
	if (tickTimer) clearInterval(tickTimer)
})
</script>

<template>
	<NcContent app-name="chronos">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationList>
					<NcAppNavigationItem
						id="view-timer"
						name="Timer"
						:active="currentView === 'timer' && projectFilter === null"
						@click="currentView = 'timer'; projectFilter = null">
						<template #icon>
							<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor" width="16" height="16">
								<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm4.2 14.2L11 13V7h1.5v5.2l4.5 2.7-.8 1.3z"/>
							</svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						id="view-analytics"
						name="Analytics"
						:active="currentView === 'analytics' && projectFilter === null"
						@click="currentView = 'analytics'; projectFilter = null">
						<template #icon>
							<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor" width="16" height="16">
								<path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>
							</svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						id="view-calendar"
						name="Session Editor"
						:active="currentView === 'calendar'"
						@click="currentView = 'calendar'">
						<template #icon>
							<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor" width="16" height="16">
								<path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10z" />
							</svg>
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationList>

				<NcAppNavigationCaption name="Projects" />
				<NcAppNavigationList>
					<NcAppNavigationItem
						v-for="p in activeTaskLists"
						:key="p.uri"
						:name="p.name"
						@click="toggleFilter(p.uri)">
						<template #icon>
							<div
								:class="[$style.filterDot, (projectFilters.length === 0 || projectFilters.includes(p.uri)) ? $style.filterDotActive : $style.filterDotInactive]"
								:style="{ '--proj-color': p.color || 'var(--color-primary-element)' }">
								<svg
									v-if="projectFilters.length === 0 || projectFilters.includes(p.uri)"
									viewBox="0 0 24 24"
									width="12"
									height="12"
									fill="white">
									<path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z" />
								</svg>
							</div>
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationList>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div :class="[$style.main, currentView === 'timer' ? $style.mainTimer : $style.mainAnalytics]">
				<template v-if="currentView === 'timer'">
					<div v-if="projectFilter" :class="$style.projectHeader">
						Filtering by project: <strong>{{ taskLists.find(l => l.uri === projectFilter)?.name }}</strong>
					</div>

					<transition name="fade" mode="out-in">
					<section
						v-if="active"
						key="active"
						:class="[$style.panel, $style.panelActive, { [$style.panelPaused]: isPaused }]">
						<div :class="[$style.pulseDot, { [$style.pulseDotPaused]: isPaused }]" />
						<div :class="$style.stateLabel">
							{{ isPaused ? 'Paused' : 'Running' }}
						</div>
						<div :class="$style.timer">{{ elapsed }}</div>
						<div :class="$style.startedAt">Started {{ formatTime(active.startTime) }}</div>
						<div v-if="active.projectName" :class="$style.activeProject">
							<span
								:class="$style.projectDot"
								:style="{ background: lookupColor(active.projectUri) || 'var(--color-primary-element)' }" />
							{{ active.projectName }}
						</div>
						<div v-if="active.note" :class="$style.activeNote">
							"{{ active.note }}"
						</div>
						<div :class="$style.actionRow">
							<NcButton
								v-if="!isPaused"
								type="secondary"
								:disabled="loading"
								aria-label="Pause"
								@click="pause">
								<template v-if="loading" #icon>
									<NcLoadingIcon :size="20" />
								</template>
								Pause
							</NcButton>
							<NcButton
								v-else
								type="primary"
								:disabled="loading"
								aria-label="Resume"
								@click="resume">
								<template v-if="loading" #icon>
									<NcLoadingIcon :size="20" />
								</template>
								Resume
							</NcButton>
							<NcButton
								type="error"
								:disabled="loading"
								aria-label="Check out"
								@click="checkOut">
								Check out
							</NcButton>
						</div>
					</section>

					<section v-else key="idle" :class="$style.panel">
						<div :class="$style.idleHeadline">Ready when you are</div>
						<div :class="$style.idleHint">
							Pick a project (optional), add a note, and press Check in.
						</div>
						<div :class="$style.selectWrap">
							<NcSelect
								v-model="selectedProject"
								:options="taskLists"
								label="name"
								:reduce="(l) => l"
								:clearable="true"
								input-label="Project (optional)"
								placeholder="No project"
								:disabled="loading" />
						</div>
						<input
							v-model="note"
							type="text"
							:class="$style.noteInput"
							placeholder="What are you working on? (optional)"
							:disabled="loading"
							@keydown.enter="checkIn" />
						<NcButton
							type="primary"
							wide
							:disabled="loading"
							aria-label="Check in"
							@click="checkIn">
							<template v-if="loading" #icon>
								<NcLoadingIcon :size="20" />
							</template>
							Check in
						</NcButton>
					</section>
				</transition>

				<section :class="$style.log">
					<div :class="$style.logHeader">
						<h2 :class="$style.sectionTitle">Recent sessions</h2>
						<span v-if="filteredEntries.length" :class="$style.logCount">
							{{ filteredEntries.length }} {{ filteredEntries.length === 1 ? 'entry' : 'entries' }}
						</span>
					</div>

					<div v-if="initialLoading" :class="$style.loadingBlock">
						<NcLoadingIcon :size="32" />
					</div>

					<ul v-else-if="filteredEntries.length" :class="$style.entryList">
						<li
							v-for="e in filteredEntries"
							:key="e.id"
							:class="[$style.entryRow, { [$style.entryRowActive]: e.endTime === null }]">
							<span
								:class="$style.entryDot"
								:style="{ background: lookupColor(e.projectUri) || 'var(--color-text-maxcontrast)' }"
								:title="e.projectName || 'No project'" />
							<div :class="$style.entryMain">
								<div :class="$style.entryNote">
									{{ e.note || 'Untitled session' }}
								</div>
								<div :class="$style.entryMeta">
									<span v-if="e.projectName" :class="$style.entryProject">
										{{ e.projectName }}
									</span>
									<span v-if="e.projectName"> · </span>
									{{ formatDate(e.startTime) }}
									· {{ formatTime(e.startTime) }}
									<template v-if="e.endTime">– {{ formatTime(e.endTime) }}</template>
									<template v-else>
										· <span :class="$style.liveTag">{{ isPaused ? 'paused' : 'live' }}</span>
									</template>
								</div>
							</div>
							<div :class="$style.entryDuration">
								{{ formatRelativeDuration(effectiveDuration(e, now)) }}
							</div>
							<button
								v-if="e.endTime !== null"
								:class="$style.entryDelete"
								:disabled="loading"
								aria-label="Delete session"
								title="Delete session"
								@click="deleteEntry(e)">
								<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
									<path
										d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z"
										fill="currentColor" />
								</svg>
							</button>
						</li>
					</ul>

					<NcEmptyContent
						v-else
						:class="$style.empty"
						name="No sessions yet"
						description="Your first check-in will appear here." />
				</section>
				</template>

				<template v-else-if="currentView === 'analytics'">
					<div :class="$style.logHeader">
						<h2 :class="$style.sectionTitle">Analytics Overview</h2>
					</div>
					<div :class="$style.analyticsGrid">
						<div :class="$style.statCard">
							<div :class="$style.statLabel">Today</div>
							<div :class="$style.statValueLarge">
								{{ formatRelativeDuration(totalToday) }}
							</div>
						</div>
						<div :class="$style.statCard">
							<div :class="$style.statLabel">This week</div>
							<div :class="$style.statValue">
								{{ formatRelativeDuration(weeklyTotalMs) }}
							</div>
							<div :class="$style.statMeta">{{ sessionCount }} sessions</div>
						</div>
					</div>

					<div :class="$style.chartsGrid">
						<div :class="$style.dailyChartCard">
							<div :class="$style.statLabel">Last 7 Days</div>
							<div :class="$style.dailyChart">
								<div v-for="(day, i) in last7Days" :key="i" :class="$style.dayCol">
									<div :class="$style.dayBars">
										<div v-for="(p, j) in day.projects" :key="j" :class="$style.dayBarSeg" 
											:style="{ height: ((p.ms / dailyMaxMs) * 100) + '%', background: p.color || 'var(--color-text-maxcontrast)' }"
											:title="formatRelativeDuration(p.ms)">
										</div>
									</div>
									<div :class="$style.dayLabel">{{ day.dateLabel }}</div>
								</div>
							</div>
						</div>

						<div :class="$style.donutCard">
							<div :class="$style.statLabel">By project (7d) & Goals</div>
							<div :class="$style.donutContainer">
								<div :class="$style.donutChart" :style="{ background: donutGradient }">
									<div :class="$style.donutHole"></div>
								</div>
							</div>
							
							<ul v-if="projectSummary.length" :class="[$style.projectList, $style.projectListScroll]">
								<li
									v-for="p in projectSummary"
									:key="p.uri || '__none__'"
									:class="$style.projectItemGoal">
									<div :class="$style.projectMainRow">
										<span
											:class="$style.projectDot"
											:style="{ background: p.color || 'var(--color-text-maxcontrast)' }" />
										<div :class="$style.projectName">{{ p.name }}</div>
										<div :class="$style.projectDur">{{ formatRelativeDuration(p.weekMs) }}</div>
									</div>
									
									<div :class="$style.projectGoalControls">
										<input type="number" 
											:class="$style.goalInput" 
											v-model.number="projectGoals[p.uri || '__none__']" 
											placeholder="Goal (h)" min="0" step="1"/>
										<div :class="$style.goalProgress" v-if="projectGoals[p.uri || '__none__'] > 0">
											{{ Math.min(100, Math.round((p.weekMs / (projectGoals[p.uri || '__none__'] * 3600000)) * 100)) }}%
										</div>
									</div>
									
									<div :class="$style.projectBar" v-if="projectGoals[p.uri || '__none__'] > 0">
										<div
											:class="$style.projectBarFill"
											:style="{
												width: Math.min(100, ((p.weekMs / (projectGoals[p.uri || '__none__'] * 3600000)) * 100)) + '%',
												background: p.color || 'var(--color-success)',
											}" />
									</div>
								</li>
							</ul>
							<div v-else :class="$style.projectsEmpty">
								No sessions yet this week.
							</div>
						</div>
					</div>
				</template>

				<template v-else-if="currentView === 'calendar'">
					<div :class="$style.logHeader">
						<h2 :class="$style.sectionTitle">Session Editor</h2>
					</div>
					<div :class="[$style.panel, $style.calWrapper]">
						<p :class="$style.calSubtitle">Drag to adjust hours or split sessions to add un-tracked breaks magically.</p>
						<vue-cal
							:class="['vuecal--blue-theme', $style.nextcloudCalTheme]"
							hide-view-selector
							:time-from="6 * 60"
							:time-to="24 * 60"
							:time-step="30"
							:scroll-to="'08:00'"
							:events="calendarEvents"
							:editable-events="{ title: false, drag: false, resize: false, delete: false, create: false }"
							:style="{ height: calHeight + 'px', width: '100%', borderRadius: '8px' }"
						>
							<template #event="{ event }">
								<div
									:class="$style.customEventContent"
									:style="{ borderLeft: `5px solid ${event.projectColor || 'var(--color-primary)'}` }"
									@contextmenu.prevent.stop="showContextMenu($event, event)"
									@dblclick.prevent.stop="openSessionEditorModal(event)"
								>
									<strong>{{ event.title }}</strong><br>
									<small v-if="event.content">{{ event.content }}</small>
								</div>
							</template>
						</vue-cal>

						<!-- RIGHT-CLICK CONTEXT MENU -->
						<Teleport to="body">
							<div
								v-if="contextMenu.visible"
								:class="$style.ctxOverlay"
								@click.self="closeContextMenu"
							>
								<div
									:class="$style.ctxMenu"
									:style="{ top: contextMenu.y + 'px', left: contextMenu.x + 'px' }"
								>
									<div :class="$style.ctxTitle">Add Break</div>
									<div :class="$style.ctxRow">
										<label :class="$style.ctxLabel">Start time</label>
										<input
											v-model="contextMenu.breakStartTime"
											type="time"
											:class="$style.ctxInput"
										>
									</div>
									<div :class="$style.ctxRow">
										<label :class="$style.ctxLabel">Duration</label>
										<select v-model="contextMenu.breakDuration" :class="$style.ctxInput">
											<option :value="15">15 min</option>
											<option :value="30">30 min</option>
											<option :value="45">45 min</option>
											<option :value="60">60 min</option>
										</select>
									</div>
									<div :class="$style.ctxActions">
										<button :class="$style.ctxCancel" @click="closeContextMenu">Cancel</button>
										<button :class="$style.ctxConfirm" @click="addBreakFromMenu">Add Break</button>
									</div>
								</div>
							</div>
						</Teleport>

						<!-- SESSION EDITOR MODAL -->
						<NcModal v-if="showEditorModal" @close="showEditorModal = false" :title="'Editar Sesión'">
							<div :class="$style.editorModalContent">
								<h3>Proyecto: {{ editorData.entry?.projectName || 'Sin asignar' }}</h3>
								<div :class="$style.formGroup">
									<label>Hora de Inicio</label>
									<input type="time" v-model="editorData.startTime" />
								</div>
								<div :class="$style.formGroup">
									<label>Hora de Fin</label>
									<input type="time" v-model="editorData.endTime" :disabled="!editorData.entry?.endTime" />
									<small v-if="!editorData.entry?.endTime">La sesión está en curso.</small>
								</div>
								
								<div :class="$style.pausesSection">
									<div :class="$style.pausesHeader">
										<h4>Pausas Registradas</h4>
										<NcButton @click="addEditorPause" type="tertiary" size="small">
											<template #icon>
												<svg viewBox="0 0 24 24" width="18" height="18"><path d="M19 13H13V19H11V13H5V11H11V5H13V11H19V13Z" fill="currentColor"/></svg>
											</template>
											Añadir Descanso
										</NcButton>
									</div>
									
									<div v-for="p in editorData.pauses" :key="p.id" :class="$style.pauseRow">
										<input type="time" v-model="p.start" />
										<span>—</span>
										<input type="time" v-model="p.end" />
										<NcButton @click="removeEditorPause(p.id)" type="tertiary" size="small" aria-label="Borrar">
											<template #icon>
												<svg viewBox="0 0 24 24" width="18" height="18"><path d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z" fill="currentColor"/></svg>
											</template>
										</NcButton>
									</div>
									<div v-if="editorData.pauses.length === 0" :class="$style.noPauses">Sin pausas añadidas.</div>
								</div>
								
								<div :class="$style.modalActions">
									<NcButton @click="showEditorModal = false" type="secondary">Cancelar</NcButton>
									<NcButton @click="saveEditorModal" type="primary">Guardar Cambios</NcButton>
								</div>
							</div>
						</NcModal>
					</div>
				</template>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style module>
:global(body.app-chronos .app-navigation) {
	background: transparent !important;
}

.projectNavDot {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	display: inline-block;
}

.analyticsGrid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.statCard,
.projectsCard {
	background: color-mix(in srgb, var(--color-main-background) 30%, transparent);
	border: 1px solid color-mix(in srgb, var(--color-main-text) 10%, transparent);
	backdrop-filter: blur(18px) saturate(1.4);
	-webkit-backdrop-filter: blur(18px) saturate(1.4);
	border-radius: var(--border-radius-large);
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	box-shadow: inset 0 1px 0 0 color-mix(in srgb, var(--color-main-text) 6%, transparent);
}

.statLabel {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.1em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.statValue {
	font-size: 28px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.statValueLarge {
	font-size: 36px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	letter-spacing: -0.02em;
}

.statMeta {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.projectHeader {
	font-size: 15px;
	padding: 12px 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	color: var(--color-main-text);
	display: flex;
	align-items: center;
	border: 1px solid var(--color-border);
}

.chartsGrid {
	display: grid;
	grid-template-columns: 1fr 340px;
	gap: 16px;
	align-items: start;
}

@media (max-width: 800px) {
	.chartsGrid {
		grid-template-columns: 1fr;
	}
}

.dailyChartCard,
.donutCard {
	background: color-mix(in srgb, var(--color-main-background) 30%, transparent);
	border: 1px solid color-mix(in srgb, var(--color-main-text) 10%, transparent);
	backdrop-filter: blur(18px) saturate(1.4);
	-webkit-backdrop-filter: blur(18px) saturate(1.4);
	border-radius: var(--border-radius-large);
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 16px;
	box-shadow: inset 0 1px 0 0 color-mix(in srgb, var(--color-main-text) 6%, transparent);
}

.dailyChart {
	display: flex;
	align-items: flex-end;
	height: 220px;
	gap: 8px;
	padding-top: 10px;
}

.dayCol {
	flex: 1;
	display: flex;
	flex-direction: column;
	justify-content: flex-end;
	align-items: center;
	height: 100%;
	gap: 8px;
}

.dayBars {
	width: 100%;
	max-width: 40px;
	flex: 1;
	display: flex;
	flex-direction: column; /* Normal column, we'll order segments correctly */
	justify-content: flex-end;
	background: color-mix(in srgb, var(--color-main-text) 4%, transparent);
	border-radius: 4px;
	overflow: hidden;
}

.dayBarSeg {
	width: 100%;
	transition: height 0.4s ease;
	min-height: 2px;
}

.dayLabel {
	font-size: 11px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.donutContainer {
	display: flex;
	justify-content: center;
	padding: 10px 0;
}

.donutChart {
	width: 140px;
	height: 140px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: background 0.5s ease;
}

.donutHole {
	width: 90px;
	height: 90px;
	background: var(--color-main-background);
	border-radius: 50%;
}

.projectListScroll {
	max-height: 380px;
	overflow-y: auto;
	padding-right: 4px;
}

.projectItemGoal {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-bottom: 12px;
	border-bottom: 1px solid color-mix(in srgb, var(--color-border) 40%, transparent);
}
.projectItemGoal:last-child {
	border-bottom: none;
	padding-bottom: 0;
}

.projectMainRow {
	display: grid;
	grid-template-columns: 10px 1fr auto;
	align-items: center;
	gap: 8px;
}

.projectGoalControls {
	display: flex;
	align-items: center;
	gap: 12px;
	padding-left: 18px;
}

.goalInput {
	width: 80px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 4px 6px;
	font-size: 12px;
	color: var(--color-main-text);
}

.goalProgress {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.projectList {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.projectItem {
	display: grid;
	grid-template-columns: 10px 1fr auto;
	align-items: center;
	gap: 8px;
}

.projectDot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
	display: inline-block;
}

.projectMain {
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.projectName {
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.projectBar {
	height: 3px;
	width: 100%;
	background: color-mix(in srgb, var(--color-main-text) 8%, transparent);
	border-radius: 2px;
	overflow: hidden;
}

.projectBarFill {
	height: 100%;
	border-radius: 2px;
	transition: width 0.4s ease;
	min-width: 2px;
}

.projectDur {
	font-size: 12px;
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.projectsEmpty {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.filterDot {
	width: 16px;
	height: 16px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	border: 2px solid var(--proj-color);
	transition: all 0.2s ease;
	flex-shrink: 0;
}

.filterDotActive {
	background-color: var(--proj-color);
}

.filterDotInactive {
	background-color: transparent;
}

.main {
	display: flex;
	flex-direction: column;
	gap: 24px;
	padding: 32px 28px 48px;
	margin: 0 auto;
	width: 100%;
	box-sizing: border-box;
}

.mainTimer {
	max-width: 820px;
}

.mainAnalytics {
	max-width: 1200px;
}

.mainCalendar {
	max-width: 1200px;
}

.cal-wrapper {
	width: 100%;
	padding: 24px;
	box-sizing: border-box;
}

.cal-subtitle {
	color: var(--color-text-maxcontrast);
	margin-top: -10px;
	margin-bottom: 20px;
	font-size: 13px;
}

/* Vue-cal native overrides for Nextcloud Dark/Light compat */
.vuecal {
	border-radius: var(--border-radius-large);
	border-color: var(--color-border);
	background: var(--color-main-background);
}

.vuecal__title-bar {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.vuecal__cell {
	background: transparent;
	color: var(--color-main-text);
}

.custom-event-content {
	background: var(--color-background-dark);
	border-radius: 4px;
	height: 100%;
	padding: 4px;
	color: var(--color-main-text);
	box-shadow: 0 1px 3px rgba(0,0,0,0.2);
	overflow: hidden;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	justify-content: flex-start;
}

.vuecal__event {
	background-color: transparent !important;
}

.panel {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 14px;
	padding: 36px 32px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
	transition: background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
	box-shadow: 0 8px 32px -20px rgba(0, 0, 0, 0.4);
}

.panelActive {
	border-color: var(--color-primary-element);
	box-shadow:
		0 8px 32px -20px rgba(0, 0, 0, 0.4),
		0 0 0 1px color-mix(in srgb, var(--color-primary-element) 30%, transparent),
		0 0 40px -10px color-mix(in srgb, var(--color-primary-element) 40%, transparent);
}

.panelPaused {
	border-color: var(--color-warning);
	box-shadow:
		0 8px 32px -20px rgba(0, 0, 0, 0.4),
		0 0 40px -16px color-mix(in srgb, var(--color-warning) 50%, transparent);
}

.stateLabel {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.12em;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.pulseDot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-success);
	box-shadow: 0 0 0 0 var(--color-success);
	animation: pulse 1.8s infinite;
}

.pulseDotPaused {
	background: var(--color-warning);
	box-shadow: none;
	animation: none;
}

@keyframes pulse {
	0% { box-shadow: 0 0 0 0 var(--color-success); }
	70% { box-shadow: 0 0 0 12px transparent; }
	100% { box-shadow: 0 0 0 0 transparent; }
}

.timer {
	font-size: 64px;
	font-weight: 300;
	font-variant-numeric: tabular-nums;
	letter-spacing: -0.02em;
	line-height: 1;
}

.startedAt {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.activeProject {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
	font-weight: 500;
}

.activeNote {
	font-style: italic;
	color: var(--color-text-lighter);
	text-align: center;
	max-width: 420px;
	padding: 8px 16px;
	background: color-mix(in srgb, var(--color-main-text) 6%, transparent);
	border-radius: var(--border-radius);
}

.actionRow {
	display: flex;
	gap: 12px;
	margin-top: 4px;
	flex-wrap: wrap;
	justify-content: center;
}

.idleHeadline {
	font-size: 22px;
	font-weight: 600;
	letter-spacing: -0.01em;
}

.idleHint {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
	text-align: center;
}

.panel :global(.button-vue) {
	max-width: 420px;
}

.selectWrap {
	width: 100%;
	max-width: 420px;
}

.noteInput {
	width: 100%;
	max-width: 420px;
	padding: 12px 16px;
	font-size: 15px;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 999px;
	outline: none;
	transition: border-color 0.2s ease;
	box-sizing: border-box;
}

.noteInput::placeholder {
	color: var(--color-text-maxcontrast);
}

.noteInput:focus {
	border-color: var(--color-primary-element);
}

.noteInput:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.sectionTitle {
	font-size: 16px;
	font-weight: 600;
	margin: 0;
	letter-spacing: -0.01em;
}

.log {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.logHeader {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	padding: 0 4px;
}

.logCount {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.loadingBlock {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.entryList {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 1px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.entryRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 14px 20px;
	background: var(--color-main-background);
	transition: background 0.15s ease;
}

.entryRow:hover {
	background: var(--color-background-hover);
}

.entryRow:hover .entryDelete {
	opacity: 1;
}

.entryRowActive {
	background: color-mix(in srgb, var(--color-primary-element) 12%, var(--color-main-background));
}

.entryDot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
}

.entryMain {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.entryNote {
	font-size: 14px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.entryMeta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.entryProject {
	font-weight: 600;
	color: var(--color-main-text);
}

.entryDuration {
	font-variant-numeric: tabular-nums;
	font-weight: 600;
	font-size: 15px;
}

.liveTag {
	color: var(--color-success);
	font-weight: 600;
	text-transform: uppercase;
	font-size: 10px;
	letter-spacing: 0.08em;
}

.entryDelete {
	display: flex;
	align-items: center;
	justify-content: center;
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast);
	padding: 6px;
	border-radius: 50%;
	cursor: pointer;
	opacity: 0;
	transition: opacity 0.15s ease, background 0.15s ease, color 0.15s ease;
}

.entryDelete:hover,
.entryDelete:focus-visible {
	opacity: 1;
	background: color-mix(in srgb, var(--color-error) 20%, transparent);
	color: var(--color-error);
	outline: none;
}

.entryDelete:disabled {
	opacity: 0.3;
	cursor: not-allowed;
}

.empty {
	padding: 24px 0;
}

:global(.fade-enter-active),
:global(.fade-leave-active) {
	transition: opacity 0.25s ease, transform 0.25s ease;
}

:global(.fade-enter-from),
:global(.fade-leave-to) {
	opacity: 0;
	transform: translateY(6px);
}

/* Vue-cal native overrides for Nextcloud Dark/Light compat */
.calWrapper {
	width: 100%;
	padding: 24px;
	box-sizing: border-box;
}

.calSubtitle {
	color: var(--color-text-maxcontrast);
	margin-top: -10px;
	margin-bottom: 20px;
	font-size: 13px;
}

.nextcloudCalTheme {
	border-radius: var(--border-radius-large);
	border-color: var(--color-border);
	background: var(--color-main-background);
}

:global(.vuecal__title-bar) {
	background: var(--color-background-hover) !important;
	color: var(--color-main-text) !important;
}

:global(.vuecal__cell) {
	background: transparent !important;
	color: var(--color-main-text) !important;
}

:global(.vuecal__event) {
	background-color: transparent !important;
	box-shadow: none !important;
}

.customEventContent {
	background: var(--color-background-dark);
	border-radius: 4px;
	height: 100%;
	padding: 6px 8px;
	color: var(--color-main-text);
	box-shadow: 0 1px 3px rgba(0,0,0,0.2);
	overflow: hidden;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	justify-content: flex-start;
	cursor: default;
	user-select: none;
}

:global(.calPauseEvent) {
	background: repeating-linear-gradient(45deg, 
		var(--color-background-hover) 0px, 
		var(--color-background-hover) 10px, 
		transparent 10px, 
		transparent 20px
	) !important;
	border: 1px dashed var(--color-border) !important;
	color: var(--color-text-maxcontrast) !important;
	opacity: 0.6;
	cursor: default !important;
}

/* ── Double-click hint ── */
.customEventContent {
	cursor: pointer;
}

.customEventContent::after {
	content: '✏️';
	position: absolute;
	bottom: 4px;
	right: 6px;
	font-size: 10px;
	opacity: 0;
	transition: opacity 0.2s;
}

:global(.vuecal__event:hover) .customEventContent::after {
	opacity: 0.7;
}

/* ── SESSION EDITOR MODAL ── */
.editorModalContent {
	padding: 24px;
	min-width: 380px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.editorModalContent h3 {
	margin: 0 0 4px;
	font-size: 1.1rem;
	color: var(--color-main-text);
}

.formGroup {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.formGroup label {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.formGroup input[type="time"],
.pauseRow input[type="time"] {
	padding: 8px 12px;
	border-radius: 6px;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
	font-size: 1rem;
	width: 100%;
}

.formGroup input[type="time"]:disabled {
	opacity: 0.5;
}

.pausesSection {
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.pausesHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 10px;
}

.pausesHeader h4 {
	margin: 0;
	font-size: 0.95rem;
	color: var(--color-main-text);
}

.pauseRow {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.pauseRow span {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.noPauses {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	text-align: center;
	padding: 12px 0;
}

.modalActions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

/* CONTEXT MENU */
.ctxOverlay {
	position: fixed;
	inset: 0;
	z-index: 9000;
}

.ctxMenu {
	position: fixed;
	z-index: 9001;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	width: 230px;
	box-shadow: 0 8px 32px rgba(0,0,0,0.4);
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ctxTitle {
	font-weight: 700;
	font-size: 14px;
	color: var(--color-main-text);
	padding-bottom: 4px;
	border-bottom: 1px solid var(--color-border);
}

.ctxRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.ctxLabel {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.ctxInput {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	padding: 4px 8px;
	flex: 1;
	min-width: 0;
	font-size: 13px;
}

.ctxActions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.ctxCancel {
	padding: 6px 12px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 13px;
}

.ctxConfirm {
	padding: 6px 12px;
	border-radius: var(--border-radius);
	border: none;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	cursor: pointer;
	font-weight: 600;
	font-size: 13px;
}

/* Hide vue-cal's native bottom resize handle completely */
:global(.vuecal__event-resize-handle) {
	display: none !important;
}
</style>
