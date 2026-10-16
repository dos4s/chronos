<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Support;

use OCA\Chronos\Core\Error\NotAuthenticatedException;
use OCP\IUserSession;

/**
 * The single source of "who is making this request" (§14.1).
 *
 * Replaces the copies of getUserId() that lived in each controller (D9).
 */
class CurrentUser {

	public function __construct(
		private IUserSession $userSession,
	) {
	}

	/**
	 * @throws NotAuthenticatedException
	 */
	public function getUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotAuthenticatedException();
		}
		return $user->getUID();
	}
}
