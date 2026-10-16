import { describe, expect, it } from 'vitest'
import { fromDate, toDate } from '../api/time.ts'
import { fromDateInput, toDateInput, toTimeInput } from './dateInput.ts'

describe('all-day values', () => {
	it('reads a date as the date it is, not as a moment', () => {
		const at = fromDateInput('2026-10-30', true)

		expect(at).not.toBeNull()
		expect(toDate(at!).toISOString()).toBe('2026-10-30T00:00:00.000Z')
	})

	/**
	 * The bug this guards against: in a zone behind UTC, reading an all-day
	 * date as a local moment lands on the previous day.
	 */
	it('round-trips a date unchanged', () => {
		for (const value of ['2026-01-01', '2026-06-15', '2026-10-30', '2026-12-31']) {
			expect(toDateInput(fromDateInput(value, true)!, true)).toBe(value)
		}
	})

	it('is unaffected by the hour of the day', () => {
		expect(fromDateInput('2026-10-30', true)).toBe(fromDateInput('2026-10-30', true, '23:59'))
	})
})

describe('timed values', () => {
	it('reads a date and time in the viewer\'s own zone', () => {
		const at = fromDateInput('2026-10-30', false, '18:00')

		expect(at).not.toBeNull()
		expect(toDate(at!).getHours()).toBe(18)
		expect(toDate(at!).getMinutes()).toBe(0)
	})

	it('round-trips a moment', () => {
		const at = fromDate(new Date(2026, 9, 30, 18, 45))

		expect(toDateInput(at, false)).toBe('2026-10-30')
		expect(toTimeInput(at)).toBe('18:45')
	})

	it('treats a missing time as midnight', () => {
		const at = fromDateInput('2026-10-30', false)

		expect(toDate(at!).getHours()).toBe(0)
	})
})

describe('bad input', () => {
	it('rejects anything that is not a date', () => {
		expect(fromDateInput('', true)).toBeNull()
		expect(fromDateInput('tomorrow', true)).toBeNull()
		expect(fromDateInput('30-10-2026', true)).toBeNull()
		expect(fromDateInput('2026-13-01', true)).toBeNull()
		expect(fromDateInput('2026-10-32', true)).toBeNull()
	})

	it('rejects an impossible time', () => {
		expect(fromDateInput('2026-10-30', false, '25:00')).toBeNull()
		expect(fromDateInput('2026-10-30', false, '12:60')).toBeNull()
	})
})
