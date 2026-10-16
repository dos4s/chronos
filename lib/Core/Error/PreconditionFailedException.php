<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Error;

use OCP\AppFramework\Http;

/**
 * The task changed since the client read it (§11.1).
 *
 * A 412 rather than a 409, so the client can tell "someone else edited this
 * task" from "this request conflicts with the rules" and run the resolution
 * flow instead of simply reporting a failure.
 */
class PreconditionFailedException extends ChronosException {

	public function __construct(string $errorCode = 'etag_mismatch', string $message = '') {
		parent::__construct($errorCode, $message);
	}

	public function getHttpStatus(): int {
		return Http::STATUS_PRECONDITION_FAILED;
	}
}
