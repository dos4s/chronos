import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import * as api from '../api/sessions.ts'
import { messageFor } from '../api/client.ts'
import type { CheckInPayload, Session, SessionUpdatePayload } from '../types/index.ts'

/**
 * Sessions as the app sees them. Every change goes through the API and the
 * server's answer is what lands in the store, so the UI never invents state.
 */
export const useTimeStore = defineStore('time', () => {
	const sessions = ref<Session[]>([])
	const active = ref<Session | null>(null)
	const loading = ref(false)
	const initialLoading = ref(true)

	const isPaused = computed(() => active.value?.pausedSinceAt != null)

	async function refresh(): Promise<void> {
		try {
			const [activeSession, list] = await Promise.all([
				api.fetchActive(),
				api.fetchSessions(500),
			])
			active.value = activeSession
			sessions.value = list
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

	const checkIn = (payload: CheckInPayload) =>
		run(() => api.checkIn(payload), t('chronos', 'Checked in'))

	const pause = () => run(api.pauseSession, t('chronos', 'Paused'))

	const resume = () => run(api.resumeSession, t('chronos', 'Resumed'))

	const checkOut = () => run(api.checkOut, t('chronos', 'Checked out'))

	const update = (id: number, payload: SessionUpdatePayload) =>
		run(() => api.updateSession(id, payload), t('chronos', 'Session updated'))

	const remove = (id: number) =>
		run(() => api.deleteSession(id), t('chronos', 'Session deleted'))

	return {
		sessions,
		active,
		loading,
		initialLoading,
		isPaused,
		refresh,
		checkIn,
		pause,
		resume,
		checkOut,
		update,
		remove,
	}
})
