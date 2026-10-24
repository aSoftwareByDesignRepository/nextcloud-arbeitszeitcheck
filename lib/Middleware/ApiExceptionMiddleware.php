<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Middleware;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Catch-all JSON envelope for ArbeitszeitCheck /api/ routes.
 *
 * Registered FIRST among the app middlewares so its afterException runs
 * LAST (the dispatcher asks middlewares in reverse registration order):
 * it only sees throwables no specialised middleware handled. Without it an
 * unexpected Exception or Error (e.g. a TypeError inside a mapper) bubbles
 * to core and renders an HTML 500 page that API clients cannot parse.
 *
 * The specialised middlewares keep their handling — they run before this
 * one and return their own typed responses. Non-API routes rethrow so page
 * requests keep the framework error pages.
 */
final class ApiExceptionMiddleware extends Middleware
{
	public function __construct(
		private readonly IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
	) {
	}

	public function afterException($controller, $methodName, \Exception $exception): Response
	{
		$path = '';
		try {
			$path = (string)$this->request->getPathInfo();
		} catch (\Throwable) {
		}
		if (!str_contains($path, '/api/')) {
			throw $exception;
		}

		$this->logger->error('ArbeitszeitCheck API request failed: ' . $methodName, [
			'app' => 'arbeitszeitcheck',
			'exception' => $exception,
		]);
		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t('Internal error.'),
			'error_code' => 'internal_error',
			'fields' => [],
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
