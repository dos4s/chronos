<script setup lang="ts">
import type { ModuleState } from './types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { messageFor } from './api/client.ts'
import * as api from './api/settings.ts'

/**
 * The two faces of §13: an admin decides which modules this instance offers,
 * each user which of those they see. One component, because the two pages
 * differ in what they write and in nothing else.
 */

const props = defineProps<{ scope: 'admin' | 'personal' }>()

const modules = ref<ModuleState>({})
const implemented = ref<ModuleState>({})
const available = ref<ModuleState>({})
const requirements = ref<Record<string, string[]>>({})
const loading = ref(true)
const saving = ref<string | null>(null)

/** The names the user reads, rather than the keys the API speaks in. */
const names: Record<string, string> = {
	projects: t('chronos', 'Projects and areas'),
	workflow: t('chronos', 'Workflows and statuses'),
	kanban: t('chronos', 'Kanban board'),
	fields: t('chronos', 'Custom fields'),
	sla: t('chronos', 'Service levels'),
	gantt: t('chronos', 'Gantt'),
	roadmap: t('chronos', 'Roadmap'),
	sprints: t('chronos', 'Sprints'),
	matrix: t('chronos', 'Eisenhower matrix'),
}

const order = ['projects', 'workflow', 'kanban', 'fields', 'sla', 'gantt', 'roadmap', 'sprints', 'matrix']

const rows = computed(() => order.map((module) => ({
	module,
	name: names[module] ?? module,
	enabled: modules.value[module] === true,
	/** Admin: not built yet. User: the instance does not offer it. */
	blocked: props.scope === 'admin'
		? implemented.value[module] !== true
		: available.value[module] !== true,
	requires: (requirements.value[module] ?? []).map((required) => names[required] ?? required),
})))

onMounted(load)

async function load(): Promise<void> {
	loading.value = true
	try {
		if (props.scope === 'admin') {
			const state = await api.fetchAdminModules()
			modules.value = state.modules
			implemented.value = state.implemented
			requirements.value = state.requirements
		} else {
			const state = await api.fetchUserModules()
			modules.value = state.modules
			available.value = state.available
			requirements.value = state.requirements
		}
	} catch (error) {
		showError(messageFor(error))
	} finally {
		loading.value = false
	}
}

async function toggle(module: string, enabled: boolean): Promise<void> {
	saving.value = module
	try {
		const response = props.scope === 'admin'
			? await api.setAdminModule(module, enabled)
			: await api.setUserModule(module, enabled)
		modules.value = response.modules
	} catch (error) {
		showError(messageFor(error))
		// Whatever the server says is the truth, including a refusal.
		await load()
	} finally {
		saving.value = null
	}
}
</script>

<template>
	<div :class="$style.panel">
		<h2 :class="$style.heading">
			{{ t('chronos', 'Chronos modules') }}
		</h2>

		<NcNoteCard type="info">
			<span v-if="scope === 'admin'">
				{{ t('chronos', 'What this instance offers. A module switched off keeps its data, so switching it back on loses nothing.') }}
			</span>
			<span v-else>
				{{ t('chronos', 'Which of the modules this instance offers you want to see. Time tracking is always on.') }}
			</span>
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="24" />

		<ul v-else :class="$style.list">
			<li v-for="row in rows" :key="row.module" :class="$style.row">
				<label :class="$style.label">
					<input
						type="checkbox"
						:checked="row.enabled"
						:disabled="row.blocked || saving === row.module"
						@change="toggle(row.module, ($event.target as HTMLInputElement).checked)">
					<span>
						{{ row.name }}
						<small v-if="row.blocked && scope === 'admin'">
							{{ t('chronos', 'Not in this version yet') }}
						</small>
						<small v-else-if="row.blocked">
							{{ t('chronos', 'Not offered on this instance') }}
						</small>
						<small v-else-if="row.requires.length">
							{{ t('chronos', 'Needs: {modules}', { modules: row.requires.join(', ') }) }}
						</small>
					</span>
				</label>
			</li>
		</ul>
	</div>
</template>

<style module>
.panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 600px;
}

.heading {
	margin: 0;
	font-size: 1.1rem;
}

.list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.label {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	font-size: var(--chronos-text);
}

.label small {
	display: block;
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
}
</style>
