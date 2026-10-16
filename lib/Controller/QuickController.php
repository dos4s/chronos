<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Support\CurrentUser;
use OCA\Chronos\Time\Service\SessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Check in and out from a link, for example from a desktop shortcut.
 *
 * Goes through the same SessionService as the UI, so a quick check-out records
 * its pause like any other (D2).
 */
#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class QuickController extends Controller {

	public function __construct(
		IRequest $request,
		private SessionService $sessions,
		private CurrentUser $currentUser,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/quick/check-in')]
	public function checkIn(): RedirectResponse {
		$this->sessions->checkInIfIdle($this->currentUser->getUid());
		return $this->redirectBack();
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/quick/check-out')]
	public function checkOut(): RedirectResponse {
		$this->sessions->checkOutIfRunning($this->currentUser->getUid());
		return $this->redirectBack();
	}

	private function redirectBack(): RedirectResponse {
		$referer = $this->request->getHeader('Referer');
		if ($referer !== '') {
			return new RedirectResponse($referer);
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('dashboard.dashboard.index'),
		);
	}
}
