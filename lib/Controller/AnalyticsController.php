<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Support\CurrentUser;
use OCA\Chronos\Time\Service\AnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Totals over a date range, computed server-side (§16).
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class AnalyticsController extends Controller {

	public function __construct(
		IRequest $request,
		private AnalyticsService $analytics,
		private CurrentUser $currentUser,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/analytics/summary')]
	public function summary(int $fromAt, int $toAt, ?string $timezone = null): JSONResponse {
		return new JSONResponse($this->analytics->summary(
			$this->currentUser->getUid(),
			$fromAt,
			$toAt,
			$timezone,
		));
	}
}
