<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Core\Support\CurrentUser;
use OCA\Chronos\Workflow\Service\WorkflowService;
use OCA\Chronos\Workflow\WorkflowView;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Workflows, statuses and transitions (§12.1).
 *
 * Every route here belongs to the `workflow` module, so each one first asks
 * whether that module is switched on for this user (§13).
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class WorkflowController extends Controller {

	public function __construct(
		IRequest $request,
		private WorkflowService $workflows,
		private FeatureToggles $toggles,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/workflows')]
	public function index(): JSONResponse {
		$userId = $this->user();

		return new JSONResponse([
			'workflows' => array_map(
				static fn (WorkflowView $view): array => $view->jsonSerialize(),
				$this->workflows->list($userId),
			),
			'templates' => $this->workflows->templates(),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/workflows')]
	public function create(string $template, ?string $name = null): JSONResponse {
		return new JSONResponse(
			$this->workflows->createFromTemplate($this->user(), $template, $name)->jsonSerialize(),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/workflows/{id}')]
	public function rename(int $id, ?string $name = null): JSONResponse {
		return new JSONResponse($this->workflows->rename($this->user(), $id, $name)->jsonSerialize());
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/workflows/{id}')]
	public function destroy(int $id): JSONResponse {
		$this->workflows->delete($this->user(), $id);
		return new JSONResponse(['ok' => true]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/workflows/{id}/statuses/{statusId}')]
	public function updateStatus(
		int $id,
		int $statusId,
		?string $name = null,
		?string $color = null,
		?int $wipLimit = null,
		?bool $autoTimer = null,
		?bool $requiresAssignee = null,
		?bool $slaPaused = null,
	): JSONResponse {
		return new JSONResponse(
			$this->workflows->updateStatus(
				$this->user(),
				$id,
				$statusId,
				$name,
				$color,
				$wipLimit,
				$autoTimer,
				$requiresAssignee,
				$slaPaused,
			)->jsonSerialize(),
		);
	}

	/**
	 * Replaces the whole set of rules; an empty list allows every move again.
	 *
	 * @param list<array{from: int, to: int, requiresComment?: bool}> $rules
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/workflows/{id}/transitions')]
	public function setTransitions(int $id, array $rules = []): JSONResponse {
		return new JSONResponse(
			$this->workflows->setTransitions($this->user(), $id, $rules)->jsonSerialize(),
		);
	}

	/**
	 * Which workflow a project uses. The reference contains slashes, so it
	 * travels as a parameter.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/workflows/project')]
	public function assign(string $project, ?int $workflowId = null): JSONResponse {
		$view = $this->workflows->assignToProject($this->user(), $project, $workflowId);

		return new JSONResponse(['workflow' => $view?->jsonSerialize()]);
	}

	private function user(): string {
		$userId = $this->currentUser->getUid();
		$this->toggles->requireEnabled(FeatureToggles::MODULE_WORKFLOW, $userId);

		return $userId;
	}
}
