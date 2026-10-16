import type { ModuleName } from '../types/index.ts'
import { get, put } from './client.ts'

interface ModulesResponse {
	modules: Record<ModuleName, boolean>
	requirements: Record<string, string[]>
}

export const fetchModules = () => get<ModulesResponse>('/settings/modules')

export const setModule = (module: ModuleName, enabled: boolean) =>
	put<{ modules: Record<ModuleName, boolean> }>(`/settings/modules/${module}`, { enabled })
