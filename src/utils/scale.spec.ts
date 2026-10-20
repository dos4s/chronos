import type { PlanBar } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { asSeconds, startOfDay } from '../api/time.ts'
import { allDayOf, createScale, windowFor } from './scale.ts'

/**
 * Pixels to time and back (§15.4).
 *
 * The whole chart is this function's output, so a mistake here is a bar in the
 * wrong place and a drag that lands on the wrong day. It is a plain value
 * object over three numbers, which is why it can be tested without a DOM.
 */

/** A local midnight, which is where the axis puts its day boundaries. */
function localDay(date: string) {
	return startOfDay(asSeconds(Date.parse(`${date}T12:00:00`) / 1000))
}

/** An all-day date, stored as midnight UTC (§8.4). */
function utcDay(date: string) {
	return asSeconds(Date.parse(`${date}T00:00:00Z`) / 1000)
}

function bar(startAt: number, endAt: number, allDay = true): PlanBar {
	return {
		startAt: asSeconds(startAt),
		endAt: asSeconds(endAt),
		startIsDate: allDay,
		dueIsDate: allDay,
		inferred: false,
		isMilestone: false,
	}
}

describe('the window a plan opens on', () => {
	const now = asSeconds(Date.parse('2026-10-15T10:00:00') / 1000)

	it('pads the extent of the bars', () => {
		const window = windowFor(localDay('2026-10-10'), localDay('2026-10-20'), now)

		expect(window.from).toBe(localDay('2026-10-03'))
		expect(window.to).toBeGreaterThan(localDay('2026-10-26'))
	})

	it('keeps today in view even when everything is in the past', () => {
		const window = windowFor(localDay('2026-01-01'), localDay('2026-01-10'), now)

		expect(window.to).toBeGreaterThan(now)
	})

	/** Most of a real list has no dates at all to begin with. */
	it('shows a fortnight around today when nothing has a date', () => {
		const window = windowFor(null, null, now)

		expect(window.from).toBeLessThan(now)
		expect(window.to - window.from).toBeGreaterThanOrEqual(14 * 86400)
	})
})

describe('placing a bar', () => {
	const scale = createScale('week', localDay('2026-10-01'), localDay('2026-10-31'))

	it('starts the window on a day boundary and is as wide as the time it shows', () => {
		expect(scale.from).toBe(localDay('2026-10-01'))
		// Linear in instants, so the extra hour a clock change adds is an
		// extra hour of chart rather than a day that is a column short.
		expect(scale.width).toBe((scale.to - scale.from) * scale.pxPerSecond)
		expect(scale.width).toBeGreaterThanOrEqual(30 * scale.pxPerDay)
	})

	/**
	 * An all-day date is a day, not an instant (§8.4), so it is placed by its
	 * date on the day grid — not by its UTC midnight, which in most zones
	 * falls inside the day before or an hour into the right one.
	 */
	it('puts an all-day bar exactly on its day columns', () => {
		const place = scale.place(bar(utcDay('2026-10-05'), utcDay('2026-10-08')))

		expect(place.x).toBe(4 * scale.pxPerDay)
		expect(place.width).toBe(3 * scale.pxPerDay)
	})

	it('puts a timed bar at the time of day it is', () => {
		const nine = asSeconds(Date.parse('2026-10-05T09:00:00') / 1000)
		const five = asSeconds(Date.parse('2026-10-05T17:00:00') / 1000)

		const place = scale.place(bar(nine, five, false))

		expect(place.x).toBeCloseTo(4 * scale.pxPerDay + 9 * scale.pxPerDay / 24, 5)
		expect(place.width).toBeCloseTo(8 * scale.pxPerDay / 24, 5)
	})

	it('gives a bar with no length something to click on', () => {
		const at = utcDay('2026-10-05')

		expect(scale.place(bar(at, at)).width).toBeGreaterThanOrEqual(2)
	})
})

