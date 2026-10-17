import type { ModuleState } from '../types/index.ts'

import { loadState } from '@nextcloud/initial-state'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

/**
 * Which modules this user has (§13).
 *
 * The page hands the state over as initial state, so the app knows what to
 * draw before it has made a single request. `core` and `time` are always on,
 * and an unknown module is off.
 */
export const useModulesStore = defineStore('modules', () => {
	const state = ref<ModuleState>(read())

	function read(): ModuleState {
		try {
			return loadState<ModuleState>('chronos', 'modules', {})
		} catch {
			// A page without initial state — a test, or the dashboard widget —
			// gets the core app and nothing optional.
			return {}
		}
	}

	function enabled(module: string): boolean {
		return state.value[module] === true
	}

	const projects = computed(() => enabled('projects'))
	const workflow = computed(() => enabled('workflow'))
	const kanban = computed(() => enabled('kanban'))
	const gantt = computed(() => enabled('gantt'))
	const roadmap = computed(() => enabled('roadmap'))

	return { state, enabled, projects, workflow, kanban, gantt, roadmap }
})
