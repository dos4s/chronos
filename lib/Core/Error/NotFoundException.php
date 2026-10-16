<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Error;

use OCP\AppFramework\Http;

class NotFoundException extends ChronosException {

	public function __construct(string $errorCode = 'not_found', string $message = '') {
		parent::__construct($errorCode, $message);
	}

	public function getHttpStatus(): int {
		return Http::STATUS_NOT_FOUND;
	}
}
