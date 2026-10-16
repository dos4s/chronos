<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Error;

use OCP\AppFramework\Http;

class ForbiddenException extends ChronosException {

	public function __construct(string $errorCode = 'forbidden', string $message = '') {
		parent::__construct($errorCode, $message);
	}

	public function getHttpStatus(): int {
		return Http::STATUS_FORBIDDEN;
	}
}
