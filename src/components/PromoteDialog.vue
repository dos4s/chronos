<script setup lang="ts">
import type { TaskNode } from '../utils/tasks.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { messageFor } from '../api/client.ts'
import { promoteSubproject } from '../api/projects.ts'
import { countOpen } from '../utils/tasks.ts'

/**
 * Turning a subproject into a list of its own (F2, §6).
 *
 * It is asked for rather than done, because it is the one action in Chronos
 * that moves somebody's tasks between lists: the tasks keep their UIDs, so
 * their history, their dependencies and their time sessions follow them, but
 * every CalDAV client will see them arrive somewhere new. The dialog says how
 * many are moving and what stays behind before it does anything.
 */
const props = defineProps<{ node: TaskNode, project: string }>()
const emit = defineEmits<{ close: [], promoted: [] }>()

const name = ref(props.node.task.summary ?? '')
const saving = ref(false)

/** Everything under the container, however deep: that is what moves. */
const branch = computed(() => {
	const all: TaskNode[] = []
	const walk = (nodes: TaskNode[]) => {
		for (const node of nodes) {
			all.push(node)
			walk(node.children)
		}
	}
	walk(props.node.children)

	return all
})

const open = computed(() => countOpen(branch.value.map((node) => node.task)))

async function promote(): Promise<void> {
	saving.value = true
	try {
		const result = await promoteSubproject(props.project, props.node.task.uid, name.value.trim() || null)

		if (result.left > 0) {
			// One copy and one delete per task, with no transaction: say how
			// far it got rather than pretending it finished.
			showError(t('chronos', '{moved} tasks moved and {left} did not. Promoting it again moves the rest.', {
				moved: result.moved,
				left: result.left,
			}))
		} else {
			showSuccess(n('chronos', 'Moved %n task into the new list', 'Moved %n tasks into the new list', result.moved))
		}

		emit('promoted')
	} catch (error) {
		showError(messageFor(error))
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<NcDialog
		:name="t('chronos', 'Promote to a list')"
		:open="true"
		size="small"
		@update:open="emit('close')">
		<div :class="$style.body">
			<p :class="$style.lead">
				{{ n('chronos',
					'“{name}” and the %n task in it become a list of their own.',
					'“{name}” and the %n tasks in it become a list of their own.',
					branch.length,
					{ name: node.task.summary || t('chronos', 'Untitled task') }) }}
			</p>

			<label :class="$style.field">
				<span :class="$style.label">{{ t('chronos', 'Name of the new list') }}</span>
				<input
					v-model="name"
					type="text"
					maxlength="100"
					:placeholder="node.task.summary ?? t('chronos', 'Promoted list')">
			</label>

			<ul :class="$style.facts">
				<li>{{ t('chronos', 'The tasks keep their history, their dependencies and the time logged against them.') }}</li>
				<li>{{ t('chronos', 'The new list remembers which project it came out of.') }}</li>
				<li>{{ t('chronos', 'The tasks that were directly inside it stop being subtasks: the list is what they are in now.') }}</li>
				<li v-if="open > 0">
					{{ n('chronos', '%n of them is still open.', '%n of them are still open.', open) }}
				</li>
			</ul>

			<NcNoteCard type="warning">
				{{ t('chronos', 'This moves the tasks in your calendar, one at a time, and cannot be undone with one click: moving them back would be the same operation in reverse. Everything else about them is kept.') }}
			</NcNoteCard>

			<div :class="$style.actions">
				<NcButton variant="tertiary" @click="emit('close')">
					{{ t('chronos', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving || branch.length === 0"
					@click="promote">
					{{ t('chronos', 'Promote') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<style module>
.body {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
}

.lead {
	margin: 0;
	font-size: var(--chronos-text);
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.label {
	font-size: var(--chronos-text-small);
	font-weight: 600;
}

.facts {
	margin: 0;
	padding-inline-start: 18px;
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
