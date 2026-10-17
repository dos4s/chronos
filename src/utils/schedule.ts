import type { UnixSeconds } from '../api/time.ts'
import type { PlanConflict, PlanLink } from '../types/index.ts'
import type { Bar } from './planDates.ts'

import { endingAt, nextWorkingDay, sameBar, startingAt } from './planDates.ts'

/**
 * What the dependencies say about the dates, in the browser (§12.5, §15.4).
 *
 * The twin of `lib/Planning/Schedule.php`, case for case, because a drag has to
 * draw the answer before it sends it: the ghost bars shown while a bar is in
 * the air are this function's output, and the write is that one's. The same
 * cases are tested on both sides.
 *
 * Every constraint is "not before", never "exactly at": a task may always sit
 * later than its dependencies require, because slack is not an error.
 */

/** The relationships that decide dates (RFC 9253 §4). */
export const TEMPORAL_RELTYPES = ['FINISHTOSTART', 'STARTTOSTART', 'FINISHTOFINISH', 'STARTTOFINISH']

export type Dependency = Omit<PlanLink, 'temporal'> & { temporal?: boolean }

export type Conflict = PlanConflict

export interface Rescheduling {
	/** Every bar that ends up somewhere new, the dragged one included. */
	bars: Record<string, Bar>
	conflicts: Conflict[]
	/** The cascade reached more tasks than it may move, so nothing should go. */
	overflowed: boolean
}

export interface ScheduleOptions {
	cascade: boolean
	limit: number
	workingDays: number[]
}

export function isTemporal(link: Dependency): boolean {
	return TEMPORAL_RELTYPES.includes(link.reltype)
}

/** Every link the given dates break, plus every loop in the graph. */
export function conflictsIn(bars: Record<string, Bar>, links: Dependency[]): Conflict[] {
	const conflicts: Conflict[] = []

	for (const link of links) {
		if (!isTemporal(link)) {
			continue
		}

		const predecessor = bars[link.predecessorUid]
		const successor = bars[link.successorUid]
		if (predecessor === undefined || successor === undefined) {
			continue
		}

		const required = earliest(link, predecessor)
		const actual = endConstrained(link) ? successor.endAt : successor.startAt

		if (actual < required) {
			conflicts.push({
				kind: 'too_early',
				predecessorUid: link.predecessorUid,
				successorUid: link.successorUid,
				reltype: link.reltype,
				requiredAt: required,
				actualAt: actual,
			})
		}
	}

	return [...conflicts, ...loops(links)]
}

/**
 * Where everything sits once one bar has moved (§15.4).
 *
 * In cascade mode each dependent is pushed just far enough to satisfy the link
 * that moved, keeping its own length, and then onto a working day. A task is
 * only ever pushed later: a cascade never pulls work forward into the space a
 * predecessor left behind.
 */
export function reschedule(
	bars: Record<string, Bar>,
	links: Dependency[],
	moved: { uid: string, bar: Bar },
	options: ScheduleOptions,
): Rescheduling {
	const current: Record<string, Bar> = { ...bars, [moved.uid]: moved.bar }
	const changed: Record<string, Bar> = { [moved.uid]: moved.bar }

	if (!options.cascade) {
		return { bars: changed, conflicts: conflictsIn(current, links), overflowed: false }
	}

	const backEdges = new Set(loops(links).map((conflict) => `${conflict.predecessorUid}>${conflict.successorUid}`))
	const outgoing = new Map<string, Dependency[]>()
	for (const link of links) {
		if (isTemporal(link) && !backEdges.has(`${link.predecessorUid}>${link.successorUid}`)) {
			outgoing.set(link.predecessorUid, [...outgoing.get(link.predecessorUid) ?? [], link])
		}
	}

	const queue = [moved.uid]
	let overflowed = false
	let steps = 0
	const ceiling = Math.max(8, options.limit * 8)

	while (queue.length > 0 && steps++ < ceiling) {
		const uid = queue.shift() as string
		const predecessor = current[uid]
		if (predecessor === undefined) {
			continue
		}

		for (const link of outgoing.get(uid) ?? []) {
			const successor = current[link.successorUid]
			if (successor === undefined) {
				continue
			}

			const pushed = satisfy(link, predecessor, successor, options.workingDays)
			if (pushed === null) {
				continue
			}

			if (changed[link.successorUid] === undefined && Object.keys(changed).length >= options.limit) {
				overflowed = true
				continue
			}

			current[link.successorUid] = pushed
			changed[link.successorUid] = pushed
			queue.push(link.successorUid)
		}
	}

	return {
		bars: changed,
		conflicts: conflictsIn(current, links),
		overflowed: overflowed || queue.length > 0,
	}
}

/** The successor moved just far enough to satisfy one link, or null. */
function satisfy(link: Dependency, predecessor: Bar, successor: Bar, workingDays: number[]): Bar | null {
	const required = earliest(link, predecessor)

	let pushed = endConstrained(link)
		? (successor.endAt >= required ? null : endingAt(successor, required))
		: (successor.startAt >= required ? null : startingAt(successor, required))

	if (pushed === null) {
		return null
	}

	// A task does not begin on a day the project does not work. Pushing it
	// later can never break the link it was pushed for.
	const working = nextWorkingDay(pushed.startAt, pushed.startIsDate, workingDays)
	if (working !== pushed.startAt) {
		pushed = startingAt(pushed, working)
	}

	return sameBar(pushed, successor) ? null : pushed
}

/** The instant a link measures from, plus its gap. */
function earliest(link: Dependency, predecessor: Bar): UnixSeconds {
	const from = link.reltype === 'FINISHTOSTART' || link.reltype === 'FINISHTOFINISH'
		? predecessor.endAt
		: predecessor.startAt

	return (from + link.gapSeconds) as UnixSeconds
}

/** Whether the link constrains the successor's end rather than its start. */
function endConstrained(link: Dependency): boolean {
	return link.reltype === 'FINISHTOFINISH' || link.reltype === 'STARTTOFINISH'
}

/**
 * The links that close a loop. Chronos refuses to create one, but another
 * client can write one, and a cascade must not spin on it.
 */
function loops(links: Dependency[]): Conflict[] {
	const outgoing = new Map<string, Dependency[]>()
	for (const link of links) {
		if (isTemporal(link)) {
			outgoing.set(link.predecessorUid, [...outgoing.get(link.predecessorUid) ?? [], link])
		}
	}

	const state = new Map<string, 1 | 2>()
	const found = new Map<string, Conflict>()

	function walk(uid: string): void {
		if (state.get(uid) !== undefined) {
			return
		}
		state.set(uid, 1)

		for (const link of outgoing.get(uid) ?? []) {
			if (state.get(link.successorUid) === 1) {
				found.set(`${link.predecessorUid}>${link.successorUid}`, {
					kind: 'cycle',
					predecessorUid: link.predecessorUid,
					successorUid: link.successorUid,
					reltype: link.reltype,
					requiredAt: 0 as UnixSeconds,
					actualAt: 0 as UnixSeconds,
				})
				continue
			}
			walk(link.successorUid)
		}

		state.set(uid, 2)
	}

	for (const uid of outgoing.keys()) {
		walk(uid)
	}

	return [...found.values()]
}
