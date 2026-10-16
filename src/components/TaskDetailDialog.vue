<script setup lang="ts">
import type { Task, TaskType, TaskWritePayload } from '../types/index.ts'

import { t } from '@nextcloud/l10n'
import { reactive, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import { fromDateInput, toDateInput, toTimeInput } from '../utils/dateInput.ts'

const props = defineProps<{ task: Task }>()
const emit = defineEmits<{ close: [], save: [changes: Partial<TaskWritePayload>] }>()

const form = reactive({
	startDate: '',
	startTime: '',
	startAllDay: true,
	dueDate: '',
	dueTime: '',
	dueAllDay: true,
	priority: 0,
	percent: '' as number | '',
	type: 'task' as TaskType,
})

watch(() => props.task, (task) => {
	form.startAllDay = task.startIsDate
	form.startDate = task.startAt === null ? '' : toDateInput(task.startAt, task.startIsDate)
	form.startTime = task.startAt === null || task.startIsDate ? '09:00' : toTimeInput(task.startAt)

	form.dueAllDay = task.dueIsDate
	form.dueDate = task.dueAt === null ? '' : toDateInput(task.dueAt, task.dueIsDate)
	form.dueTime = task.dueAt === null || task.dueIsDate ? '17:00' : toTimeInput(task.dueAt)

	form.priority = task.priority
	form.percent = task.percent ?? ''
	form.type = task.type
}, { immediate: true })

function save() {
	const startAt = form.startDate === ''
		? null
		: fromDateInput(form.startDate, form.startAllDay, form.startTime)
	const dueAt = form.dueDate === ''
		? null
		: fromDateInput(form.dueDate, form.dueAllDay, form.dueTime)

	emit('save', {
		startAt,
		startIsDate: form.startAllDay,
		dueAt,
		dueIsDate: form.dueAllDay,
		priority: Number(form.priority) || 0,
		percent: form.percent === '' ? null : Number(form.percent),
		type: form.type,
	})
}
</script>

<template>
	<NcModal :name="t('chronos', 'Task details')" @close="emit('close')">
		<div :class="$style.content">
			<h3 :class="$style.title">
				{{ task.summary || t('chronos', 'Untitled task') }}
			</h3>

			<fieldset :class="$style.group">
				<legend :class="$style.legend">
					{{ t('chronos', 'Starts') }}
				</legend>
				<div :class="$style.dateRow">
					<input v-model="form.startDate" type="date" :aria-label="t('chronos', 'Start date')">
					<input
						v-if="!form.startAllDay"
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
						v-if="!form.dueAllDay"
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
					<small>{{ t('chronos', '1 is highest, as iCalendar defines it') }}</small>
				</label>

				<label :class="$style.field">
					<span :class="$style.legend">{{ t('chronos', 'Progress') }}</span>
					<input
						v-model="form.percent"
						type="number"
						min="0"
						max="100"
						step="5">
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

			<div :class="$style.actions">
				<NcButton variant="secondary" @click="emit('close')">
					{{ t('chronos', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" @click="save">
					{{ t('chronos', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<style module>
.content {
	padding: 24px;
	min-width: 380px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.title {
	margin: 0;
	font-size: 1.1rem;
}

.group {
	border: none;
	padding: 0;
	margin: 0;
}

.legend {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	padding: 0;
}

.dateRow {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 6px;
}

.allDay {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
}

.grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 12px;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 13px;
}

.field small {
	color: var(--color-text-maxcontrast);
	font-size: 11px;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
</style>
