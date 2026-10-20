import type { UnixSeconds } from '../api/time.ts'
import type { PlanBar } from '../types/index.ts'

import { addDays, addDaysUtc, asSeconds, fromDate, startOfDay, toDate } from '../api/time.ts'
import { formatDayMonth, formatMonth, formatTimeOfDay } from './format.ts'

/**
 * The one function that converts pixels to time and back (§15.4).
 *
 * Everything a timeline draws goes through this: the bars, the grid, the
 * header, the weekend stripes, the today line, and every drag. It is a plain
 * value object over three numbers — the zoom, and the window it is showing —
 * so it can be tested without a DOM, and so nothing on the chart is ever
 * measured*: a bar's position is worked out, which is what lets a drag be
 * exact and lets a thousand rows draw without reading layout once (§15.2).
 *
 * **The axis is in local days**, because that is what a reader means by
 * Tuesday. An all-day date is not an instant, though — it is stored as
 * midnight UTC and means a whole day wherever you are (§8.4) — so an all-day
 * bar is placed by its **date** on that grid rather than by its instant.
 * Otherwise an all-day task would sit an hour into the column in Madrid and an
 * hour before it in New York, which at day zoom is half a bar's width.
 */

/**
 * `fit` is not a fixed scale: it is whatever makes the whole plan fit the chart
 * it is drawn in, which is the zoom a reader wants when they arrive (§12.5).
 */
export type Zoom = 'fit' | 'quarter' | 'month' | 'week' | 'day'

/**
 * How wide a day is at each zoom, and what a drag snaps to there (§15.4).
 *
 * `quarter` is the roadmap's: a year fits on two screens, which is the scale a
 * project is read at. The timeline uses the other three.
 */
const ZOOMS: Record<Zoom, { pxPerDay: number, snap: number }> = {
	/* Only the fallback, for a chart whose width is not known yet. */
	fit: { pxPerDay: 28, snap: 86400 },
	quarter: { pxPerDay: 8, snap: 86400 },
	month: { pxPerDay: 28, snap: 86400 },
	week: { pxPerDay: 120, snap: 86400 },
	day: { pxPerDay: 1200, snap: 900 },
}

export interface Tick {
	at: UnixSeconds
	x: number
	label: string
	/** A month boundary at month zoom, a day at the others. */
	major: boolean
}

export interface Band {
	label: string
	x: number
	width: number
}

export interface Stripe {
	x: number
	width: number
}

export interface Scale {
	zoom: Zoom
	/** The window, both ends on a local midnight. */
	from: UnixSeconds
	to: UnixSeconds
	pxPerDay: number
	pxPerSecond: number
	width: number
	/** Where an instant sits, or where a date sits on the day grid. */
	xOf: (at: UnixSeconds, isDate: boolean) => number
	/** The inverse, snapped to the zoom's unit unless asked not to be. */
	atOf: (x: number, isDate: boolean, snap?: boolean) => UnixSeconds
	/** What a drag of this bar snaps to: a day for an all-day one. */
	snapUnit: (bar: PlanBar) => number
	/** A bar's left edge and width, in pixels. */
	place: (bar: PlanBar) => { x: number, width: number }
	ticks: () => Tick[]
	bands: () => Band[]
	/** The days the project does not work, as stripes to draw behind the bars. */
	stripes: (workingDays: number[]) => Stripe[]
}

/**
 * The window a plan should open on: everything that has a bar, padded, with
 * today always in view — and a sensible fortnight when nothing has a date at
 * all, which is most of a real list to begin with.
 */
export function windowFor(
	from: UnixSeconds | null,
	to: UnixSeconds | null,
	now: UnixSeconds,
	zoom: Zoom = 'month',
): { from: UnixSeconds, to: UnixSeconds } {
	const padding = zoom === 'day' ? 1 : zoom === 'week' ? 3 : zoom === 'quarter' ? 21 : 7
	const earliest = from === null ? now : Math.min(from, now) as UnixSeconds
	const latest = to === null ? now : Math.max(to, now) as UnixSeconds

	const start = startOfDay(addDays(earliest, -padding))
	const end = startOfDay(addDays(latest, padding + 1))

	// Never narrower than a fortnight, or a bar fills the screen and there is
	// nowhere to drag it to.
	return {
		from: start,
		to: end - start < 14 * 86400 ? addDays(start, 14) : end,
	}
}

/**
 * `available` is how wide the chart may be, in pixels. It is only read at
 * `fit` zoom, where the scale is chosen so that the window fills that width
 * exactly — within reason: never finer than a day is useful and never so
 * coarse that a bar disappears.
 */
