import { describe, expect, it } from 'vitest'
import { formatClock, formatDuration } from './format.ts'

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
