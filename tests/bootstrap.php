<?php

declare(strict_types=1);

/**
 * Bootstrap for the tests that need a running Nextcloud.
 *
 * Works on both kinds of installation. A server git checkout has its own test
 * bootstrap, which brings the test helpers with it; a release tarball — what
 * most instances actually run — has no `tests/` directory at all, so the server
 * is booted directly through `lib/base.php`. The app used to assume the former,
 * which meant `composer test:integration` could not run on a real instance.
 */

$appRoot = dirname(__DIR__);
$serverRoot = dirname($appRoot, 2);

$serverTests = $serverRoot . '/tests/bootstrap.php';
if (file_exists($serverTests)) {
	require_once $serverTests;
} elseif (file_exists($serverRoot . '/lib/base.php')) {
	require_once $serverRoot . '/lib/base.php';
} else {
	fwrite(STDERR, sprintf(
		"These tests need a Nextcloud installation. Expected one at %s, with the app in apps/ or custom_apps/.\n",
		$serverRoot,
	));
	exit(1);
}

require_once $appRoot . '/vendor/autoload.php';

\OC_App::loadApp('chronos');

if (class_exists(\OC_Hook::class)) {
	\OC_Hook::clear();
}
