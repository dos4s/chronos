<?php

declare(strict_types=1);

/**
 * Bootstrap for tests that need nothing but the app's own code.
 *
 * The OCP package ships real implementations of the framework classes the app
 * builds on (entities, exceptions), so services, pure logic and request parsing
 * can be tested without a Nextcloud installation — which means they run in CI
 * and on a developer machine. Tests that need a database or a live server use
 * `phpunit.xml` instead and run inside a server checkout.
 */
require_once __DIR__ . '/../vendor/autoload.php';

// The nextcloud/ocp package ships the framework's classes but no autoloader,
// because a real installation provides them. Map them here, for tests only.
spl_autoload_register(static function (string $class): void {
	foreach (['OCP\\' => 'OCP', 'NCU\\' => 'NCU'] as $prefix => $dir) {
		if (!str_starts_with($class, $prefix)) {
			continue;
		}
		$relative = str_replace('\\', '/', substr($class, strlen($prefix)));
		$file = __DIR__ . '/../vendor/nextcloud/ocp/' . $dir . '/' . $relative . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});
