import { createApp } from 'vue'
import DashboardWidget from './DashboardWidget.vue'

/* The widget reports failures as toasts too (§15.10). */
import '@nextcloud/dialogs/style.css'

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
