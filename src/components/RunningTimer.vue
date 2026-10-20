<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, watchEffect } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useNow } from '../composables/useNow.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTimeStore } from '../stores/time.ts'
import { formatClock } from '../utils/format.ts'
import { workedSeconds } from '../utils/sessions.ts'

/**
 * The running session, wherever you are (§14.2).
 *
 * In a time tracker this is the one piece of state that should never leave the
 * screen, and it used to be visible only on the Timer view: a session you had
 * forgotten was discoverable by navigating to it. So it sits at the top of the
 * navigation while it runs — with the time, the project, and the two buttons
 * that end it — and in the browser tab's title, which is where a forgotten
 * timer is actually noticed.
 *
 * Nothing is drawn when nothing is running: an empty panel would be a worse
 * answer than the Timer view, which is one click away.
 */
const time = useTimeStore()
const projects = useProjectsStore()
const { now } = useNow()

const session = computed(() => time.active)

/**
 * Stops counting while the session is paused, because `workedSeconds` takes
 * the running pause off — which is exactly what a paused timer should look
 * like.
 */
const elapsed = computed(() => session.value === null ? 0 : workedSeconds(session.value, now.value))

const name = computed(() => {
	const uri = session.value?.projectUri ?? null

	return projects.nameFor(uri) ?? session.value?.projectName ?? t('chronos', 'No project')
})

const color = computed(() => projects.colorFor(session.value?.projectUri ?? null))

/**
 * The tab title carries it too. A timer left running overnight is noticed in
 * the tab strip long before it is noticed in the app.
 */
const original = document.title

watchEffect(() => {
	document.title = session.value === null
		? original
		: `${formatClock(elapsed.value)} · ${name.value}`
})

onBeforeUnmount(() => {
	document.title = original
})
</script>

<template>
	<div v-if="session" :class="[$style.panel, { [$style.paused]: time.isPaused }]">
		<span
			:class="$style.dot"
			:style="{ background: color || 'var(--color-success)' }"
			aria-hidden="true" />

		<div :class="$style.what">
			<span :class="$style.clock">{{ formatClock(elapsed) }}</span>
			<span :class="$style.where">
				{{ time.isPaused ? t('chronos', 'Paused · {project}', { project: name }) : name }}
			</span>
		</div>

		<NcButton
			variant="tertiary"
			:disabled="time.loading"
			:aria-label="time.isPaused ? t('chronos', 'Resume') : t('chronos', 'Pause')"
			:title="time.isPaused ? t('chronos', 'Resume') : t('chronos', 'Pause')"
			@click="time.isPaused ? time.resume() : time.pause()">
			<template #icon>
				<svg
					viewBox="0 0 24 24"
					width="16"
					height="16"
					fill="currentColor"
					aria-hidden="true">
					<path v-if="time.isPaused" d="M8 5.14v14l11-7-11-7Z" />
					<path v-else d="M14 19h4V5h-4M6 19h4V5H6v14Z" />
				</svg>
			</template>
		</NcButton>

		<NcButton
			variant="tertiary"
			:disabled="time.loading"
			:aria-label="t('chronos', 'Check out')"
			:title="t('chronos', 'Check out')"
			@click="time.checkOut()">
			<template #icon>
				<svg
					viewBox="0 0 24 24"
					width="16"
					height="16"
					fill="currentColor"
					aria-hidden="true">
					<path d="M18 18H6V6h12v12Z" />
				</svg>
			</template>
		</NcButton>
	</div>
</template>

<style module>
.panel {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 8px;
	margin: 4px 8px 8px;
	border-radius: var(--chronos-radius-card);
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
}

/* A paused session is still a session, and says so rather than ticking. */
.paused {
	opacity: 0.75;
	border-style: dashed;
}

.dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
}

.what {
	display: flex;
	flex-direction: column;
	min-width: 0;
	flex: 1;
}

.clock {
	font-size: var(--chronos-text);
	font-weight: 600;
	font-variant-numeric: tabular-nums;
	line-height: 1.1;
}

.where {
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
</style>
