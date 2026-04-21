<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'

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
const active = ref<Entry | null>(null)
const entries = ref<Entry[]>([])
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

async function api<T>(method: 'GET' | 'POST', path: string, body?: unknown): Promise<T> {
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
		const [a, list, lists] = await Promise.all([
			api<Entry | null>('GET', '/entries/active'),
			api<Entry[]>('GET', '/entries'),
			api<TaskList[]>('GET', '/tasklists'),
		])
		active.value = a
		entries.value = list
		taskLists.value = lists
	} catch (e) {
		console.error('[chronos widget]', e)
	} finally {
		initialLoading.value = false
	}
}

async function runAction(endpoint: string, body: unknown) {
	loading.value = true
	try {
		await api<Entry>('POST', endpoint, body)
		await refresh()
	} catch (e) {
		console.error('[chronos widget]', e)
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
	runAction('/entries/check-in', body).then(() => {
		note.value = ''
	})
}
const pause = () => runAction('/entries/pause', {})
const resume = () => runAction('/entries/resume', {})
const checkOut = () => runAction('/entries/check-out', {})

function effectiveDuration(e: Entry, nowMs: number): number {
	const end = e.endTime ?? nowMs
	let d = end - e.startTime - e.pausedDuration
	if (e.pausedAt !== null && e.endTime === null) {
		d -= nowMs - e.pausedAt
	}
	return Math.max(0, d)
}

function formatLive(ms: number): string {
	const s = Math.max(0, Math.floor(ms / 1000))
	const h = Math.floor(s / 3600)
	const m = Math.floor((s % 3600) / 60)
	const sec = s % 60
	const pad = (n: number) => String(n).padStart(2, '0')
	return `${pad(h)}:${pad(m)}:${pad(sec)}`
}

function formatRel(ms: number): string {
	const s = Math.max(0, Math.floor(ms / 1000))
	if (s < 60) return `${s}s`
	const h = Math.floor(s / 3600)
	const m = Math.floor((s % 3600) / 60)
	if (h === 0) return `${m}m`
	if (m === 0) return `${h}h`
	return `${h}h ${m}m`
}

const isPaused = computed(() => !!(active.value && active.value.pausedAt !== null))

const elapsed = computed(() =>
	active.value ? formatLive(effectiveDuration(active.value, now.value)) : '00:00:00',
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
	return formatRel(total)
})

const recent = computed(() =>
	entries.value.filter((e) => e.endTime !== null).slice(0, 3),
)

function lookupColor(uri: string | null): string | null {
	if (!uri) return null
	return taskLists.value.find((l) => l.uri === uri)?.color ?? null
}

function openApp() {
	window.location.href = generateUrl(appBase + '/')
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
	<div :class="$style.root">
		<div v-if="initialLoading" :class="$style.loading">Loading…</div>

		<template v-else-if="active">
			<div :class="$style.state">
				<span :class="[$style.dot, { [$style.dotPaused]: isPaused }]" />
				<span :class="$style.stateLabel">{{ isPaused ? 'Paused' : 'Running' }}</span>
			</div>
			<div :class="$style.timer">{{ elapsed }}</div>
			<div v-if="active.projectName" :class="$style.projectLine">
				<span
					:class="$style.projectDot"
					:style="{ background: lookupColor(active.projectUri) || 'var(--color-primary-element)' }" />
				<span>{{ active.projectName }}</span>
			</div>
			<div v-if="active.note" :class="$style.noteLine">"{{ active.note }}"</div>
			<div :class="$style.btnRow">
				<button
					v-if="!isPaused"
					:class="[$style.btn, $style.btnSecondary]"
					:disabled="loading"
					@click="pause">
					Pause
				</button>
				<button
					v-else
					:class="[$style.btn, $style.btnPrimary]"
					:disabled="loading"
					@click="resume">
					Resume
				</button>
				<button
					:class="[$style.btn, $style.btnError]"
					:disabled="loading"
					@click="checkOut">
					Check out
				</button>
			</div>
		</template>

		<template v-else>
			<div :class="$style.todayLine">
				<span :class="$style.todayLabel">Today</span>
				<span :class="$style.todayValue">{{ totalToday }}</span>
			</div>
			<div :class="$style.selectWrap">
				<NcSelect
					v-model="selectedProject"
					:options="taskLists"
					label="name"
					:reduce="(l) => l"
					:clearable="true"
					placeholder="No project"
					:disabled="loading" />
			</div>
			<input
				v-model="note"
				type="text"
				:class="$style.input"
				placeholder="What are you working on?"
				:disabled="loading"
				@keydown.enter="checkIn" />
			<button
				:class="[$style.btn, $style.btnPrimary, $style.btnWide]"
				:disabled="loading"
				@click="checkIn">
				Check in
			</button>
		</template>

		<div v-if="recent.length" :class="$style.recent">
			<div :class="$style.recentTitle">Recent</div>
			<ul :class="$style.recentList">
				<li v-for="e in recent" :key="e.id" :class="$style.recentItem" @click="openApp">
					<span
						:class="$style.recentDot"
						:style="{ background: lookupColor(e.projectUri) || 'var(--color-text-maxcontrast)' }" />
					<span :class="$style.recentNote">{{ e.note || 'Untitled' }}</span>
					<span :class="$style.recentDur">{{ formatRel(effectiveDuration(e, now)) }}</span>
				</li>
			</ul>
		</div>
	</div>
</template>

<style module>
.root {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
	color: var(--color-main-text);
}

.loading {
	text-align: center;
	padding: 16px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.state {
	display: flex;
	align-items: center;
	gap: 8px;
	justify-content: center;
}

.dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--color-success);
	box-shadow: 0 0 0 0 var(--color-success);
	animation: pulse 1.8s infinite;
}

