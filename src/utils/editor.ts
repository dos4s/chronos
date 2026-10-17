import type { Seconds, UnixSeconds } from '../api/time.ts'
import type { PlanBar, Session } from '../types/index.ts'

import { asSeconds } from '../api/time.ts'

/**
 * The rules of the session editor (§15.4), away from the drawing.
 *
 * A session and its breaks are not free to be anywhere: a break lives inside
 * its session and never over another one, nothing is shorter than a few
 * minutes, and dragging a session takes its breaks with it. Those rules are
 * what a drag is *allowed* to do, so they are a pure function of the session
 * and the position being tried — testable without a pointer, and applied to
 * the preview as well as to the write, so the block cannot be dragged
 * somewhere it will not be saved.
 */

/** Nothing worth recording is shorter than this. */
export const MIN_LENGTH: Seconds = 300

/** A break with both ends, which is the only kind the editor can draw. */
export interface Span {
	startAt: UnixSeconds
	endAt: UnixSeconds
}

/** The breaks of a session, in order, ignoring one that is still running. */
export function endedPauses(session: Session): Span[] {
	return session.pauses
		.flatMap((pause) => pause.endAt === null
			? []
			: [{ startAt: pause.startAt, endAt: pause.endAt }])
		.sort((a, b) => a.startAt - b.startAt)
}

/**
 * Where a session may be, given its breaks.
 *
 * Moving it is free — the breaks come along — but neither end may cross a
 * break: a session that started after its first break would describe a break
 * before the work began.
 */
export function clampSession(session: Session, kind: 'move' | 'start' | 'end', bar: PlanBar): PlanBar {
	const breaks = endedPauses(session)
	const first = breaks[0]?.startAt ?? null
	const last = breaks[breaks.length - 1]?.endAt ?? null

	if (kind === 'move') {
		return bar
	}

	if (kind === 'start') {
		const latest = Math.min(first ?? bar.endAt, bar.endAt) - MIN_LENGTH

		return { ...bar, startAt: asSeconds(Math.min(bar.startAt, latest)) }
	}

	const earliest = Math.max(last ?? bar.startAt, bar.startAt) + MIN_LENGTH

	return { ...bar, endAt: asSeconds(Math.max(bar.endAt, earliest)) }
}

/**
 * Where one break may be: inside its session, and between the breaks either
 * side of it (§15.4).
 */
export function clampPause(
	session: Session,
	index: number,
	kind: 'move' | 'start' | 'end',
	bar: PlanBar,
): PlanBar {
	const breaks = endedPauses(session)
	const end = session.endAt ?? bar.endAt
	const floor = breaks[index - 1]?.endAt ?? session.startAt
	const ceiling = breaks[index + 1]?.startAt ?? end
	const length = Math.max(MIN_LENGTH, bar.endAt - bar.startAt)

	if (kind === 'move') {
		// Pushed back inside rather than refused: a drag that runs into the
		// end of its session stops there, which is what a wall feels like.
		const startAt = Math.min(Math.max(bar.startAt, floor), ceiling - length)

		return { ...bar, startAt: asSeconds(startAt), endAt: asSeconds(startAt + length) }
	}

	if (kind === 'start') {
		const startAt = Math.min(Math.max(bar.startAt, floor), bar.endAt - MIN_LENGTH)

		return { ...bar, startAt: asSeconds(startAt) }
	}

	const endAt = Math.max(Math.min(bar.endAt, ceiling), bar.startAt + MIN_LENGTH)

	return { ...bar, endAt: asSeconds(endAt) }
}

/**
 * The session as it should be saved after one of its own blocks moved.
 *
 * Moving a session carries its breaks: they are inside it, and a break that
 * stayed behind would be a break in time somebody else was working.
 */
export function sessionAfter(session: Session, bar: PlanBar): {
	startAt: UnixSeconds
	endAt: UnixSeconds
	pauses: Span[]
} {
	// Both ends by the same amount is a move, which carries the breaks; one
	// end is a resize, which leaves them where they are.
	const delta = bar.startAt - session.startAt
	const atEnd = bar.endAt - (session.endAt ?? bar.endAt)
	const moved = delta !== 0 && delta === atEnd

	return {
		startAt: bar.startAt,
		endAt: bar.endAt,
		pauses: endedPauses(session).map((pause) => moved
			? { startAt: asSeconds(pause.startAt + delta), endAt: asSeconds(pause.endAt + delta) }
			: pause),
	}
}

/** The session as it should be saved after one of its breaks moved. */
export function pausesAfter(session: Session, index: number, bar: PlanBar): Span[] {
	return endedPauses(session).map((pause, at) => at === index
		? { startAt: bar.startAt, endAt: bar.endAt }
		: pause)
}
