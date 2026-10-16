<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Dav\CalDavGateway;
use OCA\Chronos\Core\Dav\CalendarRef;
use OCA\Chronos\Core\Support\CurrentUser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The task lists a user has, which Chronos shows as projects (§6).
 *
 * Reading CalDAV now goes through Core/Dav/CalDavGateway, so this controller
 * no longer touches the DAV app's internals, and a calendar whose component
 * set is unknown is no longer offered as a project (D12).
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class TaskListController extends Controller {

	public function __construct(
		IRequest $request,
		private CalDavGateway $gateway,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/tasklists')]
	public function index(): JSONResponse {
		$lists = array_map(
			static fn (CalendarRef $calendar): array => [
				'uri' => $calendar->ref,
				'name' => $calendar->name(),
				'color' => $calendar->color,
				'shared' => $calendar->isShared,
				'writable' => $calendar->isWritable,
			],
			$this->gateway->getTaskCalendars($this->currentUser->getUid()),
		);

		return new JSONResponse($lists);
	}
}
