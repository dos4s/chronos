module.exports = {
	// The TypeScript variant sets @typescript-eslint/parser for the <script>
	// block of .vue files; extending plain '@nextcloud' cannot parse the type
	// annotations in them, which is why linting used to fail on every view.
	extends: [
		'@nextcloud/eslint-config/typescript',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		// Comments here explain why a function exists, not what each argument
		// is; types already state that.
		'jsdoc/require-param': 'off',
		'jsdoc/require-returns': 'off',
		'vue/first-attribute-linebreak': 'off',
	},
}
