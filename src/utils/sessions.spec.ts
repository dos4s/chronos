import { describe, expect, it } from 'vitest'
import { sessionChunks, workedSeconds } from './sessions.ts'
import { asSeconds } from '../api/time.ts'
import type { Session } from '../types/index.ts'

const NOW = asSeconds(1_760_000_000)

function session(overrides: Partial<Session> = {}): Session {
	return {
		id: 1,
		userId: 'alice',
		startAt: asSeconds(NOW - 3600),
		endAt: null,
		note: null,
		pausedSinceAt: null,
		pausedSeconds: 0,
		projectUri: null,
		projectName: null,
		pauses: [],
		...overrides,
	}
}

function pause(startAt: number, endAt: number | null, id = 1) {
	return { id, entryId: 1, startAt: asSeconds(startAt), endAt: endAt === null ? null : asSeconds(endAt) }
}

describe('workedSeconds', () => {
	it('counts a finished session as its span less its pauses', () => {
		const finished = session({
			startAt: asSeconds(1000),
			endAt: asSeconds(5000),
			pausedSeconds: 600,
		})

		expect(workedSeconds(finished, NOW)).toBe(3400)
	})

	it('counts a running session up to now', () => {
		expect(workedSeconds(session(), NOW)).toBe(3600)
	})

	it('stops counting while a running session is paused', () => {
		const paused = session({ pausedSinceAt: asSeconds(NOW - 600) })

		expect(workedSeconds(paused, NOW)).toBe(3000)
	})

	it('does not subtract an open pause twice once the session has ended', () => {
		// A finished session carries its pause in pausedSeconds; pausedSinceAt
		// is only meaningful while it runs.
		const finished = session({
			startAt: asSeconds(1000),
			endAt: asSeconds(5000),
			pausedSeconds: 600,
			pausedSinceAt: asSeconds(4000),
		})

		expect(workedSeconds(finished, NOW)).toBe(3400)
	})

	it('never goes below zero', () => {
		const odd = session({ startAt: asSeconds(1000), endAt: asSeconds(1100), pausedSeconds: 9000 })

		expect(workedSeconds(odd, NOW)).toBe(0)
	})
})

describe('sessionChunks', () => {
	it('returns the whole session when nothing interrupted it', () => {
		const whole = session({ startAt: asSeconds(1000), endAt: asSeconds(5000) })

		expect(sessionChunks(whole, asSeconds(5000))).toEqual([
			{ startAt: 1000, endAt: 5000 },
		])
	})

	it('splits the session around a pause', () => {
		const split = session({
			startAt: asSeconds(1000),
			endAt: asSeconds(5000),
			pauses: [pause(2000, 3000)],
		})

		expect(sessionChunks(split, asSeconds(5000))).toEqual([
			{ startAt: 1000, endAt: 2000 },
			{ startAt: 3000, endAt: 5000 },
		])
	})

	it('orders pauses before splitting', () => {
		const split = session({
			startAt: asSeconds(1000),
			endAt: asSeconds(9000),
			pauses: [pause(6000, 7000, 2), pause(2000, 3000, 1)],
		})

		expect(sessionChunks(split, asSeconds(9000))).toEqual([
			{ startAt: 1000, endAt: 2000 },
			{ startAt: 3000, endAt: 6000 },
			{ startAt: 7000, endAt: 9000 },
		])
	})

	it('leaves no empty chunk when a pause starts with the session', () => {
		const split = session({
			startAt: asSeconds(1000),
			endAt: asSeconds(5000),
			pauses: [pause(1000, 2000)],
		})

		expect(sessionChunks(split, asSeconds(5000))).toEqual([
			{ startAt: 2000, endAt: 5000 },
		])
	})

	it('leaves no empty chunk when a pause runs to the end', () => {
		const split = session({
			startAt: asSeconds(1000),
			endAt: asSeconds(5000),
			pauses: [pause(4000, 5000)],
		})

		expect(sessionChunks(split, asSeconds(5000))).toEqual([
			{ startAt: 1000, endAt: 4000 },
		])
	})

	it('ignores a pause that is still running', () => {
		const running = session({ startAt: asSeconds(1000), pausedSinceAt: asSeconds(4000), pauses: [pause(4000, null)] })

		expect(sessionChunks(running, asSeconds(4000))).toEqual([
			{ startAt: 1000, endAt: 4000 },
		])
	})
})
