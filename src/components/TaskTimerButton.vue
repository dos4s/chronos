<script setup lang="ts">
import type { TimedTask } from '../types/index.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useNow } from '../composables/useNow.ts'
import { useProjectsStore } from '../stores/projects.ts'
import { useTimeStore } from '../stores/time.ts'
import { formatClock, formatDuration } from '../utils/format.ts'

/**
 * Put the clock on this task, or take it off (§12.2, M5).
 *
 * This is the one control that joins the two halves of the app. Until it
 * existed a session could name a project and nothing else, so planning a task
 * and timing it were separate acts: you moved a card on the board, walked to
 * the Timer, and retyped what you were doing into a note.
 *
 * Pressing it while another task is running **switches** rather than refusing:
 * one request ends the old session and starts the new one, because moving from
 * one task to the next is the thing a person does twenty times a day and it is
 * not an error to report back at them.
 *
 * It is the same button in the task list, on a card and in the details dialog,
 * for the same reason there is only one task composer (§14.2).
 */
const props = defineProps<{
	task: TimedTask
	readOnly?: boolean
	/**
	 * Keep the figure back until the clock is actually on this task: for a
	 * board, where a column of cards should read as a column of names rather
	 * than a column of numbers.
	 */
	bare?: boolean
}>()

const time = useTimeStore()
const projects = useProjectsStore()
const { now } = useNow()

const timing = computed(() => time.isTiming(props.task.uid))

const logged = computed(() => time.secondsFor(props.task.uid, now.value))

/**
 * The running figure is a clock, because it is counting; a finished total is
 * a duration, because nobody cares that a task took two hours and eleven
 * seconds (§15.11).
 */
const figure = computed(() => {
	if (logged.value <= 0) {
		return null
	}
	return timing.value ? formatClock(logged.value) : formatDuration(logged.value)
})

const label = computed(() => {
	const name = props.task.summary ?? t('chronos', 'Untitled task')
	return timing.value
		? t('chronos', 'Stop timing “{task}”', { task: name })
		: t('chronos', 'Start timing “{task}”', { task: name })
})

function toggle(): void {
	if (props.readOnly === true || time.loading) {
		return
	}

	if (timing.value) {
		time.checkOut()
		return
	}

	time.startTask(props.task, projects.nameFor(props.task.calendarUri))
}
</script>

<template>
	<div :class="$style.timer">
		<!--
			The figure comes before the button so a row reads "2h 10m ▶" —
			the fact first, the thing you can do to it second.
		-->
		<span
			v-if="figure !== null && (bare !== true || timing)"
			:class="[$style.figure, { [$style.live]: timing }]"
			:title="t('chronos', 'Time logged on this task')">
			{{ figure }}
		</span>

		<NcButton
			variant="tertiary"
			size="small"
			:disabled="readOnly || time.loading"
			:ariaLabel="label"
			:title="label"
			@click.stop="toggle">
			<template #icon>
				<svg
					viewBox="0 0 24 24"
					width="18"
					height="18"
					:fill="timing ? 'var(--color-error-text)' : 'currentColor'"
					aria-hidden="true">
					<path v-if="timing" d="M18 18H6V6h12v12Z" />
					<path v-else d="M8 5.14v14l11-7-11-7Z" />
				</svg>
			</template>
		</NcButton>
	</div>
</template>

<style module>
.timer {
	display: flex;
	align-items: center;
	gap: 2px;
	flex-shrink: 0;
}

.figure {
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

/* Counting now: the one figure on the row that is still moving. */
.live {
	color: var(--color-primary-element);
	font-weight: 600;
}
</style>
