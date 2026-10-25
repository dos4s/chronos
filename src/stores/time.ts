import type { Seconds, UnixSeconds } from '../api/time.ts'
import type { CheckInPayload, Session, SessionUpdatePayload, TaskTotal, TimedTask } from '../types/index.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { messageFor } from '../api/client.ts'
import * as api from '../api/sessions.ts'
import { nowSeconds } from '../api/time.ts'
import { workedSeconds } from '../utils/sessions.ts'

/**
 * Sessions as the app sees them. Every change goes through the API and the
 * server's answer is what lands in the store, so the UI never invents state.
 */
export const useTimeStore = defineStore('time', () => {
	const sessions = ref<Session[]>([])
	const active = ref<Session | null>(null)
	const loading = ref(false)
	const initialLoading = ref(true)
	/** What each task has cost, by uid — kept as a map for the views to read. */
	const taskTotals = ref<Record<string, TaskTotal>>({})
	/** How much of the running session the totals above already count. */
	const countedAt = ref(0)

	const isPaused = computed(() => active.value !== null && active.value.pausedSinceAt !== null)

	/** The task the clock is on, if it is on one at all. */
	const activeTaskUid = computed(() => active.value?.taskUid ?? null)

	const isTiming = (uid: string): boolean => activeTaskUid.value === uid

	/** Everything known about what a task has cost, or null if nothing is. */
	const totalFor = (uid: string): TaskTotal | null => taskTotals.value[uid] ?? null

	/**
	 * Logged seconds for a task, counting the session running on it: the total
	 * has to grow while you watch it, or the number looks broken.
	 *
	 * The server's total already counts the running session up to the moment it
	 * answered, so that much is taken back off and the live figure put in its
	 * place — rather than adding the run twice, or leaving the total frozen at
	 * whatever it was when the page loaded.
	 */
	function secondsFor(uid: string, now: UnixSeconds): Seconds {
		const logged = taskTotals.value[uid]?.seconds ?? 0
		const running = active.value
		if (running === null || running.taskUid !== uid) {
			return logged
		}

		return Math.max(0, logged - countedAt.value + workedSeconds(running, now))
	}

	async function refresh(): Promise<void> {
		try {
			const [activeSession, list, totals] = await Promise.all([
				api.fetchActive(),
				api.fetchSessions(500),
				api.fetchTaskTotals(),
			])
			active.value = activeSession
			sessions.value = list
			taskTotals.value = Object.fromEntries(totals.map((total: TaskTotal) => [total.taskUid, total]))
			countedAt.value = activeSession === null
				? 0
				: workedSeconds(activeSession, nowSeconds())
		} catch (error) {
			showError(messageFor(error))
		} finally {
			initialLoading.value = false
		}
	}

	/**
	 * Runs one action, reports it, and reloads. Returns whether it worked, so
	 * a view can close a dialog only on success.
	 */
	async function run(action: () => Promise<unknown>, success: string): Promise<boolean> {
		loading.value = true
		try {
			await action()
			await refresh()
			showSuccess(success)
			return true
		} catch (error) {
			showError(messageFor(error))
			return false
		} finally {
			loading.value = false
		}
	}

	const checkIn = (payload: CheckInPayload) => run(() => api.checkIn(payload), t('chronos', 'Checked in'))

	/**
	 * Puts the clock on a task from wherever the task is shown (§12.2, M5).
	 *
	 * It switches rather than checking in, because pressing play on a task
	 * while another one is running is what a person means to do — the server
	 * ends the old session and starts the new one in one transaction, so there
	 * is never a gap with nothing running or a moment with two.
	 */
	function startTask(task: TimedTask, projectName: string | null = null): Promise<boolean> {
		const payload: CheckInPayload = {
			taskUid: task.uid,
			projectUri: task.calendarUri,
		}
		if (task.summary !== null && task.summary !== '') {
			payload.taskSummary = task.summary
		}
		if (projectName !== null && projectName !== '') {
			payload.projectName = projectName
		}

		return run(() => api.switchTo(payload), t('chronos', 'Timing “{task}”', {
			task: task.summary ?? t('chronos', 'Untitled task'),
		}))
	}

	const pause = () => run(api.pauseSession, t('chronos', 'Paused'))

	const resume = () => run(api.resumeSession, t('chronos', 'Resumed'))

	const checkOut = () => run(api.checkOut, t('chronos', 'Checked out'))

	const update = (id: number, payload: SessionUpdatePayload) => run(() => api.updateSession(id, payload), t('chronos', 'Session updated'))

	const remove = (id: number) => run(() => api.deleteSession(id), t('chronos', 'Session deleted'))

	return {
		sessions,
		active,
		loading,
		initialLoading,
		isPaused,
		activeTaskUid,
		isTiming,
		totalFor,
		secondsFor,
		refresh,
		checkIn,
		startTask,
		pause,
		resume,
		checkOut,
		update,
		remove,
	}
})