.dotPaused {
	background: var(--color-warning);
	animation: none;
	box-shadow: none;
}

@keyframes pulse {
	0% { box-shadow: 0 0 0 0 var(--color-success); }
	70% { box-shadow: 0 0 0 8px transparent; }
	100% { box-shadow: 0 0 0 0 transparent; }
}

.stateLabel {
	font-size: 10px;
	text-transform: uppercase;
	letter-spacing: 0.1em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.timer {
	font-size: 36px;
	font-weight: 300;
	font-variant-numeric: tabular-nums;
	text-align: center;
	line-height: 1;
}

.projectLine {
	display: flex;
	align-items: center;
	gap: 8px;
	justify-content: center;
	font-size: 13px;
	font-weight: 500;
}

.projectDot {
	width: 9px;
	height: 9px;
	border-radius: 50%;
	flex-shrink: 0;
}

.noteLine {
	font-style: italic;
	color: var(--color-text-lighter);
	text-align: center;
	font-size: 12px;
	padding: 6px 10px;
	background: color-mix(in srgb, var(--color-main-text) 6%, transparent);
	border-radius: var(--border-radius);
}

.todayLine {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	padding: 4px 2px 0;
}

.todayLabel {
	font-size: 11px;
	text-transform: uppercase;
	letter-spacing: 0.1em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.todayValue {
	font-size: 16px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.selectWrap {
	width: 100%;
}

.input {
	width: 100%;
	padding: 8px 12px;
	font-size: 13px;
	color: var(--color-main-text);
	background: color-mix(in srgb, var(--color-main-background) 60%, transparent);
	border: 1px solid color-mix(in srgb, var(--color-main-text) 14%, transparent);
	border-radius: 999px;
	outline: none;
	box-sizing: border-box;
	transition: border-color 0.2s ease;
}

.input::placeholder {
	color: var(--color-text-maxcontrast);
}

.input:focus {
	border-color: var(--color-primary-element);
}

.input:disabled {
	opacity: 0.5;
}

.btnRow {
	display: flex;
	gap: 8px;
	justify-content: center;
	flex-wrap: wrap;
}

.btn {
	padding: 7px 14px;
	font-size: 13px;
	font-weight: 600;
	border: none;
	border-radius: 999px;
	cursor: pointer;
	transition: background 0.15s ease, opacity 0.15s ease;
}

.btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.btnPrimary {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.btnPrimary:hover:not(:disabled) {
	background: var(--color-primary-element-hover);
}

.btnSecondary {
	background: color-mix(in srgb, var(--color-main-text) 12%, transparent);
	color: var(--color-main-text);
}

.btnSecondary:hover:not(:disabled) {
	background: color-mix(in srgb, var(--color-main-text) 20%, transparent);
}

.btnError {
	background: var(--color-error);
	color: #ffffff;
}

.btnError:hover:not(:disabled) {
	filter: brightness(1.1);
}

.btnWide {
	width: 100%;
}

.recent {
	margin-top: 4px;
	padding-top: 10px;
	border-top: 1px solid color-mix(in srgb, var(--color-main-text) 8%, transparent);
}

.recentTitle {
	font-size: 10px;
	text-transform: uppercase;
	letter-spacing: 0.1em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	margin-bottom: 6px;
}

.recentList {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.recentItem {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 6px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background 0.15s ease;
}

.recentItem:hover {
	background: color-mix(in srgb, var(--color-main-text) 8%, transparent);
}

.recentDot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	flex-shrink: 0;
}

.recentNote {
	font-size: 12px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	flex: 1;
	min-width: 0;
}

.recentDur {
	font-size: 11px;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	flex-shrink: 0;
}
</style>
