import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{
		rules: {
			// Comments here say why a function exists; its types already say
			// what each argument is, and not every function needs a comment.
			'jsdoc/require-jsdoc': 'off',
			'jsdoc/require-param': 'off',
			'jsdoc/require-returns': 'off',
		},
	},
	{
		// The rule guards against component names colliding with HTML
		// elements in a template. A route view is resolved by the router and
		// never written as a tag, so the names stay as the architecture
		// document lists them.
		files: ['src/views/*.vue'],
		rules: {
			'vue/multi-word-component-names': 'off',
		},
	},
	{
		// Tests sit next to the code they cover and may import dev-only tools;
		// they are not part of what ships.
		files: ['**/*.spec.ts'],
		rules: {
			'n/no-unpublished-import': 'off',
		},
	},
]
