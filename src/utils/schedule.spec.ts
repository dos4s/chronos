import type { Bar } from './planDates.ts'
import type { Dependency } from './schedule.ts'

import { describe, expect, it } from 'vitest'
import { asSeconds } from '../api/time.ts'
import { duration, exclusiveEnd, inclusiveDue, isAllDay, shift, startingAt } from './planDates.ts'
import { conflictsIn, reschedule } from './schedule.ts'

/**
 * The browser's half of the scheduling (§15.4).
 *
 * These are the same cases as `tests/unit/Planning/ScheduleTest.php` and
 * `PlanDatesTest.php`, deliberately: the ghost bars a drag shows come from
 * here and the write comes from there, so if the two disagree the preview is a
 * lie. The dates are all-day, which is the case where the two frames — UTC for
 * a stored date, local for a timed one — are easiest to get wrong.
 */

/** Midnight UTC of a date, which is how an all-day date is stored (§8.4). */
function day(date: string) {
	return asSeconds(Date.parse(`${date}T00:00:00Z`) / 1000)
}

function bar(from: string, through: string): Bar {
	return {
		startAt: day(from),
		endAt: exclusiveEnd(day(through), true),
		startIsDate: true,
		dueIsDate: true,
		inferred: false,
		isMilestone: false,
	}
}

function link(from: string, to: string, reltype = 'FINISHTOSTART', gapSeconds = 0): Dependency {
	return { id: 0, predecessorUid: from, successorUid: to, reltype, gapSeconds }
}

const WEEKDAYS = [1, 2, 3, 4, 5]

describe('a bar', () => {
	it('covers the whole of the day it is due', () => {
		const friday = bar('2026-10-28', '2026-10-30')

		expect(friday.endAt).toBe(day('2026-10-31'))
		expect(inclusiveDue(friday.endAt, true)).toBe(day('2026-10-30'))
		expect(isAllDay(friday)).toBe(true)
	})

	/**
	 * M4's acceptance criterion, in the browser: the dates survive the clocks
	 * going forward, which adding 86,400 seconds seven times would not.
	 */
	it('keeps its dates when it is dragged across a daylight-saving change', () => {
		const moved = shift(bar('2026-03-26', '2026-03-27'), 7 * 86400)

		expect(moved.startAt).toBe(day('2026-04-02'))
		expect(inclusiveDue(moved.endAt, true)).toBe(day('2026-04-03'))
	})

	/**
	 * A timed bar keeps the time of day instead: whole days move as local days,
	 * so a nine o'clock task is still at nine o'clock next week whatever the
	 * clocks did in between. Asserted as the hour itself, so the test says the
	 * same thing in every zone it is run in.
	 */
	it('keeps the time of day when a timed bar is dragged', () => {
		const timed: Bar = {
			startAt: asSeconds(Date.parse('2026-03-26T09:00:00') / 1000),
			endAt: asSeconds(Date.parse('2026-03-26T17:00:00') / 1000),
			startIsDate: false,
			dueIsDate: false,
			inferred: false,
			isMilestone: false,
		}

		const moved = shift(timed, 7 * 86400)

		expect(new Date(moved.startAt * 1000).getHours()).toBe(new Date(timed.startAt * 1000).getHours())
		expect(new Date(moved.endAt * 1000).getHours()).toBe(new Date(timed.endAt * 1000).getHours())
		expect(duration(moved)).toBe(duration(timed))
	})

	it('keeps its length when it is dragged', () => {
		const original = bar('2026-10-01', '2026-10-10')

		expect(duration(shift(original, 3 * 86400))).toBe(duration(original))
	})

	it('keeps its length when it is pushed to a new start', () => {
		const original = bar('2026-10-01', '2026-10-05')
		const pushed = startingAt(original, day('2026-10-08'))

		expect(pushed.startAt).toBe(day('2026-10-08'))
		expect(duration(pushed)).toBe(duration(original))
	})
})

