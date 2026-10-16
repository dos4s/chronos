<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Support\CurrentUser;
use OCA\Chronos\Projects\ProjectView;
use OCA\Chronos\Projects\Service\ProjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Projects: the user's task lists, with what Chronos knows about each (§6).
 *
 * This replaces the earlier `/tasklists`, which could only report the lists
 * themselves. A project reference contains slashes, so it travels as a
 * parameter rather than a path segment.
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class ProjectController extends Controller {

	public function __construct(
		IRequest $request,
		private ProjectService $projects,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/projects')]
	public function index(bool $includeArchived = true): JSONResponse {
		return new JSONResponse(array_map(
			static fn (ProjectView $project): array => $project->jsonSerialize(),
			$this->projects->listProjects($this->currentUser->getUid(), $includeArchived),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/projects')]
	public function configure(
		string $project,
		?int $areaId = null,
		?string $parentUri = null,
		bool $archived = false,
		int $sort = 0,
	): JSONResponse {
		return new JSONResponse(
			$this->projects->configure(
				$this->currentUser->getUid(),
				$project,
				$areaId,
				$parentUri,
				$archived,
				$sort,
			)->jsonSerialize(),
		);
	}
}
