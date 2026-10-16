import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { showError } from '@nextcloud/dialogs'
import { fetchSummary } from '../api/analytics.ts'
import { messageFor } from '../api/client.ts'
import type { AnalyticsSummary, DayTotal } from '../types/index.ts'
import type { Seconds, UnixSeconds } from '../api/time.ts'
import { addDays, nowSeconds, startOfDay, toDateKey } from '../api/time.ts'
import { useProjectsStore } from './projects.ts'

/** The rolling window the overview shows: today and the six days before it. */
const WINDOW_DAYS = 7

/**
 * Totals from the server (§16). The store keeps the range it asked for, so the
 * figures are correct however many sessions exist — the old version added up
 * whatever the newest 100 happened to be (D1).
 */
export const useAnalyticsStore = defineStore('analytics', () => {
	const summary = ref<AnalyticsSummary | null>(null)
	const loading = ref(false)
	const fromAt = ref<UnixSeconds>(addDays(startOfDay(nowSeconds()), -(WINDOW_DAYS - 1)))
	const toAt = ref<UnixSeconds>(addDays(startOfDay(nowSeconds()), 1))

	async function refresh(): Promise<void> {
		// Re-anchor on today, in case the page has been open since yesterday.
		const today = startOfDay(nowSeconds())
		fromAt.value = addDays(today, -(WINDOW_DAYS - 1))
		toAt.value = addDays(today, 1)

		loading.value = true
		try {
			summary.value = await fetchSummary(fromAt.value, toAt.value)
		} catch (error) {
			showError(messageFor(error))
		} finally {
			loading.value = false
		}
	}

	/** A day's total, counting only the projects the sidebar filter allows. */
	function secondsOf(day: DayTotal): Seconds {
		const projects = useProjectsStore()
		return day.projects
			.filter((project) => projects.matches(project.projectUri))
			.reduce((total, project) => total + project.seconds, 0)
	}

	/** One entry per day in the window, including the days with no time. */
	const days = computed(() => {
		const projects = useProjectsStore()
		const byDate = new Map((summary.value?.days ?? []).map((day) => [day.date, day]))
		const today = startOfDay(nowSeconds())

		return Array.from({ length: WINDOW_DAYS }, (_, index) => {
			const at = addDays(today, index - (WINDOW_DAYS - 1))
			const day = byDate.get(toDateKey(at))
			return {
				at,
				date: toDateKey(at),
				seconds: day ? secondsOf(day) : 0,
				projects: (day?.projects ?? []).filter((project) => projects.matches(project.projectUri)),
			}
		})
	})

	const todaySeconds = computed(() => days.value[days.value.length - 1]?.seconds ?? 0)

	const windowSeconds = computed(() =>
		days.value.reduce((total, day) => total + day.seconds, 0),
	)

	const dayMaxSeconds = computed(() =>
		Math.max(1, ...days.value.map((day) => day.seconds)),
	)

	const projects = computed(() => {
		const filter = useProjectsStore()
		return (summary.value?.projects ?? []).filter((project) => filter.matches(project.projectUri))
	})

	const sessionCount = computed(() =>
		projects.value.reduce((total, project) => total + project.sessionCount, 0),
	)

	return {
		summary,
		loading,
		fromAt,
		toAt,
		days,
		projects,
		todaySeconds,
		windowSeconds,
		dayMaxSeconds,
		sessionCount,
		refresh,
	}
})
