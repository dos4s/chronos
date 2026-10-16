import type { Task, TaskWritePayload } from '../types/index.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, messageFor } from '../api/client.ts'
import * as api from '../api/tasks.ts'
import { buildTaskTree, countOpen, toWritePayload } from '../utils/tasks.ts'

/** HTTP 412: the task changed since the edit was based on it (§11.1). */
const PRECONDITION_FAILED = 412

/**
 * The tasks of one project, read from the index and written to CalDAV.
 *
 * Every write carries the etag it was based on. When that etag has moved on,
 * the list is reloaded and the user is told, rather than one of the two edits
 * disappearing without a trace.
 */
export const useTasksStore = defineStore('tasks', () => {
	const tasks = ref<Task[]>([])
	const projectRef = ref<string | null>(null)
	const loading = ref(false)
	const initialLoading = ref(false)

	const tree = computed(() => buildTaskTree(tasks.value))
	const openCount = computed(() => countOpen(tasks.value))

	async function select(ref: string | null): Promise<void> {
		projectRef.value = ref
		tasks.value = []
		if (ref === null) {
			return
		}

		initialLoading.value = true
		await refresh()
		initialLoading.value = false
	}

	async function refresh(): Promise<void> {
		const ref = projectRef.value
		if (ref === null) {
			return
		}

		try {
			tasks.value = await api.fetchTasks(ref)
		} catch (error) {
			showError(messageFor(error))
		}
	}

	/**
	 * Runs a write and reloads. A 412 is not a failure of the request so much
	 * as news: someone else got there first, so show what the task says now.
	 */
	async function write(action: () => Promise<unknown>, success: string): Promise<boolean> {
		loading.value = true
		try {
			await action()
			await refresh()
			showSuccess(success)
			return true
		} catch (error) {
			showError(messageFor(error))
			if (error instanceof ApiError && error.status === PRECONDITION_FAILED) {
				await refresh()
			}
			return false
		} finally {
			loading.value = false
		}
	}

	function create(summary: string, parentUid: string | null = null): Promise<boolean> {
		const ref = projectRef.value
		if (ref === null) {
			return Promise.resolve(false)
		}

		const payload: TaskWritePayload = {
			summary,
			status: 'NEEDS-ACTION',
			priority: 0,
			startAt: null,
			startIsDate: true,
			dueAt: null,
			dueIsDate: true,
			percent: null,
			parentUid,
			type: 'task',
		}

		return write(() => api.createTask(ref, payload), t('chronos', 'Task added'))
	}

	function save(task: Task, changes: Partial<TaskWritePayload>): Promise<boolean> {
		return write(
			() => api.updateTask(task.uid, toWritePayload(task, changes), task.etag),
			t('chronos', 'Task saved'),
		)
	}

	function toggleComplete(task: Task): Promise<boolean> {
		const completed = task.vtodoStatus === 'COMPLETED'

		return save(task, {
			status: completed ? 'NEEDS-ACTION' : 'COMPLETED',
			percent: completed ? null : 100,
		})
	}

	function remove(task: Task): Promise<boolean> {
		return write(() => api.deleteTask(task.uid, task.etag), t('chronos', 'Task deleted'))
	}

	return {
		tasks,
		projectRef,
		loading,
		initialLoading,
		tree,
		openCount,
		select,
		refresh,
		create,
		save,
		toggleComplete,
		remove,
	}
})
