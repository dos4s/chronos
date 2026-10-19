import { describe, expect, it } from 'vitest'
import { asSeconds } from '../api/time.ts'
import { ageInDays, ageLabel, ageLevel, ageTitle } from './age.ts'

const DAY = 86400
const NOW = asSeconds(1_700_000_000)

describe('work item age', () => {
	it('counts whole days since the card arrived', () => {
		expect(ageInDays(NOW - 3 * DAY, NOW)).toBe(3)
		expect(ageInDays(NOW - 3 * DAY - 3600, NOW)).toBe(3)
		expect(ageInDays(NOW, NOW)).toBe(0)
	})

	/** Every card whose last move predates 0.5.8 is in this position. */
	it('has no age when nothing recorded the arrival', () => {
		expect(ageInDays(null, NOW)).toBeNull()
		expect(ageInDays(undefined, NOW)).toBeNull()
		expect(ageInDays(0, NOW)).toBeNull()
	})

	it('refuses to call a clock disagreement an age', () => {
		expect(ageInDays(NOW + 5 * DAY, NOW)).toBeNull()
	})

	it('says nothing at all in the first week', () => {
		expect(ageLevel(0)).toBe(0)
		expect(ageLevel(6)).toBe(0)
		expect(ageLevel(null)).toBe(0)
	})

	it('climbs one level per threshold and stops at three', () => {
		expect(ageLevel(7)).toBe(1)
		expect(ageLevel(13)).toBe(1)
		expect(ageLevel(14)).toBe(2)
		expect(ageLevel(21)).toBe(3)
		expect(ageLevel(400)).toBe(3)
	})

	it('takes the thresholds it is given', () => {
		expect(ageLevel(4, [3, 5, 8])).toBe(1)
		expect(ageLevel(9, [3, 5, 8])).toBe(3)
	})

	it('writes days up to a week and weeks after that', () => {
		expect(ageLabel(0)).toBe('0d')
		expect(ageLabel(6)).toBe('6d')
		expect(ageLabel(7)).toBe('1w')
		expect(ageLabel(20)).toBe('2w')
		expect(ageLabel(null)).toBeNull()
	})

	it('spells the badge out for a title', () => {
		expect(ageTitle(3)).toContain('3')
		expect(ageTitle(null)).toBeNull()
	})
})
