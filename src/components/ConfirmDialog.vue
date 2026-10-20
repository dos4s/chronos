<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

/**
 * The question asked before something cannot be taken back.
 *
 * Deleting a task deletes the VTODO, which is gone from the Tasks app and off
 * the phone too — there is no undo for it the way there is for a move (§15.6).
 * Every view that can delete asks this first, in the same words, so the answer
 * is never a surprise.
 */
defineProps<{
	name: string
	message: string
	/** What the button that goes ahead says; *Delete* unless told otherwise. */
	confirm?: string
	/** A write is in flight. */
	busy?: boolean
}>()

const emit = defineEmits<{ close: [], confirm: [] }>()
</script>

<template>
	<NcDialog
		:name="name"
		:open="true"
		size="small"
		@update:open="emit('close')">
		<div :class="$style.body">
			<p :class="$style.message">
				{{ message }}
			</p>
			<div :class="$style.actions">
				<NcButton variant="tertiary" @click="emit('close')">
					{{ t('chronos', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy" @click="emit('confirm')">
					{{ confirm || t('chronos', 'Delete') }}
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

.message {
	margin: 0;
	font-size: var(--chronos-text);
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
