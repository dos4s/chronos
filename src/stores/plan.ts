import type { Plan, PlanBar, PlanConflict, PlanRow, PlanSettings } from '../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, messageFor } from '../api/client.ts'
import * as api from '../api/plan.ts'
import { inclusiveDue } from '../utils/planDates.ts'
import { reschedule } from '../utils/schedule.ts'

/** HTTP 412: the task changed since the page last read it (§15.6). */
const PRECONDITION_FAILED = 412

/** The most tasks one cascade may move, as the server has it too. */
const MAX_TASKS_PER_MOVE = 100

/**
 * One project's timeline (§12.5, §15.6).
 *
 * A drag is applied here first and sent afterwards, so the bar is where it was
 * dropped rather than a round trip later — and in cascade mode so are the
 * dependents, worked out by the same function the server will use (AD16). If
 * the server refuses the move, the plan goes back to exactly what it was and
 * says why.
 *
 * One thing it deliberately does not do optimistically: stretch the summary
 * bars above a moved task. They are derived from their children, and the
 * answer comes back with the move — a round trip later, rather than a second
 * copy of the rule.
 */
export const usePlanStore = defineStore('plan', () => {
	const plan = ref<Plan | null>(null)
	const projectRef = ref<string | null>(null)
	const initialLoading = ref(false)
	const saving = ref(false)

	const rows = computed(() => plan.value?.rows ?? [])
	const links = computed(() => plan.value?.links ?? [])
	const conflicts = computed(() => plan.value?.conflicts ?? [])
	const settings = computed<PlanSettings>(() => plan.value?.settings ?? { mode: 'warn', workingDays: [] })
	const writable = computed(() => plan.value?.project.writable ?? false)

	/** Every bar on the plan, by task, the summaries left out: those derive. */
	const bars = computed<Record<string, PlanBar>>(() => {
		const all: Record<string, PlanBar> = {}
		for (const row of rows.value) {
			if (row.bar !== null && !row.isSummary) {
				all[row.uid] = row.bar
			}
		}
		return all
	})

	const rowOf = computed<Record<string, PlanRow>>(() => {
		const all: Record<string, PlanRow> = {}
		for (const row of rows.value) {
			all[row.uid] = row
		}
		return all
	})

	/** `predecessor>successor` for every link the dates break (§15.3). */
	const broken = computed(() => new Set(conflicts.value.map((conflict) => `${conflict.predecessorUid}>${conflict.successorUid}`)))

	async function select(ref: string | null): Promise<void> {
		projectRef.value = ref
		plan.value = null

		if (ref === null) {
			return
		}

		initialLoading.value = true
		await refresh()
		initialLoading.value = false
	}

	async function refresh(): Promise<void> {
		const ref = projectRef.value
		if (ref === null) {
			return
		}

		try {
			plan.value = await api.fetchPlan(ref)
		} catch (error) {
			showError(messageFor(error))
		}
	}

	/**
	 * What a drag would do to everything else — the ghost bars shown before
	 * the drop (§15.3). The same function the server will use to write it.
	 */
	function preview(uid: string, bar: PlanBar, cascade = settings.value.mode === 'cascade') {
		return reschedule(bars.value, links.value, { uid, bar }, {
			cascade,
			limit: MAX_TASKS_PER_MOVE,
			workingDays: settings.value.workingDays,
		})
	}

	/**
	 * One bar, dropped. The plan shows it — and whatever follows it — straight
	 * away; the request goes afterwards and the server's answer replaces it.
	 */
	async function move(uid: string, bar: PlanBar, mode?: 'warn' | 'cascade'): Promise<boolean> {
		const current = plan.value
		const ref = projectRef.value
		if (current === null || ref === null) {
			return false
		}

		const effective = mode ?? settings.value.mode
		const result = preview(uid, bar, effective === 'cascade')
		if (result.overflowed) {
			showError(messageFor(new ApiError('too_many_tasks', 422)))
			return false
		}

		const etag = rowOf.value[uid]?.etag ?? null
		plan.value = withBars(current, result.bars, result.conflicts)
		saving.value = true

		try {
			const written = await api.movePlanBar({
				project: ref,
				uid,
				startAt: bar.startAt,
				dueAt: inclusiveDue(bar.endAt, bar.dueIsDate),
				startIsDate: bar.startIsDate,
				dueIsDate: bar.dueIsDate,
				etag,
				mode: effective,
			})

			reconcile(written.rows, written.conflicts)
			return true
		} catch (error) {
			// Back to exactly what it was: a bar that did not move must not be
			// left looking as though it had.
			plan.value = current
			showError(messageFor(error))

			if (error instanceof ApiError && error.status === PRECONDITION_FAILED) {
				await refresh()
			}

			return false
		} finally {
			saving.value = false
		}
	}

	/** A new dependency; the answer is the whole plan, conflicts and all. */
	async function link(
		predecessorUid: string,
		successorUid: string,
		reltype = 'FINISHTOSTART',
		gapSeconds = 0,
	): Promise<boolean> {
		const ref = projectRef.value
		if (ref === null) {
			return false
		}

		saving.value = true
		try {
			plan.value = await api.createLink({ project: ref, predecessorUid, successorUid, reltype, gapSeconds })
			return true
		} catch (error) {
			showError(messageFor(error))
			return false
		} finally {
			saving.value = false
		}
	}

	async function unlink(predecessorUid: string, successorUid: string): Promise<boolean> {
		const ref = projectRef.value
		if (ref === null) {
			return false
		}

		saving.value = true
		try {
			plan.value = await api.deleteLink(ref, predecessorUid, successorUid)
			return true
		} catch (error) {
			showError(messageFor(error))
			return false
		} finally {
			saving.value = false
		}
	}

	async function configure(next: PlanSettings): Promise<boolean> {
		const ref = projectRef.value
		const current = plan.value
		if (ref === null || current === null) {
			return false
		}

		saving.value = true
		try {
			const saved = await api.savePlanSettings({ project: ref, ...next })
			// The working days change which links are broken and where a
			// cascade would land, so the plan is read again.
			plan.value = { ...current, settings: saved }
			await refresh()
			return true
		} catch (error) {
			showError(messageFor(error))
			return false
		} finally {
			saving.value = false
		}
	}

	/** The plan with some bars somewhere else, which is what a drag shows. */
	function withBars(current: Plan, moved: Record<string, PlanBar>, found: PlanConflict[]): Plan {
		return {
			...current,
			conflicts: found,
			rows: current.rows.map((row) => {
				const bar = moved[row.uid]
				return bar === undefined ? row : { ...row, bar }
			}),
		}
	}

	/**
	 * The rows as the server wrote them — above all their new etags, without
	 * which the next write of the same task would be refused — including the
	 * summary rows it stretched and the ancestors whose progress changed.
	 */
	function reconcile(written: PlanRow[], found: PlanConflict[]): void {
		const current = plan.value
		if (current === null) {
			return
		}

		const byUid = new Map(written.map((row) => [row.uid, row]))

		plan.value = {
			...current,
			conflicts: found,
			rows: current.rows.map((row) => byUid.get(row.uid) ?? row),
		}
	}

	return {
		plan,
		projectRef,
		initialLoading,
		saving,
		rows,
		links,
		conflicts,
		settings,
		writable,
		bars,
		rowOf,
		broken,
		select,
		refresh,
		preview,
		move,
		link,
		unlink,
		configure,
	}
})
