<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Error;

use OCP\AppFramework\Http;

class ValidationFailedException extends ChronosException {

	public function getHttpStatus(): int {
		return Http::STATUS_BAD_REQUEST;
	}
}
