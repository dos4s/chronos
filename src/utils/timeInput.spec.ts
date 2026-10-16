import { describe, expect, it } from 'vitest'
import { fromDate, toDate } from '../api/time.ts'
import { fromTimeInput, toTimeInput } from './timeInput.ts'

const DAY = fromDate(new Date(2026, 9, 16, 9, 30))

describe('toTimeInput', () => {
	it('formats local wall-clock time, zero padded', () => {
		expect(toTimeInput(fromDate(new Date(2026, 9, 16, 7, 5)))).toBe('07:05')
	})

	it('formats midnight', () => {
		expect(toTimeInput(fromDate(new Date(2026, 9, 16, 0, 0)))).toBe('00:00')
	})
})

describe('fromTimeInput', () => {
	it('places the time on the session\'s own day', () => {
		const at = fromTimeInput('14:45', DAY)

		expect(at).not.toBeNull()
		expect(toDate(at!).getDate()).toBe(16)
		expect(toDate(at!).getHours()).toBe(14)
		expect(toDate(at!).getMinutes()).toBe(45)
	})

	it('zeroes the seconds, so a saved time is exact', () => {
		const at = fromTimeInput('14:45', fromDate(new Date(2026, 9, 16, 9, 30, 42)))

		expect(toDate(at!).getSeconds()).toBe(0)
	})

	it('accepts a single-digit hour', () => {
		expect(toTimeInput(fromTimeInput('9:05', DAY)!)).toBe('09:05')
	})

	it('rejects an empty or malformed value', () => {
		expect(fromTimeInput('', DAY)).toBeNull()
		expect(fromTimeInput('nope', DAY)).toBeNull()
		expect(fromTimeInput('12', DAY)).toBeNull()
	})

	it('rejects an impossible time', () => {
		expect(fromTimeInput('24:00', DAY)).toBeNull()
		expect(fromTimeInput('12:60', DAY)).toBeNull()
	})
})
