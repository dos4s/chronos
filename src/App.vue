<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { getDialogBuilder, showError, showSuccess } from '@nextcloud/dialogs'

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
	method: 'GET' | 'POST' | 'DELETE',
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
	for (const e of entries.value) {
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
	for (const e of entries.value) {
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

const projectSummary = computed<ProjectSummary[]>(() => {
	const startMs = weekStartMs.value
	const buckets = new Map<string, ProjectSummary>()
	for (const e of entries.value) {
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

const sessionCount = computed(() => entries.value.filter((e) => e.endTime !== null).length)

function lookupColor(uri: string | null): string | null {
	if (!uri) return null
	return taskLists.value.find((l) => l.uri === uri)?.color ?? null
}

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
				<div :class="$style.sidebar">
					<div :class="$style.brand">
						<svg :class="$style.brandIcon" viewBox="0 0 24 24" aria-hidden="true">
							<path
								d="M6,2V8H6V8L10,12L6,16V16H6V22H18V16H18V16L14,12L18,8V8H18V2H6M16,16.5V20H8V16.5L12,12.5L16,16.5M12,11.5L8,7.5V4H16V7.5L12,11.5Z"
								fill="currentColor" />
						</svg>
						<div :class="$style.brandName">Time Tracker</div>
					</div>

					<div :class="$style.statBlock">
						<div :class="$style.statLabel">Today</div>
						<div :class="$style.statValueLarge">
							{{ formatRelativeDuration(totalToday) }}
						</div>
					</div>

					<div :class="$style.statBlock">
						<div :class="$style.statLabel">This week</div>
						<div :class="$style.statValue">
							{{ formatRelativeDuration(weeklyTotalMs) }}
						</div>
						<div :class="$style.statMeta">{{ sessionCount }} sessions</div>
					</div>

					<div :class="$style.projectsBlock">
						<div :class="$style.statLabel">By project (7d)</div>
						<ul v-if="projectSummary.length" :class="$style.projectList">
							<li
								v-for="p in projectSummary"
								:key="p.uri || '__none__'"
								:class="$style.projectItem">
								<span
									:class="$style.projectDot"
									:style="{ background: p.color || 'var(--color-text-maxcontrast)' }" />
								<div :class="$style.projectMain">
									<div :class="$style.projectName">{{ p.name }}</div>
									<div :class="$style.projectBar">
										<div
											:class="$style.projectBarFill"
											:style="{
												width: ((p.weekMs / projectMax) * 100) + '%',
												background: p.color || 'var(--color-primary-element)',
											}" />
									</div>
								</div>
								<div :class="$style.projectDur">
									{{ formatRelativeDuration(p.weekMs) }}
								</div>
							</li>
						</ul>
						<div v-else :class="$style.projectsEmpty">
							No sessions yet this week.
						</div>
					</div>
				</div>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div :class="$style.main">
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
						<span v-if="entries.length" :class="$style.logCount">
							{{ entries.length }} {{ entries.length === 1 ? 'entry' : 'entries' }}
						</span>
					</div>

					<div v-if="initialLoading" :class="$style.loadingBlock">
						<NcLoadingIcon :size="32" />
					</div>

					<ul v-else-if="entries.length" :class="$style.entryList">
						<li
							v-for="e in entries"
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
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style module>
:global(body.app-chronos .app-navigation) {
	background: transparent !important;
}

.sidebar {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px 16px 24px;
	color: var(--color-main-text);
}

.brand {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 4px 0 8px;
}

.brandIcon {
	width: 28px;
	height: 28px;
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.brandName {
	font-size: 18px;
	font-weight: 700;
	letter-spacing: -0.01em;
}

.statBlock,
.projectsBlock {
	background: color-mix(in srgb, var(--color-main-background) 30%, transparent);
	border: 1px solid color-mix(in srgb, var(--color-main-text) 10%, transparent);
	backdrop-filter: blur(18px) saturate(1.4);
	-webkit-backdrop-filter: blur(18px) saturate(1.4);
	border-radius: var(--border-radius-large);
	padding: 14px 16px;
	display: flex;
	flex-direction: column;
	gap: 6px;
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
	font-size: 20px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.statValueLarge {
	font-size: 28px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	letter-spacing: -0.02em;
}

.statMeta {
	font-size: 12px;
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

.main {
	display: flex;
	flex-direction: column;
	gap: 24px;
	padding: 32px 28px 48px;
	max-width: 820px;
	margin: 0 auto;
	width: 100%;
	box-sizing: border-box;
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
</style>
