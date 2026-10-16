import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { loadState } from '@nextcloud/initial-state'
import type { ModuleName } from '../types/index.ts'
import { browserTimezone } from '../api/time.ts'

/**
 * Which modules this user sees (§13), handed over with the page so the
 * navigation can be drawn without waiting for a request.
 *
 * Only `core` and `time` exist so far; the rest arrive with their milestones.
 */
export const useSettingsStore = defineStore('settings', () => {
	const modules = ref<Partial<Record<ModuleName, boolean>>>(
		loadState<Partial<Record<ModuleName, boolean>>>('chronos', 'modules', { core: true, time: true }),
	)
	const timezone = ref<string>(loadState<string>('chronos', 'timezone', browserTimezone()))

	const enabled = computed(() => (module: ModuleName) => modules.value[module] === true)

	return { modules, timezone, enabled }
})
