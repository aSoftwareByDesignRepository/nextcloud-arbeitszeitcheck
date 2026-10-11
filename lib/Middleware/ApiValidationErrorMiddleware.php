<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Middleware;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\InvalidEnumParameterException;
use OCP\AppFramework\Http\InvalidStringParameterException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\ParameterOutOfRangeException;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

/**
 * Maps framework-level request-parameter validation errors to the app's
 * JSON error envelope on /api/ routes.
 *
 * Nextcloud's Dispatcher rejects invalid typed parameters before the
 * controller runs (ParameterOutOfRangeException for numeric ranges —
 * including its built-in 1..500 default for any param named "limit" —
 * InvalidStringParameterException and InvalidEnumParameterException).
 * Unhandled, these bubble up as an empty text/html 400, which API clients
 * cannot parse. This middleware keeps the rejection (fail closed) and only
 * changes the response shape.
 *
 * Non-API routes rethrow so page requests keep the framework behaviour.
 */
final class ApiValidationErrorMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
	) {
	}

	public function afterException($controller, $methodName, \Exception $exception): Response
	{
		if (
			!$exception instanceof ParameterOutOfRangeException
			&& !$exception instanceof InvalidStringParameterException
			&& !$exception instanceof InvalidEnumParameterException
		) {
			throw $exception;
		}

		$path = '';
		try {
			$path = (string)$this->request->getPathInfo();
		} catch (\Throwable) {
		}
		if (!str_contains($path, '/api/')) {
			throw $exception;
		}

		$param = $exception->getParameterName();
		return new JSONResponse([
			'success' => false,
			'error' => $exception->getMessage(),
			'error_code' => 'invalid_parameter',
			'fields' => $param !== '' ? [$param => $exception->getMessage()] : [],
		], Http::STATUS_BAD_REQUEST);
	}
}
