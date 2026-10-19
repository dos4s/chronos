import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import router from './router.ts'

/*
 * Every message the app shows is a toast from this package, and its container
 * is positioned by this stylesheet — without it a toast lands unstyled in the
 * top-left corner of the window, half off the screen (§15.10).
 */
import '@nextcloud/dialogs/style.css'
import './styles/app.css'

createApp(App)
	.use(createPinia())
	.use(router)
	.mount('#chronos')
