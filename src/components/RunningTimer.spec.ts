import type { Session } from '../types/index.ts'

import { describe, expect, it } from 'vitest'
import { asSeconds } from '../api/time.ts'
import { formatClock } from '../utils/format.ts'
import { workedSeconds } from '../utils/sessions.ts'

/**
 * What the badge in the navigation shows (§14.2).
 *
 * The component itself needs a DOM to test, which this project does not have
 * (§18); what can be pinned here is the arithmetic behind the number — and the
 * one behaviour that matters most, which is that a paused timer stops.
 */

const START = asSeconds(Date.parse('2026-10-18T09:00:00Z') / 1000)

function session(extra: Partial<Session> = {}): Session {
	return {
		id: 1,
		userId: 'alice',
		startAt: START,
		endAt: null,
		pausedSinceAt: null,
		pausedSeconds: 0,
		note: null,
		projectUri: 'principals/users/alice/work',
		projectName: 'Work',
		pauses: [],
		...extra,
	}
}

describe('the running session', () => {
	it('counts up from when it started', () => {
		const now = asSeconds(START + 3661)

		expect(formatClock(workedSeconds(session(), now))).toBe('01:01:01')
	})

	/** A paused timer that kept counting would be lying about the work. */
	it('stops counting while it is paused', () => {
		const paused = session({ pausedSinceAt: asSeconds(START + 600) })

		expect(workedSeconds(paused, asSeconds(START + 600))).toBe(600)
		expect(workedSeconds(paused, asSeconds(START + 4000))).toBe(600)
	})

	it('takes the finished breaks off', () => {
		const withBreak = session({ pausedSeconds: 900 })

		expect(workedSeconds(withBreak, asSeconds(START + 3600))).toBe(2700)
	})

	/** Nothing is ever shown as negative, whatever the clocks did. */
	it('never counts below zero', () => {
		expect(workedSeconds(session(), asSeconds(START - 60))).toBe(0)
	})
})
