import type { Project } from '../types/index.ts'

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../api/client.ts', () => ({ messageFor: () => 'failed' }))
vi.mock('../api/projects.ts', () => ({
	fetchProjects: vi.fn(),
	fetchAreas: vi.fn(),
	configureProject: vi.fn(),
	createArea: vi.fn(),
	updateArea: vi.fn(),
	deleteArea: vi.fn(),
}))

const { useProjectsStore } = await import('./projects.ts')

function project(uri: string, name: string, archived = false): Project {
	return {
		uri,
		calendarUri: uri,
		name,
		color: null,
		shared: false,
		writable: true,
		areaId: null,
		parentUri: null,
		workflowId: null,
		archived,
		sort: 0,
	}
}

function loaded() {
	const store = useProjectsStore()
	store.projects = [
		project('work', 'Work'),
		project('home', 'Home'),
		project('old', 'Old', true),
	]
	return store
}

beforeEach(() => {
	setActivePinia(createPinia())
})

/**
 * The sidebar is the one place a project is chosen (§14.2), and it carries two
 * things that really are different: which projects are in scope, and which one
 * the board, the timeline and the task list are showing.
 */
describe('choosing a project', () => {
	it('starts with everything in scope and the first project current', () => {
		const store = loaded()

		expect(store.selection).toEqual([])
		expect(store.matches('work')).toBe(true)
		expect(store.matches('home')).toBe(true)
		// Alphabetical by label, which is the order the sidebar lists them in.
		expect(store.current).toBe('home')
	})

	it('narrows to one project and works in it', () => {
		const store = loaded()

		store.choose('work')

		expect(store.selection).toEqual(['work'])
		expect(store.current).toBe('work')
		expect(store.matches('home')).toBe(false)
	})

	/** Ctrl-click adds a project for the views that can show several. */
	it('adds a second project without changing which one is current', () => {
		const store = loaded()
		store.choose('work')

		store.toggle('home', true)

		expect(store.selection).toEqual(['work', 'home'])
		expect(store.current).toBe('work')
		expect(store.matches('home')).toBe(true)
	})

	it('takes one out again', () => {
		const store = loaded()
		store.choose('work')
		store.toggle('home', true)

		store.toggle('home', true)

		expect(store.selection).toEqual(['work'])
	})

	/**
	 * An empty selection means *everything*, which is not what unticking your
	 * only project means — so the last one cannot be unticked.
	 */
	it('will not empty the selection by unticking the last project', () => {
		const store = loaded()
		store.choose('work')

		store.toggle('work', true)

		expect(store.selection).toEqual(['work'])
	})

	it('goes back to everything when asked for all projects', () => {
		const store = loaded()
		store.choose('work')

		store.clearSelection()

		expect(store.selection).toEqual([])
		expect(store.matches('home')).toBe(true)
		// And keeps working in the project it was working in.
		expect(store.current).toBe('work')
	})

	/**
	 * Switching the board to another project should not narrow the analytics
	 * to it: what is in scope and what is on screen are different questions.
	 */
	it('can work in another project without changing the scope', () => {
		const store = loaded()
		store.choose('work')
		store.toggle('home', true)

		store.focus('home')

		expect(store.current).toBe('home')
		expect(store.selection).toEqual(['work', 'home'])
	})

	/** A project that leaves the scope cannot stay the one being shown. */
	it('falls back to something in scope when the current project leaves it', () => {
		const store = loaded()
		store.choose('work')
		store.toggle('home', true)
		store.focus('home')

		store.toggle('home', true)

		expect(store.current).toBe('work')
	})
})

describe('what the sidebar offers', () => {
	it('leaves the archived projects out until they are asked for', () => {
		const store = loaded()

		expect(store.offered.map((candidate) => candidate.uri)).toEqual(['home', 'work'])

		store.showArchived = true

		expect(store.offered.map((candidate) => candidate.uri)).toEqual(['home', 'old', 'work'])
	})

	/** With nothing selected, a view may switch between all of them. */
	it('offers every project to switch between when nothing is selected', () => {
		const store = loaded()

		expect(store.inScope.map((candidate) => candidate.uri)).toEqual(['home', 'work'])
	})

	it('offers only the selected ones when there is a selection', () => {
		const store = loaded()
		store.choose('work')

		expect(store.inScope.map((candidate) => candidate.uri)).toEqual(['work'])
	})

	/**
	 * A link into the timeline can name an archived project, and an archived
	 * project is not on offer — so asking to work in one shows them, rather
	 * than opening an empty view with nothing to say why.
	 */
	it('shows the archived projects when asked to work in one of them', () => {
		const store = loaded()

		store.choose('old')

		expect(store.showArchived).toBe(true)
		expect(store.current).toBe('old')
	})

	it('has no project to show at all when there are none', () => {
		const store = useProjectsStore()

		expect(store.current).toBeNull()
		expect(store.currentProject).toBeNull()
	})
})
