<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Error\ValidationFailedException;
use OCA\Chronos\Core\Settings\FeatureToggles;
use OCA\Chronos\Core\Support\CurrentUser;
use OCA\Chronos\Planning\Service\PlanService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The timeline: one project's plan, and the drags on it (§12.5, §15.4).
 *
 * Every route belongs to the `gantt` module, so every one asks first whether
 * it is switched on for this user (§13). A plan is always read for one project,
 * because a timeline is one screen (§16); the roadmap is the cross-project view
 * and has its own.
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class PlanController extends Controller {

	public function __construct(
		IRequest $request,
		private PlanService $plan,
		private FeatureToggles $toggles,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The project reference contains slashes, so it travels as a parameter
	 * rather than as part of the path.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/plan')]
	public function index(string $project): JSONResponse {
		return new JSONResponse($this->plan->plan($this->user(), $project)->jsonSerialize());
	}

	/**
	 * One bar, dragged or resized: both of its dates, and the etag the page
	 * had for it (§15.6).
	 *
	 * `mode` lets the drag say which reschedule mode it was previewing, so the
	 * ghost bars the user accepted are the ones that get written even if the
	 * project's setting changed in another tab.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/plan/move')]
	public function move(
		string $project,
		string $uid,
		int $startAt,
		int $dueAt,
		bool $startIsDate = true,
		bool $dueIsDate = true,
		?string $etag = null,
		?string $mode = null,
	): JSONResponse {
		return new JSONResponse(
			$this->plan->move(
				$this->user(),
				$project,
				$uid,
				$startAt,
				$startIsDate,
				$dueAt,
				$dueIsDate,
				$etag,
				$mode,
			)->jsonSerialize(),
		);
	}

	/**
	 * A dependency between two tasks, written as RFC 9253 `RELATED-TO` on
	 * whichever of them declares it (§7).
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/plan/link')]
	public function link(
		string $project,
		string $predecessorUid,
		string $successorUid,
		string $reltype = 'FINISHTOSTART',
		int $gapSeconds = 0,
	): JSONResponse {
		return new JSONResponse(
			$this->plan->link(
				$this->user(),
				$project,
				trim($predecessorUid),
				trim($successorUid),
				$reltype,
				$gapSeconds,
			)->jsonSerialize(),
		);
	}

	/**
	 * A dependency is named by the two tasks it joins rather than by its row
	 * id: the row is rewritten whenever the task that declares it is written,
	 * so an id read when the link was made does not survive a drag (D42).
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/plan/link')]
	public function unlink(string $project, string $predecessorUid, string $successorUid): JSONResponse {
		return new JSONResponse(
			$this->plan->unlink(
				$this->user(),
				$project,
				trim($predecessorUid),
				trim($successorUid),
			)->jsonSerialize(),
		);
	}

	/**
	 * The project's own timeline settings: what a move does to the dependents,
	 * and which days it works (§10.2).
	 *
	 * @param list<mixed> $workingDays
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/plan/settings')]
	public function settings(string $project, string $mode, array $workingDays = []): JSONResponse {
		return new JSONResponse(
			$this->plan->configure(
				$this->user(),
				$project,
				$mode,
				$this->readWeekdays($workingDays),
			)->jsonSerialize(),
		);
	}

	/**
	 * @param list<mixed> $workingDays
	 * @return list<int>
	 * @throws ValidationFailedException
	 */
	private function readWeekdays(array $workingDays): array {
		$days = [];
		/** @var mixed $day */
		foreach ($workingDays as $day) {
			if (!is_int($day) && !(is_string($day) && ctype_digit($day))) {
				throw new ValidationFailedException('invalid_working_days');
			}
			$days[] = (int)$day;
		}

		return $days;
	}

	private function user(): string {
		$userId = $this->currentUser->getUid();
		$this->toggles->requireEnabled(FeatureToggles::MODULE_GANTT, $userId);

		return $userId;
	}
}
