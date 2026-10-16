<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Error;

use OCP\AppFramework\Http;

class NotAuthenticatedException extends ChronosException {

	public function __construct(string $errorCode = 'not_authenticated', string $message = '') {
		parent::__construct($errorCode, $message);
	}

	public function getHttpStatus(): int {
		return Http::STATUS_UNAUTHORIZED;
	}
}
