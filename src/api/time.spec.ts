import { describe, expect, it } from 'vitest'
import { addDays, asSeconds, fromDate, startOfDay, toDate, toDateKey } from './time.ts'

describe('seconds conversion', () => {
	it('converts both ways without drifting', () => {
		const at = asSeconds(1_760_000_000)
		expect(fromDate(toDate(at))).toBe(at)
	})

	it('drops the millisecond part of a Date', () => {
		expect(fromDate(new Date(1_760_000_000_999))).toBe(1_760_000_000)
	})

	it('reads a Date as seconds, not milliseconds', () => {
		expect(toDate(asSeconds(1_760_000_000)).getTime()).toBe(1_760_000_000_000)
	})
})

describe('day arithmetic', () => {
	it('finds local midnight of the day a timestamp is in', () => {
		const noon = fromDate(new Date(2026, 9, 16, 12, 34, 56))
		expect(toDate(startOfDay(noon)).getHours()).toBe(0)
		expect(toDate(startOfDay(noon)).getDate()).toBe(16)
	})

	it('adds days by calendar, so a DST change cannot shift the date', () => {
		// 2026-10-25 is when European summer time ends.
		const before = fromDate(new Date(2026, 9, 24, 0, 0, 0))
		const after = addDays(before, 2)

		expect(toDate(after).getDate()).toBe(26)
		expect(toDate(after).getHours()).toBe(0)
	})

	it('steps backwards across a month boundary', () => {
		const first = fromDate(new Date(2026, 9, 1, 0, 0, 0))
		expect(toDateKey(addDays(first, -1))).toBe('2026-09-30')
	})

	it('formats a date key the way the server buckets days', () => {
		expect(toDateKey(fromDate(new Date(2026, 0, 5, 23, 59)))).toBe('2026-01-05')
	})
})