describe('what the dependencies break', () => {
	it('reports a successor that starts before its predecessor ends', () => {
		const conflicts = conflictsIn(
			{ a: bar('2026-10-05', '2026-10-09'), b: bar('2026-10-07', '2026-10-09') },
			[link('a', 'b')],
		)

		expect(conflicts).toHaveLength(1)
		expect(conflicts[0].kind).toBe('too_early')
		expect(conflicts[0].requiredAt).toBe(day('2026-10-10'))
		expect(conflicts[0].actualAt).toBe(day('2026-10-07'))
	})

	/** Slack is not an error. */
	it('says nothing about a successor that sits later than it has to', () => {
		expect(conflictsIn(
			{ a: bar('2026-10-05', '2026-10-09'), b: bar('2026-10-20', '2026-10-21') },
			[link('a', 'b')],
		)).toEqual([])
	})

	it('reports a loop instead of following it', () => {
		const conflicts = conflictsIn(
			{ a: bar('2026-10-05', '2026-10-06'), b: bar('2026-10-07', '2026-10-08') },
			[link('a', 'b'), link('b', 'a')],
		)

		expect(conflicts.map((conflict) => conflict.kind)).toContain('cycle')
	})

	/** `DEPENDS-ON` says what blocks what, not when (RFC 9253 §5). */
	it('ignores a link that is not a temporal one', () => {
		expect(conflictsIn(
			{ a: bar('2026-10-05', '2026-10-09'), b: bar('2026-10-01', '2026-10-02') },
			[link('a', 'b', 'DEPENDS-ON')],
		)).toEqual([])
	})
})

