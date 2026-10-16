<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Core\Support\CurrentUser;
use OCA\Chronos\Projects\Db\Area;
use OCA\Chronos\Projects\Service\ProjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Areas: the grouping above projects, which exists only in Chronos (§6).
 *
 * Part of the `projects` module (§13); the project list itself is not, because
 * the task views cannot work without it.
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class AreaController extends Controller {

	public function __construct(
		IRequest $request,
		private ProjectService $projects,
		private FeatureToggles $toggles,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/areas')]
	public function index(): JSONResponse {
		return new JSONResponse(array_map(
			static fn (Area $area): array => $area->jsonSerialize(),
			$this->projects->listAreas($this->user()),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/areas')]
	public function create(?string $name = null, ?string $color = null): JSONResponse {
		return new JSONResponse(
			$this->projects->createArea($this->user(), $name, $color)->jsonSerialize(),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/areas/{id}')]
	public function update(int $id, ?string $name = null, ?string $color = null, ?int $sort = null): JSONResponse {
		return new JSONResponse(
			$this->projects->updateArea($this->user(), $id, $name, $color, $sort)->jsonSerialize(),
		);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/areas/{id}')]
	public function destroy(int $id): JSONResponse {
		$this->projects->deleteArea($this->user(), $id);
		return new JSONResponse(['ok' => true]);
	}

	/**
	 * Areas belong to the projects module, so an instance or a user with it
	 * switched off has no areas rather than empty ones (§13).
	 */
	private function user(): string {
		$userId = $this->currentUser->getUid();
		$this->toggles->requireEnabled(FeatureToggles::MODULE_PROJECTS, $userId);

		return $userId;
	}
}
