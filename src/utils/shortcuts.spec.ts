import { describe, expect, it } from 'vitest'
import { groupsFor, shortcutGroups } from './shortcuts.ts'

/**
 * The one list of what the app can be told to do (§15.7).
 *
 * What is worth pinning is not the wording but the shape: that the view you
 * are on is answered first, that the ones which work anywhere are never
 * hidden, and that every group has something in it — an empty section in a
 * help dialog is worse than no section.
 */
describe('the shortcut list', () => {
	it('has a group per drag view, and one for anywhere', () => {
		const routes = shortcutGroups().map((group) => group.route)

		expect(routes).toContain(null)
		expect(routes).toContain('board')
		expect(routes).toContain('timeline')
		expect(routes).toContain('editor')
	})

	it('never has an empty group', () => {
		for (const group of shortcutGroups()) {
			expect(group.shortcuts.length).toBeGreaterThan(0)
			expect(group.title).not.toBe('')
		}
	})

	it('says what every key does', () => {
		for (const group of shortcutGroups()) {
			for (const shortcut of group.shortcuts) {
				expect(shortcut.keys).not.toBe('')
				expect(shortcut.what).not.toBe('')
			}
		}
	})
})

describe('the order they are shown in', () => {
	it('answers the view you are on first', () => {
		expect(groupsFor('timeline')[0].route).toBe('timeline')
		expect(groupsFor('board')[0].route).toBe('board')
	})

	it('puts the ones that work anywhere next', () => {
		expect(groupsFor('board')[1].route).toBeNull()
	})

	it('starts with those when the view has none of its own', () => {
		expect(groupsFor('analytics')[0].route).toBeNull()
		expect(groupsFor(null)[0].route).toBeNull()
	})

	it('still lists every group, wherever you are', () => {
		expect(groupsFor('timeline')).toHaveLength(shortcutGroups().length)
		expect(groupsFor('nowhere-at-all')).toHaveLength(shortcutGroups().length)
	})
})
