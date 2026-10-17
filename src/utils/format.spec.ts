import { describe, expect, it } from 'vitest'
import { asSeconds } from '../api/time.ts'
import { formatClock, formatDate, formatDuration, formatWeekday } from './format.ts'

describe('formatDuration', () => {
	it('shows seconds under a minute', () => {
		expect(formatDuration(45)).toBe('45s')
	})

	it('shows whole minutes under an hour', () => {
		expect(formatDuration(1500)).toBe('25m')
	})

	it('shows hours and minutes', () => {
		expect(formatDuration(3 * 3600 + 25 * 60)).toBe('3h 25m')
	})

	it('leaves out the minutes when there are none', () => {
		expect(formatDuration(2 * 3600)).toBe('2h')
	})

	it('treats a negative duration as zero', () => {
		expect(formatDuration(-10)).toBe('0s')
	})
})

describe('formatClock', () => {
	it('pads every part', () => {
		expect(formatClock(3661)).toBe('01:01:01')
	})

	it('counts past a day rather than wrapping', () => {
		expect(formatClock(25 * 3600)).toBe('25:00:00')
	})

	it('starts at zero', () => {
		expect(formatClock(0)).toBe('00:00:00')
		expect(formatClock(-5)).toBe('00:00:00')
	})
})

/**
 * An all-day date is midnight UTC and is not an instant (§8.4): reading one in
 * the reader's own zone shows the day before for everybody west of Greenwich,
 * which is what D40 was.
 */
describe('formatDate', () => {
	const allDay = asSeconds(Date.parse('2026-10-30T00:00:00Z') / 1000)

	it('says the date an all-day date is, whatever zone the reader is in', () => {
		// Read in UTC, which is the frame it is stored in — so the 30th is the
		// 30th in Madrid and in New York. Reading it locally would show the
		// 29th for everybody west of Greenwich.
		expect(formatDate(allDay, true)).toBe(new Date(allDay * 1000).toLocaleDateString([], {
			day: '2-digit',
			month: 'short',
			year: 'numeric',
			timeZone: 'UTC',
		}))
		expect(formatWeekday(allDay, true)).toBe(new Date(allDay * 1000).toLocaleDateString([], { weekday: 'short', timeZone: 'UTC' }))
		// 30 October 2026 is a Friday, wherever you read it from.
		expect(formatDate(allDay, true)).toContain('30')
	})

	it('reads a timed date in the reader\'s own zone, which is what it means', () => {
		const noon = asSeconds(Date.parse('2026-10-30T12:00:00Z') / 1000)

		expect(formatDate(noon)).toBe(new Date(noon * 1000).toLocaleDateString([], { day: '2-digit', month: 'short', year: 'numeric' }))
	})
})
