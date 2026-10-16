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
import { areaOf, groupByArea, labelProjects } from '../utils/projects.ts'
import { useTimeStore } from './time.ts'

/**
 * The user's projects — their Tasks lists, with what Chronos knows about each —
 * their areas, and the sidebar's filter selection. An empty selection means
 * "everything".
 */
export const useProjectsStore = defineStore('projects', () => {
	const projects = ref<Project[]>([])
	const areas = ref<Area[]>([])
	const filters = ref<string[]>([])

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

	/** Projects by area, areas in the user's order, "No area" last. */
	const grouped = computed(() => groupByArea(projects.value, areas.value))

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

	function isSelected(uri: string): boolean {
		return filters.value.length === 0 || filters.value.includes(uri)
	}

	function toggle(uri: string): void {
		const index = filters.value.indexOf(uri)
		if (index > -1) {
			filters.value.splice(index, 1)
			return
		}
		filters.value.push(uri)
	}

	function matches(uri: string | null): boolean {
		if (filters.value.length === 0) {
			return true
		}
		return uri !== null && filters.value.includes(uri)
	}

	/** Only the projects the user has actually tracked time on. */
	const tracked = computed(() => {
		const time = useTimeStore()
		const used = new Set(time.sessions
			.map((session) => session.projectUri)
			.filter((uri): uri is string => uri !== null))
		return projects.value.filter((project) => used.has(project.uri))
	})

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
		filters,
		active,
		all,
		grouped,
		tracked,
		refresh,
		areaFor,
		find,
		colorFor,
		nameFor,
		isSelected,
		toggle,
		matches,
		configure,
		addArea,
		renameArea,
		removeArea,
	}
})
