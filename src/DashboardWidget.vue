<script setup lang="ts">
import type { CheckInPayload, Project, Session } from './types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { computed, ref } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { fetchSummary } from './api/analytics.ts'
import { messageFor } from './api/client.ts'
import { fetchProjects } from './api/projects.ts'
import * as sessionsApi from './api/sessions.ts'
import { addDays, nowSeconds, startOfDay } from './api/time.ts'
import { useNow } from './composables/useNow.ts'
import { formatClock, formatDuration } from './utils/format.ts'
import { workedSeconds } from './utils/sessions.ts'

/**
 * The widget uses the same API client, types and formatters as the app (D8,
 * D16), so the two can no longer drift apart. Today's total comes from the
 * server's aggregation rather than from a page of recent sessions.
 */

const { now } = useNow()

const active = ref<Session | null>(null)
const recent = ref<Session[]>([])
const projects = ref<Project[]>([])
const todaySeconds = ref(0)
const note = ref('')
const selectedProject = ref<Project | null>(null)
const loading = ref(false)
const initialLoading = ref(true)

async function refresh() {
	const today = startOfDay(nowSeconds())
	try {
		const [activeSession, sessions, allProjects, summary] = await Promise.all([
			sessionsApi.fetchActive(),
			sessionsApi.fetchSessions(10),
			fetchProjects(),
			fetchSummary(today, addDays(today, 1)),
		])
		active.value = activeSession
		recent.value = sessions.filter((session) => session.endAt !== null).slice(0, 3)
		projects.value = allProjects
		todaySeconds.value = summary.totalSeconds
	} catch (error) {
		showError(messageFor(error))
	} finally {
		initialLoading.value = false
	}
}

async function run(action: () => Promise<unknown>) {
	loading.value = true
	try {
		await action()
		await refresh()
	} catch (error) {
		showError(messageFor(error))
	} finally {
		loading.value = false
	}
}

async function checkIn() {
	const payload: CheckInPayload = {}
	if (note.value.trim() !== '') {
		payload.note = note.value.trim()
	}
	if (selectedProject.value !== null) {
		payload.projectUri = selectedProject.value.uri
		payload.projectName = selectedProject.value.name
	}

	await run(() => sessionsApi.checkIn(payload))
	note.value = ''
}

const pause = () => run(sessionsApi.pauseSession)
const resume = () => run(sessionsApi.resumeSession)
const checkOut = () => run(sessionsApi.checkOut)

const isPaused = computed(() => active.value !== null && active.value.pausedSinceAt !== null)

const elapsed = computed(() => active.value ? formatClock(workedSeconds(active.value, now.value)) : '00:00:00')

const todayTotal = computed(() => formatDuration(todaySeconds.value))

/** Archived projects are kept out of the picker, as in the app itself. */
const pickable = computed(() => projects.value.filter((project) => !project.archived))

function colorFor(uri: string | null): string | null {
	if (uri === null) {
		return null
	}
	return projects.value.find((project) => project.uri === uri)?.color ?? null
}

function openApp() {
	window.location.href = generateUrl('/apps/chronos/')
}

refresh()
</script>

