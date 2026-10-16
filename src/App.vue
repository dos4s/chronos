<script setup lang="ts">
import { computed, onMounted } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcContent from '@nextcloud/vue/components/NcContent'
import { t } from '@nextcloud/l10n'
import { useRoute } from 'vue-router'
import { useProjectsStore } from './stores/projects.ts'
import { useTimeStore } from './stores/time.ts'

const route = useRoute()
const time = useTimeStore()
const projects = useProjectsStore()

const wide = computed(() => route.name !== 'timer')

onMounted(() => {
	time.refresh()
	projects.refresh()
})
</script>

<template>
	<NcContent app-name="chronos">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationList>
					<NcAppNavigationItem
						id="view-timer"
						:name="t('chronos', 'Timer')"
						:to="{ name: 'timer' }">
						<template #icon>
							<svg viewBox="0 0 24 24"
								aria-hidden="true"
								fill="currentColor"
								width="16"
								height="16">
								<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm4.2 14.2L11 13V7h1.5v5.2l4.5 2.7-.8 1.3z" />
							</svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						id="view-analytics"
						:name="t('chronos', 'Analytics')"
						:to="{ name: 'analytics' }">
						<template #icon>
							<svg viewBox="0 0 24 24"
								aria-hidden="true"
								fill="currentColor"
								width="16"
								height="16">
								<path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z" />
							</svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						id="view-editor"
						:name="t('chronos', 'Session editor')"
						:to="{ name: 'editor' }">
						<template #icon>
							<svg viewBox="0 0 24 24"
								aria-hidden="true"
								fill="currentColor"
								width="16"
								height="16">
								<path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10z" />
							</svg>
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationList>

				<template v-if="projects.tracked.length">
					<NcAppNavigationCaption :name="t('chronos', 'Projects')" />
					<NcAppNavigationList>
						<NcAppNavigationItem
							v-for="project in projects.tracked"
							:key="project.uri"
							:name="project.name"
							@click="projects.toggle(project.uri)">
							<template #icon>
								<div
									:class="[$style.dot, projects.isSelected(project.uri) ? $style.dotOn : $style.dotOff]"
									:style="{ '--chronos-project-color': project.color || 'var(--color-primary-element)' }">
									<svg
										v-if="projects.isSelected(project.uri)"
										viewBox="0 0 24 24"
										width="12"
										height="12"
										fill="white"
										aria-hidden="true">
										<path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z" />
									</svg>
								</div>
							</template>
						</NcAppNavigationItem>
					</NcAppNavigationList>
				</template>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div :class="[$style.main, wide ? $style.wide : $style.narrow]">
				<RouterView />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style module>
.main {
	display: flex;
	flex-direction: column;
	gap: 24px;
	padding: 32px 28px 48px;
	margin: 0 auto;
	width: 100%;
	box-sizing: border-box;
}

.narrow {
	max-width: 820px;
}

.wide {
	max-width: 1200px;
}

.dot {
	width: 16px;
	height: 16px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	border: 2px solid var(--chronos-project-color);
	transition: all 0.2s ease;
	flex-shrink: 0;
}

.dotOn {
	background-color: var(--chronos-project-color);
}

.dotOff {
	background-color: transparent;
}
</style>