describe('a drag', () => {
	const options = { cascade: true, limit: 50, workingDays: [] as number[] }

	it('moves only the bar itself in warn mode, and says what it broke', () => {
		const result = reschedule(
			{ a: bar('2026-10-05', '2026-10-06'), b: bar('2026-10-07', '2026-10-08') },
			[link('a', 'b')],
			{ uid: 'a', bar: bar('2026-10-12', '2026-10-13') },
			{ ...options, cascade: false },
		)

		expect(Object.keys(result.bars)).toEqual(['a'])
		expect(result.conflicts).toHaveLength(1)
	})

	it('pushes the dependent exactly far enough in cascade mode', () => {
		const result = reschedule(
			{ a: bar('2026-10-05', '2026-10-09'), b: bar('2026-10-12', '2026-10-14') },
			[link('a', 'b')],
			{ uid: 'a', bar: bar('2026-10-07', '2026-10-13') },
			options,
		)

		expect(Object.keys(result.bars)).toEqual(['a', 'b'])
		expect(result.bars.b.startAt).toBe(day('2026-10-14'))
		expect(duration(result.bars.b)).toBe(3 * 86400)
		expect(result.conflicts).toEqual([])
	})

	it('never pulls a dependent earlier', () => {
		const result = reschedule(
			{ a: bar('2026-10-05', '2026-10-09'), b: bar('2026-10-20', '2026-10-21') },
			[link('a', 'b')],
			{ uid: 'a', bar: bar('2026-10-01', '2026-10-02') },
			options,
		)

		expect(Object.keys(result.bars)).toEqual(['a'])
	})

	it('follows the whole chain', () => {
		const result = reschedule(
			{
				a: bar('2026-10-05', '2026-10-06'),
				b: bar('2026-10-07', '2026-10-08'),
				c: bar('2026-10-09', '2026-10-12'),
			},
			[link('a', 'b'), link('b', 'c')],
			{ uid: 'a', bar: bar('2026-10-12', '2026-10-13') },
			options,
		)

		expect(Object.keys(result.bars)).toEqual(['a', 'b', 'c'])
		expect(result.bars.b.startAt).toBe(day('2026-10-14'))
		expect(result.bars.c.startAt).toBe(day('2026-10-16'))
	})

	it('waits for a lag and overlaps a lead', () => {
		const lag = conflictsIn(
			{ a: bar('2026-10-05', '2026-10-06'), b: bar('2026-10-07', '2026-10-08') },
			[link('a', 'b', 'FINISHTOSTART', 2 * 86400)],
		)
		const lead = conflictsIn(
			{ a: bar('2026-10-05', '2026-10-06'), b: bar('2026-10-07', '2026-10-08') },
			[link('a', 'b', 'FINISHTOSTART', -86400)],
		)

		expect(lag).toHaveLength(1)
		expect(lag[0].requiredAt).toBe(day('2026-10-09'))
		expect(lead).toEqual([])
	})

	it('constrains a start against a start', () => {
		const result = reschedule(
			{ a: bar('2026-10-05', '2026-10-09'), b: bar('2026-10-05', '2026-10-06') },
			[link('a', 'b', 'STARTTOSTART', 86400)],
			{ uid: 'a', bar: bar('2026-10-05', '2026-10-09') },
			options,
		)

		expect(result.bars.b.startAt).toBe(day('2026-10-06'))
	})

	it('constrains an end against an end', () => {
		const result = reschedule(
			{ a: bar('2026-10-05', '2026-10-09'), b: bar('2026-10-05', '2026-10-07') },
			[link('a', 'b', 'FINISHTOFINISH')],
			{ uid: 'a', bar: bar('2026-10-05', '2026-10-09') },
			options,
		)

		expect(result.bars.b.endAt).toBe(day('2026-10-10'))
		expect(result.bars.b.startAt).toBe(day('2026-10-07'))
	})

	it('constrains an end against a start', () => {
		const result = reschedule(
			{ a: bar('2026-10-10', '2026-10-12'), b: bar('2026-10-05', '2026-10-06') },
			[link('a', 'b', 'STARTTOFINISH')],
			{ uid: 'a', bar: bar('2026-10-10', '2026-10-12') },
			options,
		)

		expect(result.bars.b.endAt).toBe(day('2026-10-10'))
	})

	/** A cascade lands a task on a day the project works (§15.4). */
	it('pushes a start off a weekend', () => {
		const result = reschedule(
			{ a: bar('2026-10-29', '2026-10-30'), b: bar('2026-10-30', '2026-11-02') },
			[link('a', 'b')],
			{ uid: 'a', bar: bar('2026-10-29', '2026-10-30') },
			{ ...options, workingDays: WEEKDAYS },
		)

		expect(result.bars.b.startAt).toBe(day('2026-11-02'))
	})

	it('does not spin on a loop', () => {
		const result = reschedule(
			{ a: bar('2026-10-05', '2026-10-06'), b: bar('2026-10-07', '2026-10-08') },
			[link('a', 'b'), link('b', 'a')],
			{ uid: 'a', bar: bar('2026-10-09', '2026-10-10') },
			options,
		)

		expect(result.conflicts.map((conflict) => conflict.kind)).toContain('cycle')
	})

	it('says so rather than moving part of a chain that is too long', () => {
		const bars: Record<string, Bar> = { t0: bar('2026-10-01', '2026-10-02') }
		const links: Dependency[] = []
		for (let i = 1; i < 10; i++) {
			bars[`t${i}`] = bar('2026-10-02', '2026-10-03')
			links.push(link(`t${i - 1}`, `t${i}`))
		}

		const result = reschedule(bars, links, { uid: 't0', bar: bar('2026-10-20', '2026-10-21') }, {
			...options,
			limit: 4,
		})

		expect(result.overflowed).toBe(true)
		expect(Object.keys(result.bars).length).toBeLessThanOrEqual(4)
	})

	it('ignores a link to a task that is not on the plan', () => {
		const result = reschedule(
			{ a: bar('2026-10-05', '2026-10-06') },
			[link('a', 'somewhere-else')],
			{ uid: 'a', bar: bar('2026-10-09', '2026-10-10') },
			options,
		)

		expect(Object.keys(result.bars)).toEqual(['a'])
	})
})
