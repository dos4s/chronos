<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Core\Support\CurrentUser;
use OCA\Chronos\Core\Task\TaskService;
use OCA\Chronos\Workflow\Db\StatusChange;
use OCA\Chronos\Workflow\Service\WorkflowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * A task's status history (§10.3).
 *
 * Its own controller because the route belongs to the workflow module while
 * `/tasks` belongs to the core task layer, and because every controller with
 * routes has to be a file directly in `lib/Controller` (AD12).
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class TaskHistoryController extends Controller {

	public function __construct(
		IRequest $request,
		private WorkflowService $workflows,
		private TaskService $tasks,
		private FeatureToggles $toggles,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/tasks/{uid}/history')]
	public function index(string $uid, int $limit = 100): JSONResponse {
		$userId = $this->currentUser->getUid();
		$this->toggles->requireEnabled(FeatureToggles::MODULE_WORKFLOW, $userId);

		// Reading the task first is what scopes the history: a UID alone says
		// nothing about who may see it.
		$task = $this->tasks->get($userId, $uid);

		return new JSONResponse(array_map(
			static fn (StatusChange $change): array => $change->jsonSerialize(),
			$this->workflows->historyFor($task->getUid(), $limit),
		));
	}
}
