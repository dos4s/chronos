<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Chronos\AppInfo\Application::APP_ID, OCA\Chronos\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Chronos\AppInfo\Application::APP_ID, OCA\Chronos\AppInfo\Application::APP_ID . '-main');

?>

<div id="chronos"></div>
