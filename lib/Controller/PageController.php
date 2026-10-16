<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Core\Support\CurrentUser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IDateTimeZone;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {

	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private FeatureToggles $toggles,
		private CurrentUser $currentUser,
		private IDateTimeZone $dateTimeZone,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		// Saves the app a round trip before it can draw its navigation.
		$this->initialState->provideInitialState(
			'modules',
			$this->toggles->getUserState($this->currentUser->getUid()),
		);
		$this->initialState->provideInitialState(
			'timezone',
			$this->dateTimeZone->getTimeZone()->getName(),
		);

		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}
}
