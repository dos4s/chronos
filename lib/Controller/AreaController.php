<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
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
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class AreaController extends Controller {

	public function __construct(
		IRequest $request,
		private ProjectService $projects,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/areas')]
	public function index(): JSONResponse {
		return new JSONResponse(array_map(
			static fn (Area $area): array => $area->jsonSerialize(),
			$this->projects->listAreas($this->currentUser->getUid()),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/areas')]
	public function create(?string $name = null, ?string $color = null): JSONResponse {
		return new JSONResponse(
			$this->projects->createArea($this->currentUser->getUid(), $name, $color)->jsonSerialize(),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/areas/{id}')]
	public function update(int $id, ?string $name = null, ?string $color = null, ?int $sort = null): JSONResponse {
		return new JSONResponse(
			$this->projects->updateArea($this->currentUser->getUid(), $id, $name, $color, $sort)->jsonSerialize(),
		);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/areas/{id}')]
	public function destroy(int $id): JSONResponse {
		$this->projects->deleteArea($this->currentUser->getUid(), $id);
		return new JSONResponse(['ok' => true]);
	}
}
