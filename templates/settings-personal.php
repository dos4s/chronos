<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Chronos\AppInfo\Application::APP_ID, OCA\Chronos\AppInfo\Application::APP_ID . '-settings');
Util::addStyle(OCA\Chronos\AppInfo\Application::APP_ID, OCA\Chronos\AppInfo\Application::APP_ID . '-settings');

?>

<div id="chronos-settings" data-chronos-scope="personal"></div>
