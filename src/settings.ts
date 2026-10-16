import { createApp } from 'vue'
import SettingsPanel from './SettingsPanel.vue'

import './styles/app.css'

/**
 * Both settings pages — admin and personal — mount this one panel; the
 * template says which it is (§13).
 */
const element = document.getElementById('chronos-settings')
if (element !== null) {
	const scope = element.dataset.chronosScope === 'admin' ? 'admin' : 'personal'
	createApp(SettingsPanel, { scope }).mount(element)
}
