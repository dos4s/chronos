<script setup lang="ts">
import type { StatusChange, Task, TaskType, TaskWritePayload } from '../types/index.ts'

import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { computed, reactive, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { messageFor } from '../api/client.ts'
import { fetchHistory } from '../api/workflows.ts'
import { useCurrentWorkflow } from '../composables/useCurrentWorkflow.ts'
import { useWorkflowsStore } from '../stores/workflows.ts'
import { fromDateInput, toDateInput, toTimeInput } from '../utils/dateInput.ts'
import { formatDate, formatTimeOfDay } from '../utils/format.ts'

const props = defineProps<{
	task: Task
	/** False where the view has nothing to delete into, like a read-only list. */
	deletable?: boolean
}>()

const emit = defineEmits<{
	close: []
	save: [changes: Partial<TaskWritePayload>]
	/** The view asks to be sure and does the deleting; this only asks for it. */
	remove: []
}>()

const workflows = useWorkflowsStore()
const { workflowId, statuses } = useCurrentWorkflow()

const history = ref<StatusChange[]>([])
const loadingHistory = ref(false)

const currentStatus = computed(() => workflows.statusOf(props.task, workflowId.value))

const form = reactive({
	summary: '',
	startDate: '',
	startTime: '',
	startAllDay: true,
	dueDate: '',
	dueTime: '',
	dueAllDay: true,
	priority: 0,
	percent: '' as number | '',
	type: 'task' as TaskType,
	statusKey: '',
	comment: '',
})

/**
 * Whether the status the user picked needs a note. The server has the last
 * word; this only asks for one before the write rather than after it.
 */
const transition = computed(() => {
	const from = currentStatus.value
	const to = statuses.value.find((status) => status.key === form.statusKey)
	if (from === undefined || from === null || to === undefined || from.id === to.id) {
		return null
	}
	return workflows.find(workflowId.value)?.transitions
		.find((rule) => rule.fromStatusId === from.id && rule.toStatusId === to.id) ?? null
})

const needsComment = computed(() => transition.value?.requiresComment === true)

watch(() => props.task, (task) => {
	form.summary = task.summary ?? ''
	form.startAllDay = task.startIsDate
	form.startDate = task.startAt === null ? '' : toDateInput(task.startAt, task.startIsDate)
	form.startTime = task.startAt === null || task.startIsDate ? '09:00' : toTimeInput(task.startAt)

	form.dueAllDay = task.dueIsDate
	form.dueDate = task.dueAt === null ? '' : toDateInput(task.dueAt, task.dueIsDate)
	form.dueTime = task.dueAt === null || task.dueIsDate ? '17:00' : toTimeInput(task.dueAt)

	form.priority = task.priority
	form.percent = task.percent ?? ''
	form.type = task.type
	form.statusKey = workflows.statusOf(task, workflowId.value)?.key ?? ''
	form.comment = ''

	void loadHistory(task.uid)
}, { immediate: true })

async function loadHistory(uid: string): Promise<void> {
	history.value = []
	if (workflowId.value === null) {
		return
	}

	loadingHistory.value = true
	try {
		history.value = await fetchHistory(uid)
	} catch (error) {
		showError(messageFor(error))
	} finally {
		loadingHistory.value = false
	}
}

function statusName(key: string | null): string {
	if (key === null) {
		return t('chronos', 'No status')
	}
	return statuses.value.find((status) => status.key === key)?.name ?? key
}

function changeLabel(change: StatusChange): string {
	if (change.fromKey !== change.toKey) {
		return t('chronos', '{from} → {to}', {
			from: statusName(change.fromKey),
			to: statusName(change.toKey),
		})
	}
	if (change.fromAssignee !== change.toAssignee) {
		return change.toAssignee === null
			? t('chronos', 'Unassigned')
			: t('chronos', 'Assigned to {user}', { user: change.toAssignee })
	}
	return statusName(change.toKey)
}

function changeWhen(change: StatusChange): string {
	return `${formatDate(change.changedAt)} ${formatTimeOfDay(change.changedAt)}`
}

function save() {
	const startAt = form.startDate === ''
		? null
		: fromDateInput(form.startDate, form.startAllDay, form.startTime)
	const dueAt = form.dueDate === ''
		? null
		: fromDateInput(form.dueDate, form.dueAllDay, form.dueTime)

	const changes: Partial<TaskWritePayload> = {
		summary: form.summary.trim(),
		startAt,
		startIsDate: form.startAllDay,
		dueAt,
		dueIsDate: form.dueAllDay,
		priority: Number(form.priority) || 0,
		percent: form.percent === '' ? null : Number(form.percent),
		type: form.type,
	}

	if (workflowId.value !== null && form.statusKey !== '') {
		changes.statusKey = form.statusKey
		if (form.comment.trim() !== '') {
			changes.comment = form.comment.trim()
		}
	}

	emit('save', changes)
}
</script>

<template>
	<!--
		NcDialog, not NcModal: the modal paints its title bar across the whole
		window, so "Task details" was drawn over Nextcloud's own top bar
		(§15.11). A dialog keeps its name in its own header.
	-->
	<NcDialog
		:name="t('chronos', 'Task details')"
		:open="true"
		size="normal"
		@update:open="emit('close')">
		<div :class="$style.content">
			<label :class="$style.field">
				<span :class="$style.legend">{{ t('chronos', 'Name') }}</span>
				<input
					v-model="form.summary"
					type="text"
					:placeholder="t('chronos', 'Untitled task')"
					@keydown.enter="save">
			</label>

			<label v-if="workflowId !== null" :class="$style.field">
				<span :class="$style.legend">{{ t('chronos', 'Status') }}</span>
				<select v-model="form.statusKey">
					<option v-for="status in statuses" :key="status.key" :value="status.key">
						{{ status.name }}
					</option>
				</select>
				<small v-if="currentStatus">
					{{ t('chronos', 'Other apps see this as {status}', { status: currentStatus.vtodoStatus }) }}
				</small>
			</label>

			<label v-if="needsComment" :class="$style.field">
				<span :class="$style.legend">{{ t('chronos', 'Note') }}</span>
				<input
					v-model="form.comment"
					type="text"
					:placeholder="t('chronos', 'This change needs a note')">
			</label>

			<fieldset :class="$style.group">
				<legend :class="$style.legend">
					{{ t('chronos', 'Starts') }}
				</legend>
				<div :class="$style.dateRow">
					<input v-model="form.startDate" type="date" :aria-label="t('chronos', 'Start date')">
					<input
						v-if="!form.startAllDay && form.startDate !== ''"
						v-model="form.startTime"
						type="time"
						:aria-label="t('chronos', 'Start time')">
					<label :class="$style.allDay">
						<input v-model="form.startAllDay" type="checkbox">
						{{ t('chronos', 'All day') }}
					</label>
				</div>
			</fieldset>

			<fieldset :class="$style.group">
				<legend :class="$style.legend">
					{{ t('chronos', 'Due') }}
				</legend>
				<div :class="$style.dateRow">
					<input v-model="form.dueDate" type="date" :aria-label="t('chronos', 'Due date')">
					<input
						v-if="!form.dueAllDay && form.dueDate !== ''"
						v-model="form.dueTime"
						type="time"
						:aria-label="t('chronos', 'Due time')">
					<label :class="$style.allDay">
						<input v-model="form.dueAllDay" type="checkbox">
						{{ t('chronos', 'All day') }}
					</label>
				</div>
			</fieldset>

			<div :class="$style.grid">
				<label :class="$style.field">
					<span :class="$style.legend">{{ t('chronos', 'Priority') }}</span>
					<select v-model.number="form.priority">
						<option :value="0">{{ t('chronos', 'None') }}</option>
						<option v-for="level in [1, 2, 3, 4, 5, 6, 7, 8, 9]" :key="level" :value="level">
							{{ level }}
						</option>
					</select>
					<small>{{ t('chronos', '1 is the highest') }}</small>
				</label>

				<label :class="$style.field">
					<span :class="$style.legend">{{ t('chronos', 'Progress') }}</span>
					<div :class="$style.suffixed">
						<input
							v-model="form.percent"
							type="number"
							min="0"
							max="100"
							step="5">
						<span :class="$style.suffix">%</span>
					</div>
				</label>

				<label :class="$style.field">
					<span :class="$style.legend">{{ t('chronos', 'Kind') }}</span>
					<select v-model="form.type">
						<option value="task">{{ t('chronos', 'Task') }}</option>
						<option value="subproject">{{ t('chronos', 'Subproject') }}</option>
						<option value="milestone">{{ t('chronos', 'Milestone') }}</option>
					</select>
				</label>
			</div>

			<section v-if="workflowId !== null" :class="$style.history">
				<h4 :class="$style.legend">
					{{ t('chronos', 'History') }}
				</h4>
				<p v-if="loadingHistory" :class="$style.empty">
					{{ t('chronos', 'Loading…') }}
				</p>
				<p v-else-if="history.length === 0" :class="$style.empty">
					{{ t('chronos', 'Nothing has happened to this task yet.') }}
				</p>
				<ul v-else :class="$style.changes">
					<li v-for="change in history" :key="change.id">
						<span :class="$style.changeWhat">{{ changeLabel(change) }}</span>
						<span :class="$style.changeWhen">
							{{ changeWhen(change) }} ·
							{{ change.source === 'external' ? t('chronos', 'another app') : change.changedBy }}
						</span>
						<span v-if="change.comment" :class="$style.changeNote">{{ change.comment }}</span>
					</li>
				</ul>
			</section>

			<div :class="$style.actions">
				<NcButton v-if="deletable" variant="error" @click="emit('remove')">
					{{ t('chronos', 'Delete') }}
				</NcButton>
				<div :class="$style.spacer" />
				<NcButton variant="tertiary" @click="emit('close')">
					{{ t('chronos', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" @click="save">
					{{ t('chronos', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<style module>
.content {
	padding: var(--chronos-space-6);
	min-width: min(560px, 90vw);
	display: flex;
	flex-direction: column;
	gap: var(--chronos-space-4);
}

/*
 * Nextcloud gives every input a width of 130px through a selector with an
 * element in it, which outranks a class (§15.10) — so the name field in this
 * dialog was a third the width of the dialog and scrolled the name it held.
 * These are qualified the same way, and fill their column.
 */
.content input[type="text"],
.content input[type="number"],
.content input[type="date"],
.content input[type="time"],
.content select {
	width: 100%;
	min-height: var(--chronos-control);
	margin: 0;
}

.content input[type="checkbox"] {
	width: auto;
	min-height: 0;
}

.spacer {
	flex: 1;
}

.group {
	border: none;
	padding: 0;
	margin: 0;
}

/* Sentence case at the app's small size: a label is read, not shouted. */
.legend {
	font-weight: 600;
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
	padding: 0;
}

.dateRow {
	display: flex;
	align-items: center;
	gap: var(--chronos-space-2);
	flex-wrap: wrap;
	margin-top: var(--chronos-space-1);
}

/* The date takes the room; the time and the all-day switch take what they need. */
.dateRow > :first-child {
	flex: 1 1 160px;
}

.dateRow input[type="time"] {
	flex: 0 1 140px;
}

.allDay {
	display: flex;
	align-items: center;
	gap: var(--chronos-space-2);
	font-size: var(--chronos-text-small);
	white-space: nowrap;
}

.grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: var(--chronos-space-3);
	align-items: start;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: var(--chronos-text-small);
}

.field small {
	color: var(--color-text-maxcontrast);
	font-size: var(--chronos-text-small);
}

/* A number with its unit beside it, inside the field's own box. */
.suffixed {
	display: flex;
	align-items: center;
	gap: var(--chronos-space-2);
}

.suffix {
	color: var(--color-text-maxcontrast);
	font-size: var(--chronos-text-small);
}

.history {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
	max-height: 220px;
	overflow-y: auto;
}

.empty {
	margin: 6px 0 0;
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
}

.changes {
	list-style: none;
	padding: 0;
	margin: 8px 0 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.changeWhat {
	display: block;
	font-size: var(--chronos-text-small);
}

.changeWhen {
	display: block;
	font-size: var(--chronos-text-small);
	color: var(--color-text-maxcontrast);
}

.changeNote {
	display: block;
	font-size: var(--chronos-text-small);
	font-style: italic;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
</style>
