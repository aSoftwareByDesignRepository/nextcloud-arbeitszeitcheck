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

		// Locale-stable typed mapping — status keys off the exception class,
		// never off message text (a localized "Zugriff verweigert." must not
		// collapse to a 500 the way an English-substring match would).
		if ($exception instanceof \OCA\ArbeitszeitCheck\Exception\NotAuthenticatedException) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('User not authenticated'),
				'error_code' => 'unauthenticated',
				'fields' => new \stdClass(),
			], Http::STATUS_UNAUTHORIZED);
		}
		if ($exception instanceof \OCA\ArbeitszeitCheck\Exception\AccessDeniedException) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Access denied'),
				'error_code' => 'access_denied',
				'fields' => new \stdClass(),
			], Http::STATUS_FORBIDDEN);
		}
		if ($exception instanceof \OCA\ArbeitszeitCheck\Exception\BusinessRuleException) {
			$payload = $exception->toHttpPayload();
			$payload['fields'] = new \stdClass();
			return new JSONResponse($payload, Http::STATUS_BAD_REQUEST);
		}

		$this->logger->error('ArbeitszeitCheck API request failed: ' . $methodName, [
			'app' => 'arbeitszeitcheck',
			'exception' => $exception,
		]);
		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t('Internal error.'),
			'error_code' => 'internal_error',
			'fields' => new \stdClass(),
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
