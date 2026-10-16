<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { useTasksStore } from '../stores/tasks.ts'

const props = defineProps<{ parentUid?: string | null, placeholder?: string }>()
const emit = defineEmits<{ added: [] }>()

const tasks = useTasksStore()
const summary = ref('')

async function add() {
	const value = summary.value.trim()
	if (value === '') {
		return
	}

	if (await tasks.create(value, props.parentUid ?? null)) {
		summary.value = ''
		emit('added')
	}
}
</script>

<template>
	<div :class="$style.composer">
		<input
			v-model="summary"
			type="text"
			:class="$style.input"
			:placeholder="placeholder || t('chronos', 'Add a task…')"
			:aria-label="placeholder || t('chronos', 'Add a task')"
			:disabled="tasks.loading"
			@keydown.enter="add">
		<NcButton variant="primary" :disabled="tasks.loading || !summary.trim()" @click="add">
			<template v-if="tasks.loading" #icon>
				<NcLoadingIcon :size="20" />
			</template>
			{{ t('chronos', 'Add') }}
		</NcButton>
	</div>
</template>

<style module>
.composer {
	display: flex;
	gap: 8px;
	align-items: center;
}

.input {
	flex: 1;
	min-width: 0;
	padding: 10px 16px;
	font-size: 15px;
	color: var(--color-main-text);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 999px;
	outline: none;
}

.input:focus {
	border-color: var(--color-primary-element);
}
</style>
