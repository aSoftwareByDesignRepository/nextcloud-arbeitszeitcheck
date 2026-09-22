<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Middleware;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Exception\AppAccessDeniedException;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Support\SafeHomeUrl;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * App entry gate — blocks users who are not allowed to use ArbeitszeitCheck
 * before any controller action runs (parity with BudgetCheck / ProjectCheck).
 *
 * {@see PublicPage} endpoints (kiosk, health, tokenized iCal) keep their own
 * auth and must not be blocked when a restricted account happens to hold a
 * browser session cookie on the same origin.
 */
class AppAccessMiddleware extends Middleware
{
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly PermissionService $permissionService,
		private readonly IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
		private readonly IControllerMethodReflector $reflector,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\ArbeitszeitCheck\\Controller\\')) {
			return;
		}

		try {
			$reflection = new ReflectionMethod($controller, (string)$methodName);
			if ($this->isPublicPage($reflection)) {
				return;
			}
		} catch (\ReflectionException) {
			// Unknown method — fall through to allowlist check.
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$uid = $user->getUID();
		if ($this->permissionService->isUserAllowedByAccessGroups($uid)) {
			return;
		}

		$this->logger->warning('arbeitszeitcheck app access denied', [
			'userId' => $uid,
			'path' => $this->request->getPathInfo(),
		]);
		throw new AppAccessDeniedException('restriction');
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		if (!$exception instanceof AppAccessDeniedException) {
			throw $exception;
		}

		$path = (string)($this->request->getPathInfo() ?? '');
		$isApi = str_contains($path, '/api/')
			|| str_contains($path, '/ocs/')
			|| $this->request->getMethod() !== 'GET';
		$accept = strtolower((string)$this->request->getHeader('Accept'));
		$contentType = strtolower((string)$this->request->getHeader('Content-Type'));
		$xRequestedWith = strtolower((string)$this->request->getHeader('X-Requested-With'));
		$wantsJson = str_contains($accept, 'application/json')
			|| str_contains($contentType, 'application/json')
			|| $xRequestedWith === 'xmlhttprequest';

		$l = $this->l10nFactory->get(Application::APP_ID);
		$message = $l->t('You do not have access to ArbeitszeitCheck. Your account is not among the users or groups allowed to use this app.');
		$hint = $l->t('Ask a Nextcloud or ArbeitszeitCheck administrator to enable the app for your account or add you to an allowed group.');

		if ($isApi || $wantsJson) {
			// `error` is surfaced verbatim in UI toasts (forms, deletion modal), so it must
			// be a human-readable, localized sentence. `code` stays machine-readable.
			return new JSONResponse([
				'ok' => false,
				'error' => $message,
				'message' => $message,
				'hint' => $hint,
				'code' => 'app_access_denied',
			], Http::STATUS_FORBIDDEN);
		}

		$response = new TemplateResponse(Application::APP_ID, 'access-denied', [
			'message' => $message,
			'hint' => $hint,
			'homeUrl' => SafeHomeUrl::resolve($this->urlGenerator),
			'l' => $l,
		]);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);

		return $response;
	}

	private function isPublicPage(ReflectionMethod $reflection): bool
	{
		if (!empty($reflection->getAttributes(PublicPage::class))) {
			return true;
		}
		return $this->reflector->hasAnnotation('PublicPage');
	}
}