<template>
	<div :class="$style.root">
		<div v-if="initialLoading" :class="$style.loading">
			{{ t('chronos', 'Loading…') }}
		</div>

		<template v-else-if="active">
			<div :class="$style.state">
				<span :class="[$style.dot, { [$style.dotPaused]: isPaused }]" />
				<span :class="$style.stateLabel">{{ isPaused ? t('chronos', 'Paused') : t('chronos', 'Running') }}</span>
			</div>
			<div :class="$style.timer">
				{{ elapsed }}
			</div>
			<div v-if="active.projectName" :class="$style.projectLine">
				<span
					:class="$style.projectDot"
					:style="{ background: colorFor(active.projectUri) || 'var(--color-primary-element)' }" />
				<span>{{ active.projectName }}</span>
			</div>
			<div v-if="active.note" :class="$style.noteLine">
				"{{ active.note }}"
			</div>
			<div :class="$style.btnRow">
				<button
					v-if="!isPaused"
					:class="[$style.btn, $style.btnSecondary]"
					:disabled="loading"
					@click="pause">
					{{ t('chronos', 'Pause') }}
				</button>
				<button
					v-else
					:class="[$style.btn, $style.btnPrimary]"
					:disabled="loading"
					@click="resume">
					{{ t('chronos', 'Resume') }}
				</button>
				<button
					:class="[$style.btn, $style.btnError]"
					:disabled="loading"
					@click="checkOut">
					{{ t('chronos', 'Check out') }}
				</button>
			</div>
		</template>

		<template v-else>
			<div :class="$style.todayLine">
				<span :class="$style.todayLabel">{{ t('chronos', 'Today') }}</span>
				<span :class="$style.todayValue">{{ todayTotal }}</span>
			</div>
			<div :class="$style.selectWrap">
				<NcSelect
					v-model="selectedProject"
					:options="pickable"
					label="name"
					:reduce="(project: Project) => project"
					:clearable="true"
					:placeholder="t('chronos', 'No project')"
					:disabled="loading" />
			</div>
			<input
				v-model="note"
				type="text"
				:class="$style.input"
				:placeholder="t('chronos', 'What are you working on?')"
				:disabled="loading"
				@keydown.enter="checkIn">
			<button
				:class="[$style.btn, $style.btnPrimary, $style.btnWide]"
				:disabled="loading"
				@click="checkIn">
				{{ t('chronos', 'Check in') }}
			</button>
		</template>

		<div v-if="recent.length" :class="$style.recent">
			<div :class="$style.recentTitle">
				{{ t('chronos', 'Recent') }}
			</div>
			<ul :class="$style.recentList">
				<li
					v-for="session in recent"
					:key="session.id"
					:class="$style.recentItem"
					@click="openApp">
					<span
						:class="$style.recentDot"
						:style="{ background: colorFor(session.projectUri) || 'var(--color-text-maxcontrast)' }" />
					<span :class="$style.recentNote">{{ session.note || t('chronos', 'Untitled') }}</span>
					<span :class="$style.recentDur">{{ formatDuration(workedSeconds(session, now)) }}</span>
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
	font-size: var(--chronos-text-small);
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
	/*
	 * The shape takes the readable colour and the glow the pale one: in
	 * Nextcloud 33 `--color-success` and its two siblings are background
	 * tints, so a dot painted in one is a dot nobody can see (§15.10).
	 */
	background: var(--color-success-text);
	box-shadow: 0 0 0 0 var(--color-success);
	animation: pulse 1.8s infinite;
}

.dotPaused {
	background: var(--color-warning-text);
	animation: none;
	box-shadow: none;
}

@keyframes pulse {
	0% { box-shadow: 0 0 0 0 var(--color-success); }
	70% { box-shadow: 0 0 0 8px transparent; }
	100% { box-shadow: 0 0 0 0 transparent; }
}

.stateLabel {
	font-size: var(--chronos-text-small);
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
	font-size: var(--chronos-text-small);
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
	font-size: var(--chronos-text-small);
	padding: 6px 10px;
	background: color-mix(in srgb, var(--color-main-text) 6%, transparent);
	border-radius: var(--chronos-radius-chip);
}

.todayLine {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	padding: 4px 2px 0;
}

.todayLabel {
	font-size: var(--chronos-text-small);
	text-transform: uppercase;
	letter-spacing: 0.1em;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.todayValue {
	font-size: var(--chronos-text-title);
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.selectWrap {
	width: 100%;
}

/*
 * Qualified with the element for the same reason the buttons are (§15.10):
 * Nextcloud styles every `input`, `select` and `textarea` through a selector
 * with an element in it, which outranks a single class — so this rule's
 * padding, width and height were being ignored.
 */
input.input {
	width: 100%;
	padding: 8px 12px;
	font-size: var(--chronos-text-small);
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

input.input:focus {
	border-color: var(--color-primary-element);
}

input.input:disabled {
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
	font-size: var(--chronos-text-small);
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
	background: var(--color-error-text);
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
	font-size: var(--chronos-text-small);
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
	border-radius: var(--chronos-radius-chip);
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
	font-size: var(--chronos-text-small);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	flex: 1;
	min-width: 0;
}

.recentDur {
	font-size: var(--chronos-text-small);
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	flex-shrink: 0;
}
</style>
