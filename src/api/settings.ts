import type { ModuleState } from '../types/index.ts'

import { get, put } from './client.ts'

export interface UserModules {
	modules: ModuleState
	/** What the instance offers, which is what a user can choose from. */
	available: ModuleState
	requirements: Record<string, string[]>
}

export interface AdminModules {
	modules: ModuleState
	/** Modules this version of Chronos actually has. */
	implemented: ModuleState
	requirements: Record<string, string[]>
}

export const fetchUserModules = () => get<UserModules>('/settings/modules')

export function setUserModule(module: string, enabled: boolean) {
	return put<{ modules: ModuleState }>(`/settings/modules/${module}`, { enabled })
}

export const fetchAdminModules = () => get<AdminModules>('/settings/admin/modules')

export function setAdminModule(module: string, enabled: boolean) {
	return put<{ modules: ModuleState }>(`/settings/admin/modules/${module}`, { enabled })
}
