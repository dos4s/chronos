<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Core\Support\CurrentUser;
use OCA\Chronos\Planning\Service\RoadmapService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The roadmap: every project at once (§12.6).
 *
 * The one read in the app that is deliberately *not* scoped to one project —
 * the whole point of it is the other projects — and the one that therefore
 * belongs to its own module, which requires the `gantt` one (§13).
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class RoadmapController extends Controller {

	public function __construct(
		IRequest $request,
		private RoadmapService $roadmap,
		private FeatureToggles $toggles,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/roadmap')]
	public function index(string $archived = 'no'): JSONResponse {
		return new JSONResponse(
			$this->roadmap->roadmap($this->user(), $archived === 'yes')->jsonSerialize(),
		);
	}

	private function user(): string {
		$userId = $this->currentUser->getUid();
		$this->toggles->requireEnabled(FeatureToggles::MODULE_ROADMAP, $userId);

		return $userId;
	}
}
