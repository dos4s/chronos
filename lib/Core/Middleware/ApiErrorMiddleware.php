<?php

declare(strict_types=1);

namespace OCA\Chronos\Core\Middleware;

use OCA\Chronos\AppInfo\Application;
use OCA\Chronos\Core\Error\ChronosException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Maps typed Chronos exceptions to HTTP responses in exactly one place (§14.1).
 */
class ApiErrorMiddleware extends Middleware {

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param Controller $controller
	 * @param string $methodName
	 * @throws Throwable
	 */
	public function afterException($controller, $methodName, Throwable $exception): JSONResponse {
		if (!$exception instanceof ChronosException) {
			throw $exception;
		}

		$this->logger->debug('Chronos request failed: ' . $exception->getErrorCode(), [
			'app' => Application::APP_ID,
			'controller' => $controller::class,
			'method' => $methodName,
			'exception' => $exception,
		]);

		/** @psalm-suppress ArgumentTypeCoercion every getHttpStatus() returns an Http::STATUS_* constant */
		return new JSONResponse(
			['error' => $exception->getErrorCode()],
			$exception->getHttpStatus(),
		);
	}
}
