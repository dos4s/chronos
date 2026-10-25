import type { Session, TaskTotal } from '../types/index.ts'

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { asSeconds } from '../api/time.ts'

const fetchActive = vi.fn()
const fetchSessions = vi.fn()
const fetchTaskTotals = vi.fn()
const switchTo = vi.fn()

vi.mock('../api/sessions.ts', () => ({
	fetchActive: () => fetchActive(),
	fetchSessions: (limit: number) => fetchSessions(limit),
	fetchTaskTotals: () => fetchTaskTotals(),
	checkIn: vi.fn(),
	switchTo: (payload: unknown) => switchTo(payload),
	pauseSession: vi.fn(),
	resumeSession: vi.fn(),
	checkOut: vi.fn(),
	updateSession: vi.fn(),
	deleteSession: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

vi.mock('../api/client.ts', () => ({
	messageFor: (error: unknown) => String(error),
}))

const { useTimeStore } = await import('./time.ts')

const NOW = asSeconds(1_760_000_000)

/** The clock the store reads when it refreshes; the tests move it by hand. */
function freezeNow(at: number): void {
	vi.setSystemTime(at * 1000)
}

function session(extra: Partial<Session> = {}): Session {
	return {
		id: 1,
		userId: 'alice',
		startAt: asSeconds(NOW - 600),
		endAt: null,
		note: null,
		pausedSinceAt: null,
		pausedSeconds: 0,
		projectUri: 'principals/users/alice/work',
		projectName: 'Work',
		taskUid: 'task-1',
		taskSummary: 'Write the migration',
		pauses: [],
		...extra,
	}
}

function total(extra: Partial<TaskTotal> = {}): TaskTotal {
	return {
		taskUid: 'task-1',
		seconds: 3600,
		sessionCount: 2,
		lastAt: asSeconds(NOW - 600),
		...extra,
	}
}

describe('the time store, timing a task', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.useFakeTimers()
		freezeNow(NOW)
		fetchSessions.mockResolvedValue([])
		fetchActive.mockResolvedValue(null)
		fetchTaskTotals.mockResolvedValue([])
		switchTo.mockResolvedValue(session())
	})

	it('knows which task the clock is on', async () => {
		fetchActive.mockResolvedValue(session())
		const time = useTimeStore()

		await time.refresh()

		expect(time.activeTaskUid).toBe('task-1')
		expect(time.isTiming('task-1')).toBe(true)
		expect(time.isTiming('task-2')).toBe(false)
	})

	it('has no task when the running session names none', async () => {
		fetchActive.mockResolvedValue(session({ taskUid: null, taskSummary: null }))
		const time = useTimeStore()

		await time.refresh()

		expect(time.activeTaskUid).toBeNull()
	})

	it('reports what a finished task cost', async () => {
		fetchTaskTotals.mockResolvedValue([total({ seconds: 7200 })])
		const time = useTimeStore()

		await time.refresh()

		expect(time.secondsFor('task-1', NOW)).toBe(7200)
		expect(time.totalFor('task-1')?.sessionCount).toBe(2)
	})

	it('knows nothing about a task nobody has timed', async () => {
		const time = useTimeStore()

		await time.refresh()

		expect(time.secondsFor('task-9', NOW)).toBe(0)
		expect(time.totalFor('task-9')).toBeNull()
	})

	/**
	 * The arithmetic that is easy to get wrong: the server's total already
	 * counts the running session up to the moment it answered, so the live
	 * figure replaces that part rather than being added on top of it. Here the
	 * task has an hour logged, of which the running session is ten minutes;
	 * five minutes later the total has to read 1h 05m, not 1h 10m.
	 */
	it('counts the running session once, not twice', async () => {
		fetchActive.mockResolvedValue(session())
		fetchTaskTotals.mockResolvedValue([total({ seconds: 3600 })])
		const time = useTimeStore()

		await time.refresh()

		expect(time.secondsFor('task-1', NOW)).toBe(3600)
		expect(time.secondsFor('task-1', asSeconds(NOW + 300))).toBe(3900)
	})

	/** A paused session stops adding to the total, as it stops the clock. */
	it('stops counting while the session is paused', async () => {
		fetchActive.mockResolvedValue(session({ pausedSinceAt: NOW }))
		fetchTaskTotals.mockResolvedValue([total({ seconds: 3600 })])
		const time = useTimeStore()

		await time.refresh()

		expect(time.secondsFor('task-1', asSeconds(NOW + 300))).toBe(3600)
	})

	/**
	 * Pressing play on a task switches rather than checking in, so the server
	 * closes whatever was running in the same transaction (§12.2).
	 */
	it('switches the clock onto a task, with the project it belongs to', async () => {
		const time = useTimeStore()

		await time.startTask({
			uid: 'task-2',
			summary: 'Draw the legend',
			calendarUri: 'principals/users/alice/work',
		}, 'Work')

		expect(switchTo).toHaveBeenCalledWith({
			taskUid: 'task-2',
			taskSummary: 'Draw the legend',
			projectUri: 'principals/users/alice/work',
			projectName: 'Work',
		})
	})

	it('leaves out a name a task does not have', async () => {
		const time = useTimeStore()

		await time.startTask({ uid: 'task-2', summary: null, calendarUri: 'cal' })

		expect(switchTo).toHaveBeenCalledWith({ taskUid: 'task-2', projectUri: 'cal' })
	})
})
