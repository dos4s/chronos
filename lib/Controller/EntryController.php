<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Support\CurrentUser;
use OCA\Chronos\Time\Request\SessionUpdateRequest;
use OCA\Chronos\Time\Service\SessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Sessions. Every method reads input, calls one service method and returns the
 * result; all rules live in SessionService (§14.1).
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class EntryController extends Controller {

	private const MAX_LIMIT = 500;

	public function __construct(
		IRequest $request,
		private SessionService $sessions,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/entries/active')]
	public function active(): JSONResponse {
		return new JSONResponse($this->sessions->getActive($this->currentUser->getUid()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/entries')]
	public function index(int $limit = 100, int $offset = 0): JSONResponse {
		return new JSONResponse($this->sessions->listSessions(
			$this->currentUser->getUid(),
			max(1, min($limit, self::MAX_LIMIT)),
			max(0, $offset),
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/entries/task-totals')]
	public function taskTotals(): JSONResponse {
		return new JSONResponse($this->sessions->taskTotals($this->currentUser->getUid()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/entries/check-in')]
	public function checkIn(
		?string $note = null,
		?string $projectUri = null,
		?string $projectName = null,
		?string $taskUid = null,
		?string $taskSummary = null,
	): JSONResponse {
		return new JSONResponse(
			$this->sessions->checkIn(
				$this->currentUser->getUid(),
				$note,
				$projectUri,
				$projectName,
				$taskUid,
				$taskSummary,
			),
			Http::STATUS_CREATED,
		);
	}

	/**
	 * Start this, ending whatever was running: one gesture, one request.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/entries/switch')]
	public function switchTo(
		?string $note = null,
		?string $projectUri = null,
		?string $projectName = null,
		?string $taskUid = null,
		?string $taskSummary = null,
	): JSONResponse {
		return new JSONResponse(
			$this->sessions->switchTo(
				$this->currentUser->getUid(),
				$note,
				$projectUri,
				$projectName,
				$taskUid,
				$taskSummary,
			),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/entries/pause')]
	public function pause(): JSONResponse {
		return new JSONResponse($this->sessions->pause($this->currentUser->getUid()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/entries/resume')]
	public function resume(): JSONResponse {
		return new JSONResponse($this->sessions->resume($this->currentUser->getUid()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/entries/check-out')]
	public function checkOut(): JSONResponse {
		return new JSONResponse($this->sessions->checkOut($this->currentUser->getUid()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/entries/{id}')]
	public function update(
		int $id,
		int $startAt,
		?int $endAt = null,
		?array $pauses = null,
		?string $note = null,
		?string $projectUri = null,
		?string $projectName = null,
		?string $taskUid = null,
		?string $taskSummary = null,
	): JSONResponse {
		$body = SessionUpdateRequest::fromParams($startAt, $endAt, $pauses, $note);

		return new JSONResponse($this->sessions->updateSession(
			$this->currentUser->getUid(),
			$id,
			$body->startAt,
			$body->endAt,
			$body->pauses,
			$body->note,
			$projectUri,
			$projectName,
			$taskUid,
			$taskSummary,
		));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/entries/{id}')]
	public function destroy(int $id): JSONResponse {
		$this->sessions->deleteSession($this->currentUser->getUid(), $id);
		return new JSONResponse(['ok' => true]);
	}
}
