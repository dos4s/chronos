/**
 * vue-cal 4 ships no type declarations. The session editor uses it through a
 * handful of props and one slot; M4 replaces it with the shared drag engine
 * (§15.8), so a minimal declaration is enough until then.
 */
declare module 'vue-cal' {
	import type { DefineComponent } from 'vue'

	const VueCal: DefineComponent<Record<string, unknown>>
	export default VueCal
}

declare module 'vue-cal/dist/vuecal.css'
