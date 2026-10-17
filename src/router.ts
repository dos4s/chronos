import { createRouter, createWebHashHistory } from 'vue-router'

/**
 * Views are loaded on demand (§14.2), so opening the timer does not pull in
 * the calendar library.
 *
 * Hash history keeps every route inside the app's single server route, which
 * means no extra backend route per view.
 */
export default createRouter({
	history: createWebHashHistory(),
	routes: [
		{
			path: '/',
			name: 'timer',
			component: () => import('./views/Timer.vue'),
		},
		{
			path: '/tasks',
			name: 'tasks',
			component: () => import('./views/Tasks.vue'),
		},
		{
			path: '/board',
			name: 'board',
			component: () => import('./views/Kanban.vue'),
		},
		{
			path: '/analytics',
			name: 'analytics',
			component: () => import('./views/Analytics.vue'),
		},
		{
			path: '/editor',
			name: 'editor',
			component: () => import('./views/SessionEditor.vue'),
		},
		{
			path: '/:pathMatch(.*)*',
			redirect: '/',
		},
	],
})
