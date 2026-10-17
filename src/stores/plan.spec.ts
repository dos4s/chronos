import type { Plan, PlanBar, PlanRow } from '../types/index.ts'

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { asSeconds } from '../api/time.ts'

const fetchPlan = vi.fn()
const movePlanBar = vi.fn()
const createLink = vi.fn()
const deleteLink = vi.fn()
const savePlanSettings = vi.fn()

vi.mock('../api/plan.ts', () => ({
	fetchPlan: (project: string) => fetchPlan(project),
	movePlanBar: (payload: unknown) => movePlanBar(payload),
	createLink: (payload: unknown) => createLink(payload),
	deleteLink: (project: string, id: number) => deleteLink(project, id),
	savePlanSettings: (payload: unknown) => savePlanSettings(payload),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

/**
 * The HTTP client reaches for `window` as it loads — it is built for a browser
 * — so the test takes the two things the store uses from it and leaves the
 * rest alone.
 */
vi.mock('../api/client.ts', () => {
	class MockedApiError extends Error {
		readonly code: string
		readonly status: number

		constructor(code: string, status: number) {
			super(code)
			this.name = 'ApiError'
			this.code = code
			this.status = status
		}
	}

	return {
		ApiError: MockedApiError,
		messageFor: (error: unknown) => error instanceof MockedApiError ? error.code : 'failed',
	}
})

const { usePlanStore } = await import('./plan.ts')
const { ApiError } = await import('../api/client.ts')

/** An all-day date, which is midnight UTC of that day (§8.4). */
function day(date: string) {
	return asSeconds(Date.parse(`${date}T00:00:00Z`) / 1000)
}

function bar(from: string, throughExclusive: string): PlanBar {
	return {
		startAt: day(from),
		endAt: day(throughExclusive),
		startIsDate: true,
		dueIsDate: true,
		inferred: false,
		isMilestone: false,
	}
}

function row(uid: string, barOf: PlanBar | null, extra: Partial<PlanRow> = {}): PlanRow {
	return {
		id: 1,
		calendarUri: 'work',
		objectUri: `${uid}.ics`,
		etag: `etag-${uid}`,
		uid,
		type: 'task',
		parentUid: null,
		summary: uid,
		vtodoStatus: 'NEEDS-ACTION',
		priority: 0,
		percent: null,
		assignee: null,
		startAt: barOf?.startAt ?? null,
		dueAt: null,
		startIsDate: true,
		dueIsDate: true,
		completedAt: null,
		statusId: null,
		sprintId: null,
		updatedAt: asSeconds(0),
		depth: 0,
		bar: barOf,
		isSummary: false,
		progress: null,
		...extra,
	}
}

function plan(mode: 'warn' | 'cascade' = 'warn'): Plan {
	return {
		project: {
			uri: 'principals/users/alice/work',
			calendarUri: 'work',
			name: 'Work',
			color: null,
			writable: true,
		},
		from: day('2026-10-05'),
		to: day('2026-10-09'),
		rows: [
			row('a', bar('2026-10-05', '2026-10-07')),
			row('b', bar('2026-10-07', '2026-10-09')),
			row('parent', bar('2026-10-05', '2026-10-09'), { isSummary: true }),
			row('undated', null),
		],
		links: [{
			id: 4,
			predecessorUid: 'a',
			successorUid: 'b',
			reltype: 'FINISHTOSTART',
			gapSeconds: 0,
			temporal: true,
		}],
		conflicts: [],
		settings: { mode, workingDays: [] },
	}
}

async function loaded(mode: 'warn' | 'cascade' = 'warn') {
	const store = usePlanStore()
	fetchPlan.mockResolvedValue(plan(mode))
	await store.select('principals/users/alice/work')
	return store
}

beforeEach(() => {
	setActivePinia(createPinia())
	fetchPlan.mockReset()
	movePlanBar.mockReset()
	createLink.mockReset()
	deleteLink.mockReset()
	savePlanSettings.mockReset()
})

describe('selecting a project', () => {
	it('reads its plan', async () => {
		const store = await loaded()

		expect(fetchPlan).toHaveBeenCalledWith('principals/users/alice/work')
		expect(store.rows.map((it) => it.uid)).toEqual(['a', 'b', 'parent', 'undated'])
	})

	it('reads nothing at all without a project', async () => {
		const store = usePlanStore()
		await store.select(null)

		expect(fetchPlan).not.toHaveBeenCalled()
		expect(store.plan).toBeNull()
	})

	/** A summary is derived, so the scheduler neither moves nor measures it. */
	it('leaves the summary rows out of the bars it schedules with', async () => {
		const store = await loaded()

		expect(Object.keys(store.bars)).toEqual(['a', 'b'])
	})
})

describe('a drag', () => {
	it('sends both dates, the etag and the mode', async () => {
		const store = await loaded()
		movePlanBar.mockResolvedValue({ rows: [], conflicts: [], cascaded: false })

		await store.move('a', bar('2026-10-12', '2026-10-14'))

		expect(movePlanBar).toHaveBeenCalledWith({
			project: 'principals/users/alice/work',
			uid: 'a',
			startAt: day('2026-10-12'),
			// Inclusive, as a DUE date is: the bar ends at the start of the
			// 14th, so it is due on the 13th (§8.4).
			dueAt: day('2026-10-13'),
			startIsDate: true,
			dueIsDate: true,
			etag: 'etag-a',
			mode: 'warn',
		})
	})

	/** The bar is where it was dropped before the request is answered (§15.6). */
	it('shows the move before the server has answered', async () => {
		const store = await loaded()
		let resolve: (value: unknown) => void = () => {}
		movePlanBar.mockReturnValue(new Promise((done) => {
			resolve = done
		}))

		const pending = store.move('a', bar('2026-10-12', '2026-10-14'))

		expect(store.rowOf.a.bar?.startAt).toBe(day('2026-10-12'))

		resolve({ rows: [], conflicts: [], cascaded: false })
		await pending
	})

	/** In cascade mode the dependents move with it, worked out here (AD16). */
	it('moves what depends on it in cascade mode', async () => {
		const store = await loaded('cascade')
		movePlanBar.mockResolvedValue({ rows: [], conflicts: [], cascaded: true })

		await store.move('a', bar('2026-10-12', '2026-10-14'))

		expect(store.rowOf.b.bar?.startAt).toBe(day('2026-10-14'))
	})

	it('leaves the dependents alone in warn mode, and says what broke', async () => {
		const store = await loaded()
		movePlanBar.mockResolvedValue({
			rows: [],
			conflicts: [{
				kind: 'too_early',
				predecessorUid: 'a',
				successorUid: 'b',
				reltype: 'FINISHTOSTART',
				requiredAt: day('2026-10-14'),
				actualAt: day('2026-10-07'),
			}],
			cascaded: false,
		})

		await store.move('a', bar('2026-10-12', '2026-10-14'))

		expect(store.rowOf.b.bar?.startAt).toBe(day('2026-10-07'))
		expect(store.broken.has('a>b')).toBe(true)
	})

	it('takes the rows the server wrote, so the next write is not refused', async () => {
		const store = await loaded()
		movePlanBar.mockResolvedValue({
			rows: [{ ...row('a', bar('2026-10-12', '2026-10-14')), etag: 'etag-written' }],
			conflicts: [],
			cascaded: false,
		})

		await store.move('a', bar('2026-10-12', '2026-10-14'))

		expect(store.rowOf.a.etag).toBe('etag-written')
	})

	/** A refused move has to leave the plan as it was. */
	it('puts the plan back when the server refuses it', async () => {
		const store = await loaded()
		movePlanBar.mockRejectedValue(new ApiError('project_read_only', 403))

		const done = await store.move('a', bar('2026-10-12', '2026-10-14'))

		expect(done).toBe(false)
		expect(store.rowOf.a.bar?.startAt).toBe(day('2026-10-05'))
	})

	/** A 412 is news, not a failure: the plan is read again (§15.6). */
	it('reads the plan again when a task has moved on since', async () => {
		const store = await loaded()
		movePlanBar.mockRejectedValue(new ApiError('precondition_failed', 412))
		fetchPlan.mockResolvedValue(plan())

		await store.move('a', bar('2026-10-12', '2026-10-14'))

		expect(fetchPlan).toHaveBeenCalledTimes(2)
	})

	it('refuses to start a cascade bigger than one request may carry', async () => {
		const store = usePlanStore()
		const big = plan('cascade')
		for (let i = 0; i < 120; i++) {
			big.rows.push(row(`t${i}`, bar('2026-10-05', '2026-10-06')))
			if (i > 0) {
				big.links.push({
					id: 100 + i,
					predecessorUid: `t${i - 1}`,
					successorUid: `t${i}`,
					reltype: 'FINISHTOSTART',
					gapSeconds: 0,
					temporal: true,
				})
			}
		}
		fetchPlan.mockResolvedValue(big)
		await store.select('principals/users/alice/work')

		const done = await store.move('t0', bar('2026-11-01', '2026-11-02'))

		expect(done).toBe(false)
		expect(movePlanBar).not.toHaveBeenCalled()
	})
})

describe('the ghost bars a drag shows', () => {
	it('say where a cascade would put the dependents', async () => {
		const store = await loaded('cascade')

		const result = store.preview('a', bar('2026-10-12', '2026-10-14'))

		expect(Object.keys(result.bars)).toEqual(['a', 'b'])
		expect(result.bars.b.startAt).toBe(day('2026-10-14'))
	})

	it('say nothing would follow in warn mode, and what would break', async () => {
		const store = await loaded()

		const result = store.preview('a', bar('2026-10-12', '2026-10-14'))

		expect(Object.keys(result.bars)).toEqual(['a'])
		expect(result.conflicts).toHaveLength(1)
	})
})

describe('dependencies', () => {
	it('takes the whole plan back from a new link', async () => {
		const store = await loaded()
		const after = plan()
		after.conflicts = [{
			kind: 'too_early',
			predecessorUid: 'a',
			successorUid: 'b',
			reltype: 'FINISHTOSTART',
			requiredAt: day('2026-10-14'),
			actualAt: day('2026-10-07'),
		}]
		createLink.mockResolvedValue(after)

		await store.link('a', 'b', 'STARTTOSTART', 86400)

		expect(createLink).toHaveBeenCalledWith({
			project: 'principals/users/alice/work',
			predecessorUid: 'a',
			successorUid: 'b',
			reltype: 'STARTTOSTART',
			gapSeconds: 86400,
		})
		expect(store.conflicts).toHaveLength(1)
	})

	it('and from removing one', async () => {
		const store = await loaded()
		const after = plan()
		after.links = []
		deleteLink.mockResolvedValue(after)

		await store.unlink(4)

		expect(deleteLink).toHaveBeenCalledWith('principals/users/alice/work', 4)
		expect(store.links).toEqual([])
	})

	it('says why a link was refused and changes nothing', async () => {
		const store = await loaded()
		createLink.mockRejectedValue(new ApiError('link_cycle', 422))

		expect(await store.link('b', 'a')).toBe(false)
		expect(store.links).toHaveLength(1)
	})
})

describe('the project settings', () => {
	it('saves them and reads the plan again, because they change the dates', async () => {
		const store = await loaded()
		savePlanSettings.mockResolvedValue({ mode: 'cascade', workingDays: [1, 2, 3, 4, 5] })
		fetchPlan.mockResolvedValue(plan('cascade'))

		expect(await store.configure({ mode: 'cascade', workingDays: [1, 2, 3, 4, 5] })).toBe(true)
		expect(savePlanSettings).toHaveBeenCalledWith({
			project: 'principals/users/alice/work',
			mode: 'cascade',
			workingDays: [1, 2, 3, 4, 5],
		})
		expect(fetchPlan).toHaveBeenCalledTimes(2)
	})
})
