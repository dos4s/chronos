import type { TaskList } from '../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { messageFor } from '../api/client.ts'
import { fetchTaskLists } from '../api/taskLists.ts'
import { useTimeStore } from './time.ts'

/**
 * The user's Tasks lists, which Chronos shows as projects, plus the sidebar's
 * filter selection. An empty selection means "everything".
 */
export const useProjectsStore = defineStore('projects', () => {
	const taskLists = ref<TaskList[]>([])
	const filters = ref<string[]>([])

	async function refresh(): Promise<void> {
		try {
			taskLists.value = await fetchTaskLists()
		} catch (error) {
			showError(messageFor(error))
		}
	}

	function colorFor(uri: string | null): string | null {
		if (uri === null) {
			return null
		}
		return taskLists.value.find((list) => list.uri === uri)?.color ?? null
	}

	function nameFor(uri: string | null): string | null {
		if (uri === null) {
			return null
		}
		return taskLists.value.find((list) => list.uri === uri)?.name ?? null
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

	/** Only the lists the user has actually tracked time on. */
	const tracked = computed(() => {
		const time = useTimeStore()
		const used = new Set(time.sessions
			.map((session) => session.projectUri)
			.filter((uri): uri is string => uri !== null))
		return taskLists.value.filter((list) => used.has(list.uri))
	})

	return { taskLists, filters, tracked, refresh, colorFor, nameFor, isSelected, toggle, matches }
})
