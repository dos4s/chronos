import type { UnixSeconds } from '../api/time.ts'
import type { BoardColumn, TaskWritePayload } from '../types/index.ts'

/**
 * What a task is made of when it is made somewhere other than the list (§14.3).
 *
 * The board and the roadmap each know something the composer does not — which
 * column a card was added to, which day a diamond was dropped on — and that
 * knowledge is a handful of fields on the write. They live here rather than in
 * the view so they can be tested without a DOM (§18), and because the board's
 * is a real rule rather than a default: a card added to a column has to *land*
 * in that column, which under a workflow means its status key and without one
 * means the plain VTODO status the column stands for.
 */

/** The blank a new task starts from: open, undated, unranked. */
export function blankTask(summary: string, parentUid: string | null = null): TaskWritePayload {
	return {
		summary,
		status: 'NEEDS-ACTION',
		priority: 0,
		startAt: null,
		startIsDate: true,
		dueAt: null,
		dueIsDate: true,
		percent: null,
		parentUid,
		type: 'task',
	}
}

/**
 * A card added to a column of the board.
 *
 * Without a workflow the columns are the four standard statuses and a status
 * key would be refused, so only the VTODO status is sent. With one, the key is
 * what decides — and creating a card straight into a column in the middle of a
 * workflow is allowed, because a task that has never had a status has no
 * transition to disallow.
 *
 * A card added to a final column is finished the moment it exists, so it is
 * written at 100%: a done task at 0% reads as work nobody started.
 */
export function cardIn(column: BoardColumn, hasWorkflow: boolean): Partial<TaskWritePayload> {
	const done = column.vtodoStatus === 'COMPLETED'

	return {
		status: column.vtodoStatus,
		statusKey: hasWorkflow ? column.key : null,
		percent: done ? 100 : null,
	}
}

/**
 * A milestone put on the roadmap at a date.
 *
 * A milestone is a date something has to be true by, so it carries a `DUE` and
 * no start — one date, drawn as a diamond rather than a bar (§6, §12.6). The
 * date is a date and not an instant: a deadline on the 14th is the 14th
 * wherever it is read (§8.4).
 */
export function milestoneOn(at: UnixSeconds): Partial<TaskWritePayload> {
	return {
		type: 'milestone',
		startAt: null,
		startIsDate: true,
		dueAt: at,
		dueIsDate: true,
	}
}
