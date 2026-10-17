import type { Area, Project, ProjectConfig } from '../types/index.ts'
import type { LabelledProject } from '../utils/projects.ts'

import { showError } from '@nextcloud/dialogs'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { messageFor } from '../api/client.ts'
import {
	configureProject,
	createArea,
	deleteArea,
	fetchAreas,
	fetchProjects,
	updateArea,
} from '../api/projects.ts'
import { areaOf, labelProjects } from '../utils/projects.ts'

/**
 * The user's projects — their Tasks lists, with what Chronos knows about each —
 * their areas, and **the one place a project is chosen**.
 *
 * There used to be two: a filter in the sidebar that only the time views read,
 * and a picker of its own in the task list, the board and the timeline. Two
 * controls for one idea, neither of them saying which it was. Now the sidebar
 * is the whole of it, and it carries two things that really are different:
 *
 * - **`selection`** — the projects in scope. Empty means everything, which is
 *   what the time views aggregate over and what the roadmap draws.
 * - **`current`** — the one project the single-project views show. A board's
 *   columns *are* one project's workflow statuses, a plan is one project's
 *   rows, and the composer writes into one list, so those three cannot be a
 *   set however much the sidebar would like them to be. It is the project that
 *   was last clicked, as long as it is still in scope.
 */
export const useProjectsStore = defineStore('projects', () => {
	const projects = ref<Project[]>([])
	const areas = ref<Area[]>([])
	/** The projects in scope; empty means every one of them. */
	const selection = ref<string[]>([])
	/** The last project clicked, which is what the single-project views show. */
	const chosen = ref<string | null>(null)
	/** Whether the sidebar offers the archived projects as well. */
	const showArchived = ref(false)

	async function refresh(): Promise<void> {
		try {
			const [loadedProjects, loadedAreas] = await Promise.all([
				fetchProjects(),
				fetchAreas(),
			])
			projects.value = loadedProjects
			areas.value = loadedAreas
		} catch (error) {
			showError(messageFor(error))
		}
	}

	function areaFor(project: Project): Area | null {
		return areaOf(project, areas.value)
	}

	/**
	 * Projects to work in: the archived ones are kept out of the pickers, and
	 * stay reachable by asking for them.
	 */
	const active = computed<LabelledProject[]>(() => labelProjects(projects.value.filter((project) => !project.archived), areas.value))

	const all = computed<LabelledProject[]>(() => labelProjects(projects.value, areas.value))

	function find(uri: string | null): Project | null {
		if (uri === null) {
			return null
		}
		return projects.value.find((project) => project.uri === uri) ?? null
	}

	function colorFor(uri: string | null): string | null {
		return find(uri)?.color ?? null
	}

	function nameFor(uri: string | null): string | null {
		return find(uri)?.name ?? null
	}

	/**
	 * Whether a project is in scope. With nothing selected everything is, which
	 * is why an unselected sidebar still shows every session in the analytics.
	 */
	function isSelected(uri: string): boolean {
		return selection.value.length === 0 || selection.value.includes(uri)
	}

	/**
	 * The projects the sidebar offers, which is what everything else is chosen
	 * from: the active ones, plus the archived if they have been asked for.
	 */
	const offered = computed<LabelledProject[]>(() => showArchived.value ? all.value : active.value)

	/**
	 * The projects a single-project view may switch between: the selection, or
	 * everything on offer when nothing is selected.
	 */
	const inScope = computed<LabelledProject[]>(() => selection.value.length === 0
		? offered.value
		: offered.value.filter((project) => selection.value.includes(project.uri)))

	/**
	 * The project the board, the timeline and the task list show: the one last
	 * clicked while it is still in scope, and otherwise the first that is.
	 */
	const current = computed<string | null>(() => {
		const picked = chosen.value
		if (picked !== null && inScope.value.some((project) => project.uri === picked)) {
			return picked
		}

		return inScope.value[0]?.uri ?? null
	})

	const currentProject = computed<LabelledProject | null>(() => inScope.value.find((project) => project.uri === current.value) ?? null)

	/** Whether this is *the* project — the one the board and timeline show. */
	function isCurrent(uri: string): boolean {
		return current.value === uri
	}

	/** Clicking a project: work in this one, and look at this one. */
	function choose(uri: string): void {
		selection.value = [uri]
		chosen.value = uri
	}

	/**
	 * Ctrl- or ⌘-clicking: add a project to the scope, or take it out again.
	 * The last one cannot be taken out by this route — an empty selection means
	 * everything*, which is not what unticking your only project means.
	 */
	function extend(uri: string): void {
		if (selection.value.includes(uri)) {
			if (selection.value.length > 1) {
				selection.value = selection.value.filter((kept) => kept !== uri)
			}
			return
		}

		selection.value = [...selection.value, uri]
		chosen.value ??= uri
	}

	/**
	 * Works in a project without changing what is in scope — what the header
	 * switcher does. Switching the board to another project the sidebar is
	 * already showing should not narrow the analytics to it.
	 */
	function focus(uri: string): void {
		chosen.value = uri
	}

	/** Either, depending on the modifier the click came with. */
	function toggle(uri: string, additive = false): void {
		(additive ? extend : choose)(uri)
	}

	/** *All projects*: the time views aggregate everything again. */
	function clearSelection(): void {
		selection.value = []
	}

	function matches(uri: string | null): boolean {
		if (selection.value.length === 0) {
			return true
		}
		return uri !== null && selection.value.includes(uri)
	}

	async function configure(uri: string, config: ProjectConfig): Promise<boolean> {
		try {
			const saved = await configureProject(uri, config)
			const index = projects.value.findIndex((project) => project.uri === uri)
			if (index > -1) {
				projects.value.splice(index, 1, saved)
			}
			return true
		} catch (error) {
			showError(messageFor(error))
			return false
		}
	}

	async function addArea(name: string, color: string | null): Promise<Area | null> {
		try {
			const area = await createArea(name, color)
			areas.value.push(area)
			return area
		} catch (error) {
			showError(messageFor(error))
			return null
		}
	}

	async function renameArea(id: number, name: string, color: string | null): Promise<boolean> {
		try {
			const saved = await updateArea(id, name, color)
			const index = areas.value.findIndex((area) => area.id === id)
			if (index > -1) {
				areas.value.splice(index, 1, saved)
			}
			return true
		} catch (error) {
			showError(messageFor(error))
			return false
		}
	}

	/** The projects in the area are not touched, only their area is cleared. */
	async function removeArea(id: number): Promise<boolean> {
		try {
			await deleteArea(id)
			areas.value = areas.value.filter((area) => area.id !== id)
			projects.value = projects.value.map((project) => project.areaId === id ? { ...project, areaId: null } : project)
			return true
		} catch (error) {
			showError(messageFor(error))
			return false
		}
	}

	return {
		projects,
		areas,
		selection,
		showArchived,
		active,
		all,
		offered,
		inScope,
		current,
		currentProject,
		refresh,
		areaFor,
		find,
		colorFor,
		nameFor,
		isSelected,
		isCurrent,
		choose,
		focus,
		toggle,
		clearSelection,
		matches,
		configure,
		addArea,
		renameArea,
		removeArea,
	}
})
