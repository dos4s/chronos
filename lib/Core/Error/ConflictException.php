<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Error;

use OCP\AppFramework\Http;

class ConflictException extends ChronosException {

	public function getHttpStatus(): int {
		return Http::STATUS_CONFLICT;
	}
}
