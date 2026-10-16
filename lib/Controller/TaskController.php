<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Index\Task;
use OCA\Chronos\Core\Support\CurrentUser;
use OCA\Chronos\Core\Task\TaskService;
use OCA\Chronos\Core\Task\TaskWrite;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Tasks, read from the index and written to CalDAV (§11.1).
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class TaskController extends Controller {

	public function __construct(
		IRequest $request,
		private TaskService $tasks,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/tasks')]
	public function index(string $project): JSONResponse {
		return new JSONResponse(array_map(
			static fn (Task $task): array => $task->jsonSerialize(),
			$this->tasks->listForProject($this->currentUser->getUid(), $project),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/tasks')]
	public function create(
		string $project,
		?string $summary = null,
		?string $status = null,
		?int $priority = null,
		?int $startAt = null,
		?bool $startIsDate = null,
		?int $dueAt = null,
		?bool $dueIsDate = null,
		?int $percent = null,
		?string $parentUid = null,
		?string $type = null,
	): JSONResponse {
		$write = TaskWrite::fromParams(
			$summary,
			$status,
			$priority,
			$startAt,
			$startIsDate,
			$dueAt,
			$dueIsDate,
			$percent,
			$parentUid,
			$type,
		);

		return new JSONResponse(
			$this->tasks->create($this->currentUser->getUid(), $project, $write)->jsonSerialize(),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/tasks/{uid}')]
	public function update(
		string $uid,
		?string $summary = null,
		?string $status = null,
		?int $priority = null,
		?int $startAt = null,
		?bool $startIsDate = null,
		?int $dueAt = null,
		?bool $dueIsDate = null,
		?int $percent = null,
		?string $parentUid = null,
		?string $type = null,
		?string $etag = null,
	): JSONResponse {
		$write = TaskWrite::fromParams(
			$summary,
			$status,
			$priority,
			$startAt,
			$startIsDate,
			$dueAt,
			$dueIsDate,
			$percent,
			$parentUid,
			$type,
		);

		return new JSONResponse(
			$this->tasks->update($this->currentUser->getUid(), $uid, $write, $etag)->jsonSerialize(),
		);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/tasks/{uid}')]
	public function destroy(string $uid, ?string $etag = null): JSONResponse {
		$this->tasks->delete($this->currentUser->getUid(), $uid, $etag);
		return new JSONResponse(['ok' => true]);
	}
}
