<?php

declare(strict_types=1);

namespace OCA\Chronos\Controller;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Db\Entry;
use OCA\Chronos\Db\EntryMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use RuntimeException;

#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
class QuickController extends Controller {

	public function __construct(
		IRequest $request,
		private EntryMapper $mapper,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/quick/check-in')]
	public function checkIn(): RedirectResponse {
		$userId = $this->getUserId();
		try {
			$this->mapper->findActive($userId);
			// already checked in → no-op
		} catch (DoesNotExistException) {
			$entry = new Entry();
			$entry->setUserId($userId);
			$entry->setStartTime($this->nowMs());
			$entry->setPausedDuration(0);
			$this->mapper->insert($entry);
		}
		return $this->redirectBack();
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/quick/check-out')]
	public function checkOut(): RedirectResponse {
		$userId = $this->getUserId();
		try {
			$entry = $this->mapper->findActive($userId);
			$now = $this->nowMs();
			if ($entry->getPausedAt() !== null) {
				$entry->setPausedDuration(
					$entry->getPausedDuration() + ($now - (int) $entry->getPausedAt()),
				);
				$entry->setPausedAt(null);
			}
			$entry->setEndTime($now);
			$this->mapper->update($entry);
		} catch (DoesNotExistException) {
			// no-op
		}
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

	private function getUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('No authenticated user');
		}
		return $user->getUID();
	}

	private function nowMs(): int {
		return (int) (microtime(true) * 1000);
	}
}
