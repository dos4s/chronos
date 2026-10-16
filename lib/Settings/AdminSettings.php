<?php

declare(strict_types=1);

namespace OCA\Chronos\Settings;

use OCA\Chronos\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Which Chronos modules this instance offers (§13).
 *
 * The admin decides what exists; each user then decides which of those they
 * want to see, in the personal settings.
 */
class AdminSettings implements ISettings {

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'settings-admin');
	}

	public function getSection(): string {
		return 'additional';
	}

	public function getPriority(): int {
		return 50;
	}
}
