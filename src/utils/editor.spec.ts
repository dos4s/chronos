import type { Session } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { asSeconds } from '../api/time.ts'
import { clampPause, clampSession, endedPauses, MIN_LENGTH, pausesAfter, sessionAfter } from './editor.ts'

/**
 * What a drag in the session editor is allowed to do (§15.4).
 *
 * The rules are a pure function of the session and the position being tried,
 * which is why they can be tested without a pointer — and they are applied to
 * the preview as well as to the write, so a block cannot be dragged somewhere
 * it will not be saved.
 */

/** 09:00 on a day, in whatever zone the test is run in. */
const NINE = asSeconds(Date.parse('2026-10-13T09:00:00') / 1000)
const hour = 3600

function at(hours: number) {
	return asSeconds(NINE + hours * hour)
}

function bar(startAt: number, endAt: number) {
	return {
		startAt: asSeconds(startAt),
		endAt: asSeconds(endAt),
		startIsDate: false,
		dueIsDate: false,
		inferred: false,
		isMilestone: false,
	}
}

/** A nine-to-five session with a one-hour break at one o'clock. */
function session(): Session {
	return {
		id: 7,
		userId: 'alice',
		startAt: at(0),
		endAt: at(8),
		pausedSinceAt: null,
		pausedSeconds: hour,
		note: 'A day',
		projectUri: 'principals/users/alice/work',
		projectName: 'Work',
		taskUid: null,
		taskSummary: null,
		pauses: [{ id: 1, entryId: 7, startAt: at(4), endAt: at(5) }],
	}
}

describe('the breaks of a session', () => {
	it('come out in order, with a running one left out', () => {
		const running = session()
		running.pauses = [
			{ id: 2, entryId: 7, startAt: at(6), endAt: at(7) },
			{ id: 1, entryId: 7, startAt: at(4), endAt: at(5) },
			{ id: 3, entryId: 7, startAt: at(7), endAt: null },
		]

		expect(endedPauses(running).map((pause) => pause.startAt)).toEqual([at(4), at(6)])
	})
})

describe('dragging a session', () => {
	it('may be moved anywhere, and takes its breaks with it', () => {
		const moved = bar(at(2), at(10))

		expect(clampSession(session(), 'move', moved)).toEqual(moved)

		const after = sessionAfter(session(), moved)
		expect(after.startAt).toBe(at(2))
		expect(after.pauses).toEqual([{ startAt: at(6), endAt: at(7) }])
	})

	/** A session that started after its own break is not a session. */
	it('cannot have its start dragged past its first break', () => {
		const clamped = clampSession(session(), 'start', bar(at(5), at(8)))

		expect(clamped.startAt).toBe(asSeconds(at(4) - MIN_LENGTH))
	})

	it('cannot have its end dragged before its last break', () => {
		const clamped = clampSession(session(), 'end', bar(at(0), at(4.5)))

		expect(clamped.endAt).toBe(asSeconds(at(5) + MIN_LENGTH))
	})

	/** Resizing is not moving: the breaks stay where they are. */
	it('leaves the breaks alone when only one end moves', () => {
		const after = sessionAfter(session(), bar(at(0), at(10)))

		expect(after.pauses).toEqual([{ startAt: at(4), endAt: at(5) }])
	})
})

describe('dragging a break', () => {
	it('keeps it inside its session', () => {
		const early = clampPause(session(), 0, 'move', bar(at(-2), at(-1)))
		const late = clampPause(session(), 0, 'move', bar(at(9), at(10)))

		expect(early.startAt).toBe(at(0))
		expect(late.endAt).toBe(at(8))
		// Pushed back inside rather than refused, and still an hour long.
		expect(late.endAt - late.startAt).toBe(hour)
	})

	it('keeps it clear of the break before and after it', () => {
		const three = session()
		three.pauses = [
			{ id: 1, entryId: 7, startAt: at(2), endAt: at(3) },
			{ id: 2, entryId: 7, startAt: at(4), endAt: at(5) },
			{ id: 3, entryId: 7, startAt: at(6), endAt: at(7) },
		]

		// The middle one, dragged over the first.
		const back = clampPause(three, 1, 'move', bar(at(2.5), at(3.5)))
		expect(back.startAt).toBe(at(3))

		// And over the last.
		const forward = clampPause(three, 1, 'move', bar(at(5.5), at(6.5)))
		expect(forward.endAt).toBe(at(6))
	})

	it('cannot be resized past its neighbours', () => {
		const three = session()
		three.pauses = [
			{ id: 1, entryId: 7, startAt: at(2), endAt: at(3) },
			{ id: 2, entryId: 7, startAt: at(4), endAt: at(5) },
		]

		expect(clampPause(three, 1, 'start', bar(at(2.5), at(5))).startAt).toBe(at(3))
		expect(clampPause(three, 0, 'end', bar(at(2), at(4.5))).endAt).toBe(at(4))
	})

	it('cannot be made shorter than a few minutes', () => {
		const squashed = clampPause(session(), 0, 'end', bar(at(4), at(4) + 60))

		expect(squashed.endAt - squashed.startAt).toBe(MIN_LENGTH)
	})

	it('replaces only itself when it is saved', () => {
		const three = session()
		three.pauses = [
			{ id: 1, entryId: 7, startAt: at(2), endAt: at(3) },
			{ id: 2, entryId: 7, startAt: at(4), endAt: at(5) },
		]

		expect(pausesAfter(three, 1, bar(at(4.5), at(5.5)))).toEqual([
			{ startAt: at(2), endAt: at(3) },
			{ startAt: at(4.5), endAt: at(5.5) },
		])
	})
})
