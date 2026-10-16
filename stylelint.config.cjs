module.exports = {
	// The project depends on @nextcloud/stylelint-config; the previous value
	// pointed at a package that is not installed, so stylelint could not run
	// at all ("No rules found within configuration").
	extends: '@nextcloud/stylelint-config',
}