export function createScale(zoom: Zoom, from: UnixSeconds, to: UnixSeconds, available = 0): Scale {
	const { snap } = ZOOMS[zoom]
	const start = startOfDay(from)
	const end = Math.max(to, addDays(start, 1)) as UnixSeconds
	// The exact span, not a rounded count of days: a window that crosses a
	// clock change is not a whole number of days, and rounding it here would
	// leave the fitted chart a pixel wider than the room it was given.
	const span = Math.max(1, (end - start) / 86400)
	const pxPerDay = zoom === 'fit' && available > 0
		? Math.min(240, Math.max(3, available / span))
		: ZOOMS[zoom].pxPerDay
	const pxPerSecond = pxPerDay / 86400
	const width = (end - start) * pxPerSecond

	/**
	 * The local midnight of the calendar date an all-day value names. The
	 * value itself is midnight UTC, so the date is read in UTC and then placed
	 * on the local grid (§8.4).
	 */
	const localDayOf = (at: UnixSeconds): UnixSeconds => {
		const date = toDate(at)
		return fromDate(new Date(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()))
	}

	const xOf = (at: UnixSeconds, isDate: boolean): number => ((isDate ? localDayOf(at) : at) - start) * pxPerSecond

	const atOf = (x: number, isDate: boolean, snapping = true): UnixSeconds => {
		const at = asSeconds(start + x / pxPerSecond)
		if (isDate) {
			// A date is a date: the day the pointer is over, back in UTC.
			const local = toDate(startOfDay(at))
			return asSeconds(Date.UTC(local.getFullYear(), local.getMonth(), local.getDate()) / 1000)
		}
		if (!snapping) {
			return at
		}

		// Snapped against the window's own edge, which is a local midnight, so
		// a day's snap lands at midnight where the reader is rather than at
		// midnight UTC — two in the morning for half of Europe.
		return asSeconds(start + Math.round((at - start) / snap) * snap)
	}

	return {
		zoom,
		from: start,
		to: end,
		pxPerDay,
		pxPerSecond,
		width,
		xOf,
		atOf,
		snapUnit: (bar: PlanBar) => bar.startIsDate && bar.dueIsDate ? 86400 : snap,
		place: (bar: PlanBar) => {
			const allDay = bar.startIsDate && bar.dueIsDate
			const x = xOf(bar.startAt, allDay)
			const right = xOf(bar.endAt, allDay)

			return { x, width: Math.max(bar.isMilestone ? 0 : 2, right - x) }
		},
		ticks: () => {
			const ticks: Tick[] = []
			// Rounded, not ceiled: the hour a clock change adds to a day is
			// not another column (§8.4).
			const days = Math.round((end - start) / 86400)
			// At month zoom a tick per day is unreadable; one per week is not,
			// and at quarter zoom even that is too many. At `fit` the scale is
			// whatever the data needed, so the step follows from how much room
			// a day actually got.
			const step = zoom === 'fit'
				? (pxPerDay >= 60 ? 1 : pxPerDay >= 16 ? 7 : 28)
				: zoom === 'quarter' ? 28 : zoom === 'month' ? 7 : 1

			for (let day = 0; day < days; day += step) {
				const at = addDays(start, day)
				ticks.push({
					at,
					x: (at - start) * pxPerSecond,
					label: zoom === 'day' ? formatTimeOfDay(at) : formatDayMonth(at),
					major: toDate(at).getDate() === 1 || (zoom === 'day' && day === 0),
				})
			}

			if (zoom !== 'day') {
				return ticks
			}

			// Day zoom counts hours, not days.
			const hours: Tick[] = []
			for (let hour = 0; hour * 3600 < end - start; hour += 2) {
				const at = asSeconds(start + hour * 3600)
				hours.push({
					at,
					x: hour * 3600 * pxPerSecond,
					label: formatTimeOfDay(at),
					major: hour % 24 === 0,
				})
			}

			return hours
		},
		bands: () => {
			const bands: Band[] = []
			let at = start

			while (at < end) {
				const date = toDate(at)
				const next = fromDate(new Date(date.getFullYear(), date.getMonth() + 1, 1))
				const stop = Math.min(next, end) as UnixSeconds

				bands.push({
					label: formatMonth(asSeconds(Date.UTC(date.getFullYear(), date.getMonth(), 1) / 1000)),
					x: (at - start) * pxPerSecond,
					width: (stop - at) * pxPerSecond,
				})

				at = stop
			}

			return bands
		},
		stripes: (workingDays: number[]) => {
			/*
			 * Below about a centimetre a week the stripes stop marking the
			 * weekends and start being a zebra over the whole chart: six
			 * months at a fitted scale is forty pairs of narrow grey bands
			 * behind every bar (§15.13). The days are still there; they are
			 * just not worth drawing at that size.
			 */
			if (workingDays.length === 0 || workingDays.length === 7 || pxPerDay < 9) {
				return []
			}

			const stripes: Stripe[] = []
			const days = Math.round((end - start) / 86400)

			for (let day = 0; day < days; day++) {
				const at = addDays(start, day)
				const weekday = toDate(at).getDay()
				if (!workingDays.includes(weekday === 0 ? 7 : weekday)) {
					stripes.push({
						x: (at - start) * pxPerSecond,
						// Measured to the next boundary rather than assumed to
						// be a day wide: one day a year is 25 hours long.
						width: (addDays(at, 1) - at) * pxPerSecond,
					})
				}
			}

			return stripes
		},
	}
}

/** The UTC midnight an all-day value would have for the day `at` falls in. */
export function allDayOf(at: UnixSeconds): UnixSeconds {
	const date = toDate(startOfDay(at))

	return asSeconds(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()) / 1000)
}

/** One day later, in the frame the date belongs to (§8.4). */
export function dayAfter(at: UnixSeconds, isDate: boolean): UnixSeconds {
	return isDate ? addDaysUtc(at, 1) : addDays(at, 1)
}
