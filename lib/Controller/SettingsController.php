<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Core\Support\CurrentUser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Feature toggles (§13): what this instance offers and what this user wants.
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class SettingsController extends Controller {

	public function __construct(
		IRequest $request,
		private FeatureToggles $toggles,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/settings/modules')]
	public function modules(): JSONResponse {
		return new JSONResponse([
			'modules' => $this->toggles->getUserState($this->currentUser->getUid()),
			'requirements' => $this->toggles->getRequirements(),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/settings/modules/{module}')]
	public function setModule(string $module, bool $enabled): JSONResponse {
		$userId = $this->currentUser->getUid();
		$this->toggles->setEnabledForUser($module, $userId, $enabled);
		return new JSONResponse(['modules' => $this->toggles->getUserState($userId)]);
	}

	#[FrontpageRoute(verb: 'GET', url: '/settings/admin/modules')]
	public function adminModules(): JSONResponse {
		return new JSONResponse([
			'modules' => $this->toggles->getAdminState(),
			'requirements' => $this->toggles->getRequirements(),
		]);
	}

	#[FrontpageRoute(verb: 'PUT', url: '/settings/admin/modules/{module}')]
	public function setAdminModule(string $module, bool $enabled): JSONResponse {
		$this->toggles->setAvailable($module, $enabled);
		return new JSONResponse(['modules' => $this->toggles->getAdminState()]);
	}
}