describe('reading a pointer back', () => {
	const scale = createScale('week', localDay('2026-10-01'), localDay('2026-10-31'))

	/**
	 * Snapped against the window's own edge, which is a local midnight — not
	 * against the epoch, which would land a day's snap at two in the morning
	 * for half of Europe.
	 */
	it('snaps a timed instant to the reader\'s own midnight', () => {
		expect(scale.atOf(4 * scale.pxPerDay + 1, false)).toBe(localDay('2026-10-05'))
	})

	/** A date is a date: the day the pointer is over, back in UTC. */
	it('reads a day as the all-day value for that day', () => {
		expect(scale.atOf(4 * scale.pxPerDay + 3, true)).toBe(utcDay('2026-10-05'))
		expect(scale.atOf(4 * scale.pxPerDay + scale.pxPerDay - 1, true)).toBe(utcDay('2026-10-05'))
	})

	it('comes back to where it started', () => {
		const all = bar(utcDay('2026-10-07'), utcDay('2026-10-09'))
		const place = scale.place(all)

		expect(scale.atOf(place.x, true)).toBe(all.startAt)
	})

	it('snaps a day zoom to quarter hours and a week zoom to days', () => {
		expect(createScale('day', localDay('2026-10-01'), localDay('2026-10-02')).snapUnit(bar(0, 0, false))).toBe(900)
		expect(scale.snapUnit(bar(0, 0, false))).toBe(86400)
		// An all-day bar snaps to a day whatever the zoom: it has no time.
		expect(createScale('day', localDay('2026-10-01'), localDay('2026-10-02')).snapUnit(bar(0, 0))).toBe(86400)
	})

	it('can be asked not to snap at all, which is what alt does', () => {
		const exact = scale.atOf(4 * scale.pxPerDay + 1234, false, false)

		expect(exact % 86400).not.toBe(0)
	})
})

describe('the grid behind the bars', () => {
	const scale = createScale('week', localDay('2026-10-26'), localDay('2026-11-09'))

	it('stripes the days the project does not work', () => {
		const stripes = scale.stripes([1, 2, 3, 4, 5])

		// Two weekends in a fortnight, two days each.
		expect(stripes).toHaveLength(4)
		expect(stripes[0].x).toBe(5 * scale.pxPerDay)
		expect(stripes[0].width).toBe(scale.pxPerDay)
	})

	it('stripes nothing when every day works', () => {
		expect(scale.stripes([])).toEqual([])
		expect(scale.stripes([1, 2, 3, 4, 5, 6, 7])).toEqual([])
	})

	/** Narrow stripes stop reading as weekends and start reading as a zebra. */
	it('stripes nothing once a day is too narrow to be one', () => {
		const wide = createScale('fit', localDay('2026-10-01'), localDay('2026-10-29'), 1400)
		const narrow = createScale('fit', localDay('2026-01-01'), localDay('2027-01-01'), 900)

		expect(wide.stripes([1, 2, 3, 4, 5]).length).toBeGreaterThan(0)
		expect(narrow.stripes([1, 2, 3, 4, 5])).toEqual([])
	})

	it('bands the months it spans', () => {
		const bands = scale.bands()

		expect(bands).toHaveLength(2)
		expect(bands[0].x).toBe(0)
		expect(bands[0].width + bands[1].width).toBeCloseTo(scale.width, 5)
	})

	it('ticks once a week at month zoom and once a day at week zoom', () => {
		const month = createScale('month', localDay('2026-10-01'), localDay('2026-10-29')).ticks()

		expect(month).toHaveLength(4)
		expect(scale.ticks()).toHaveLength(14)
	})
})

describe('all-day values', () => {
	it('turns the day an instant falls in into the date it is', () => {
		const noon = asSeconds(Date.parse('2026-10-05T12:00:00') / 1000)

		expect(allDayOf(noon)).toBe(utcDay('2026-10-05'))
	})
})

describe('fitting a plan to the chart it is drawn in', () => {
	const from = asSeconds(Date.UTC(2026, 0, 1) / 1000)
	const to = asSeconds(Date.UTC(2026, 2, 1) / 1000)

	it('spends exactly the room it is given', () => {
		const scale = createScale('fit', from, to, 1000)

		expect(Math.round(scale.width)).toBe(1000)
	})

	/** A chart that has not been measured yet still has to draw something. */
	it('falls back to a month when nobody says how wide it is', () => {
		const unmeasured = createScale('fit', from, to, 0)
		const month = createScale('month', from, to)

		expect(unmeasured.width).toBe(month.width)
	})

	it('refuses to go finer than a quarter of an hour of screen or coarser than a thread', () => {
		const roomy = createScale('fit', from, asSeconds(from + 2 * 86400), 4000)
		const cramped = createScale('fit', from, asSeconds(from + 4000 * 86400), 300)

		expect(roomy.pxPerDay).toBeLessThanOrEqual(240)
		expect(cramped.pxPerDay).toBeGreaterThanOrEqual(3)
	})

	it('thins its ticks out as the days get narrower', () => {
		const wide = createScale('fit', from, to, 6000)
		const narrow = createScale('fit', from, to, 400)

		expect(wide.ticks().length).toBeGreaterThan(narrow.ticks().length)
	})
})
