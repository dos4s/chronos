<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'

const props = defineProps<{ x: number, y: number, initialStart: string }>()
const emit = defineEmits<{ close: [], add: [startTime: string, minutes: number] }>()

const startTime = ref(props.initialStart)
const minutes = ref(30)

// Opening the menu somewhere else starts from that block's default again.
watch(() => props.initialStart, (value) => {
	startTime.value = value
	minutes.value = 30
})
</script>

<template>
	<Teleport to="body">
		<div :class="$style.overlay" @click.self="emit('close')">
			<div :class="$style.menu" :style="{ top: props.y + 'px', left: props.x + 'px' }">
				<div :class="$style.title">
					{{ t('chronos', 'Add break') }}
				</div>
				<div :class="$style.row">
					<label for="chronos-break-start" :class="$style.label">
						{{ t('chronos', 'Start time') }}
					</label>
					<input
						id="chronos-break-start"
						v-model="startTime"
						type="time"
						:class="$style.input">
				</div>
				<div :class="$style.row">
					<label for="chronos-break-length" :class="$style.label">
						{{ t('chronos', 'Duration') }}
					</label>
					<select id="chronos-break-length" v-model.number="minutes" :class="$style.input">
						<option :value="15">
							{{ t('chronos', '15 min') }}
						</option>
						<option :value="30">
							{{ t('chronos', '30 min') }}
						</option>
						<option :value="45">
							{{ t('chronos', '45 min') }}
						</option>
						<option :value="60">
							{{ t('chronos', '60 min') }}
						</option>
					</select>
				</div>
				<div :class="$style.actions">
					<button :class="$style.cancel" @click="emit('close')">
						{{ t('chronos', 'Cancel') }}
					</button>
					<button :class="$style.confirm" @click="emit('add', startTime, minutes)">
						{{ t('chronos', 'Add break') }}
					</button>
				</div>
			</div>
		</div>
	</Teleport>
</template>

<style module>
.overlay {
	position: fixed;
	inset: 0;
	z-index: 9000;
}

.menu {
	position: fixed;
	z-index: 9001;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	width: 230px;
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.title {
	font-weight: 700;
	font-size: 14px;
	color: var(--color-main-text);
	padding-bottom: 4px;
	border-bottom: 1px solid var(--color-border);
}

.row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.input {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	padding: 4px 8px;
	flex: 1;
	min-width: 0;
	font-size: 13px;
}

.actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

/*
 * Qualified with the element on purpose: Nextcloud styles every plain button as
 * a filled pill through `button:not(.button-vue, …)`, which outranks a single
 * class, so a bespoke control styled by its class alone came out as a green box
 * (§15.10).
 */
button.cancel {
	min-height: 0;
	width: auto;
	padding: 6px 12px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 13px;
	font-weight: normal;
}

button.confirm {
	min-height: 0;
	width: auto;
	padding: 6px 12px;
	border-radius: var(--border-radius);
	border: none;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	cursor: pointer;
	font-weight: 600;
	font-size: 13px;
}
</style>
