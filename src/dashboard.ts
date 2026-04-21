import { createApp } from 'vue'
import DashboardWidget from './DashboardWidget.vue'

declare global {
	interface Window {
		OCA: {
			Dashboard: {
				register: (id: string, cb: (el: HTMLElement, ctx: unknown) => void) => void
			}
		}
	}
}

document.addEventListener('DOMContentLoaded', () => {
	window.OCA.Dashboard.register('chronos', (el) => {
		const app = createApp(DashboardWidget)
		app.mount(el)
	})
})
