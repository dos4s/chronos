<?php

declare(strict_types=1);

namespace OCA\Chronos\Settings;

use OCA\Chronos\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Which of the modules this instance offers this user wants to see (§13).
 *
 * A module switched off here keeps its data: switching it back on loses
 * nothing.
 */
class PersonalSettings implements ISettings {

	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'settings-personal');
	}

	public function getSection(): string {
		return 'additional';
	}

	public function getPriority(): int {
		return 50;
	}
}
