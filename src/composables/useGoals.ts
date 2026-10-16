import { ref, watch } from 'vue'

const STORAGE_KEY = 'chronos_goals'
const NO_PROJECT = '__none__'

/**
 * Weekly goals in hours, per project.
 *
 * Still in this browser's storage, which means they do not follow the user to
 * another device (D6). M5 moves them into `chronos_goals` on the server; the
 * reading and writing is kept here so that change touches one file.
 */
const goals = ref<Record<string, number>>(read())

watch(goals, (value) => {
	try {
		localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
	} catch {
		// Private windows and blocked storage: the goal simply is not kept.
	}
}, { deep: true })

function read(): Record<string, number> {
	try {
		const raw = localStorage.getItem(STORAGE_KEY)
		const parsed = raw === null ? {} : JSON.parse(raw)
		return typeof parsed === 'object' && parsed !== null ? parsed as Record<string, number> : {}
	} catch {
		return {}
	}
}

export function useGoals() {
	const keyFor = (projectUri: string | null) => projectUri ?? NO_PROJECT

	const goalHours = (projectUri: string | null): number => goals.value[keyFor(projectUri)] ?? 0

	/** Progress toward the goal, as a percentage capped at 100. */
	const progress = (projectUri: string | null, seconds: number): number => {
		const hours = goalHours(projectUri)
		if (hours <= 0) {
			return 0
		}
		return Math.min(100, Math.round((seconds / (hours * 3600)) * 100))
	}

	return { goals, keyFor, goalHours, progress }
}
