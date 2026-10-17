import type { UnixSeconds } from '../api/time.ts'
import type { PlanBar } from '../types/index.ts'

import { addDays, addDaysUtc, asSeconds } from '../api/time.ts'

/**
 * The arithmetic a timeline stands on, in the browser (§8.4, §15.4).
 *
 * The twin of `lib/Planning/PlanDates.php`, and deliberately so: the ghost bars
 * a drag shows are worked out here and the write is worked out there, so if the
 * two disagree the preview is a lie. The rules are the same three:
 *
 * - a bar's `endAt` is **exclusive**, so a task due Friday ends at the start of
 *   Saturday and `>=` comparisons need no off-by-a-day;
 * - an all-day date moves in **UTC days**, because that is the frame it is
 *   stored in;
 * - a timed date moves in **local days**, so a 9 am task dragged across a
 *   daylight-saving change is still at 9 am.
 */

/** One bar on the timeline, as the server sends it and the drag moves it. */
export type Bar = PlanBar

export function isAllDay(bar: Bar): boolean {
	return bar.startIsDate && bar.dueIsDate
}

export function duration(bar: Bar): number {
	return Math.max(0, bar.endAt - bar.startAt)
}

/** A bar's end as an exclusive instant (§8.4). */
export function exclusiveEnd(dueAt: UnixSeconds, isDate: boolean): UnixSeconds {
	return isDate ? addDaysUtc(dueAt, 1) : dueAt
}

/** The `DUE` value to write for a bar that ends here — the inverse. */
export function inclusiveDue(endAt: UnixSeconds, isDate: boolean): UnixSeconds {
	return isDate ? addDaysUtc(endAt, -1) : endAt
}

/** The bar moved by this many seconds, keeping its length. */
export function shift(bar: Bar, seconds: number): Bar {
	if (isAllDay(bar)) {
		const days = Math.round(seconds / 86400)
		return { ...bar, startAt: addDaysUtc(bar.startAt, days), endAt: addDaysUtc(bar.endAt, days) }
	}

	const days = Math.trunc(seconds / 86400)
	const rest = seconds - days * 86400

	return {
		...bar,
		startAt: asSeconds(addDays(bar.startAt, days) + rest),
		endAt: asSeconds(addDays(bar.endAt, days) + rest),
	}
}

/** The same bar starting here, length unchanged — what a cascade does. */
export function startingAt(bar: Bar, startAt: UnixSeconds): Bar {
	return shift(bar, startAt - bar.startAt)
}

/** The same bar ending here, length unchanged. */
export function endingAt(bar: Bar, endAt: UnixSeconds): Bar {
	return shift(bar, endAt - bar.endAt)
}

/** Whether two bars sit in the same place, which is how a no-op drag is spotted. */
export function sameBar(one: Bar, other: Bar): boolean {
	return one.startAt === other.startAt && one.endAt === other.endAt
}

/**
 * The bar a drag creates or resizes, from the two instants it now covers.
 *
 * A bar the plan had only guessed at stops being a guess the moment somebody
 * drags it, which is why `inferred` is false here.
 */
export function barBetween(startAt: UnixSeconds, endAt: UnixSeconds, like: Bar): Bar {
	return {
		startAt,
		endAt,
		startIsDate: like.startIsDate,
		dueIsDate: like.dueIsDate,
		inferred: false,
		isMilestone: like.isMilestone,
	}
}

/** Which weekdays a project works, as `Date.getDay()` numbers them (0 = Sunday). */
export function isWorkingDay(at: UnixSeconds, isDate: boolean, workingDays: number[]): boolean {
	if (workingDays.length === 0 || workingDays.length === 7) {
		return true
	}

	const date = new Date(at * 1000)
	// ISO weekdays, 1 = Monday, as the API sends them.
	const weekday = isDate ? date.getUTCDay() : date.getDay()

	return workingDays.includes(weekday === 0 ? 7 : weekday)
}

/** The same instant, or the same time on the next working day (§15.4). */
export function nextWorkingDay(at: UnixSeconds, isDate: boolean, workingDays: number[]): UnixSeconds {
	if (workingDays.length === 0) {
		return at
	}

	let moved = at
	for (let step = 0; step < 7; step++) {
		if (isWorkingDay(moved, isDate, workingDays)) {
			return moved
		}
		moved = isDate ? addDaysUtc(moved, 1) : addDays(moved, 1)
	}

	return at
}
