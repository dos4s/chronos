<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Error;

use RuntimeException;
use Throwable;

/**
 * Base class for every expected error in Chronos.
 *
 * Services throw these instead of returning error arrays; ApiErrorMiddleware
 * maps them to a JSON response exactly once (§14.1).
 */
abstract class ChronosException extends RuntimeException {

	public function __construct(
		private string $errorCode,
		string $message = '',
		?Throwable $previous = null,
	) {
		parent::__construct($message === '' ? $errorCode : $message, 0, $previous);
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	abstract public function getHttpStatus(): int;
}
