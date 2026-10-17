<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcContent from '@nextcloud/vue/components/NcContent'
import { useModulesStore } from './stores/modules.ts'
import { useProjectsStore } from './stores/projects.ts'
import { useTimeStore } from './stores/time.ts'

const route = useRoute()
const time = useTimeStore()
const projects = useProjectsStore()
const modules = useModulesStore()

const wide = computed(() => route.name !== 'timer')

/**
 * A plain click works in that project and looks at it; ctrl or ⌘ adds another
 * for the views that can show several at once (analytics, the sessions, the
 * roadmap). Shift does the same, because that is what a list of things usually
 * means by it.
 */
function onProjectClick(event: MouseEvent, uri: string): void {
	projects.toggle(uri, event.ctrlKey || event.metaKey || event.shiftKey)
}

onMounted(() => {
	time.refresh()
	projects.refresh()
})
</script>

<template>
	<NcContent appName="chronos">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationList>
					<NcAppNavigationItem
						id="view-timer"
						:name="t('chronos', 'Timer')"
						:to="{ name: 'timer' }">
						<template #icon>
							<svg
								viewBox="0 0 24 24"
								aria-hidden="true"
								fill="currentColor"
								width="16"
								height="16">
								<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm4.2 14.2L11 13V7h1.5v5.2l4.5 2.7-.8 1.3z" />
							</svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						id="view-tasks"
						:name="t('chronos', 'Tasks')"
						:to="{ name: 'tasks' }">
						<template #icon>
							<svg
								viewBox="0 0 24 24"
								aria-hidden="true"
								fill="currentColor"
								width="16"
								height="16">
								<path d="M3 5h2v2H3V5m0 6h2v2H3v-2m0 6h2v2H3v-2M7 5h14v2H7V5m0 6h14v2H7v-2m0 6h14v2H7v-2" />
							</svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						v-if="modules.kanban"
						id="view-board"
						:name="t('chronos', 'Board')"
						:to="{ name: 'board' }">
						<template #icon>
							<svg
								viewBox="0 0 24 24"
								aria-hidden="true"
								fill="currentColor"
								width="16"
								height="16">
								<path d="M4 4h4v12H4V4m6 0h4v16h-4V4m6 0h4v8h-4V4M2 2v20h20V2H2m2 2h16v16H4V4Z" />
							</svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						v-if="modules.gantt"
						id="view-timeline"
						:name="t('chronos', 'Timeline')"
						:to="{ name: 'timeline' }">
						<template #icon>
							<svg
								viewBox="0 0 24 24"
								aria-hidden="true"
								fill="currentColor"
								width="16"
								height="16">
								<path d="M2 4h2v16H2V4m4 2h8v3H6V6m3 5h9v3H9v-3m-3 5h7v3H6v-3Z" />
							</svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						v-if="modules.roadmap"
						id="view-roadmap"
						:name="t('chronos', 'Roadmap')"
						:to="{ name: 'roadmap' }">
						<template #icon>
							<svg
								viewBox="0 0 24 24"
								aria-hidden="true"
								fill="currentColor"
								width="16"
								height="16">
								<path d="M15 19l-6-2V5l6 2v12m2-12 4-1.5v12L17 19V7M7 5v12l-4 1.5v-12L7 5Z" />
							</svg>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						id="view-analytics"
						:name="t('chronos', 'Analytics')"
						:to="{ name: 'analytics' }">
						<template #icon>
							<svg
								viewBox="0 0 24 24"
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
							<svg
								viewBox="0 0 24 24"
								aria-hidden="true"
								fill="currentColor"
								width="16"
								height="16">
								<path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10z" />
							</svg>
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationList>

				<template v-if="projects.offered.length">
					<NcAppNavigationCaption :name="t('chronos', 'Projects')" />
					<NcAppNavigationList>
						<!--
							The one place a project is chosen (§14.2). Clicking
							one works in it and looks at it; ctrl or ⌘ adds
							another for the views that can show several at once.
						-->
						<NcAppNavigationItem
							:name="t('chronos', 'All projects')"
							:active="projects.selection.length === 0"
							@click="projects.clearSelection()">
							<template #icon>
								<div :class="[$style.dot, projects.selection.length === 0 ? $style.dotAll : $style.dotOff]" />
							</template>
						</NcAppNavigationItem>
						<NcAppNavigationItem
							v-for="project in projects.offered"
							:key="project.uri"
							:name="project.name"
							:active="projects.isCurrent(project.uri) && projects.selection.length > 0"
							:title="project.label"
							@click="onProjectClick($event, project.uri)">
							<template #icon>
								<div
									:class="[$style.dot, {
										[$style.dotOn]: projects.selection.includes(project.uri),
										[$style.dotOff]: !projects.selection.includes(project.uri),
										[$style.dotCurrent]: projects.isCurrent(project.uri),
									}]"
									:style="{ '--chronos-project-color': project.color || 'var(--color-primary-element)' }">
									<svg
										v-if="projects.selection.includes(project.uri)"
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
					<label :class="$style.archived">
						<input v-model="projects.showArchived" type="checkbox">
						{{ t('chronos', 'Show archived projects') }}
					</label>
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

/* Every project at once, which is what no selection means. */
.dotAll {
	background-color: var(--color-primary-element);
	border-color: var(--color-primary-element);
}

/* The one the board, the timeline and the task list are showing. */
.dotCurrent {
	box-shadow: 0 0 0 2px var(--color-main-background), 0 0 0 4px var(--chronos-project-color, var(--color-primary-element));
}

.archived {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 4px 12px 8px 14px;
}
</style>
