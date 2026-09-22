<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Middleware;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Controller\AdminController;
use OCA\ArbeitszeitCheck\Controller\KioskAdminController;
use OCA\ArbeitszeitCheck\Controller\LicenseAdminController;
use OCA\ArbeitszeitCheck\Controller\OvertimePayoutController;
use OCA\ArbeitszeitCheck\Exception\NotAppAdminException;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Support\SafeHomeUrl;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * App-admin gate for Administration controllers.
 *
 * Controllers listed here MUST use #[NoAdminRequired] so Nextcloud's
 * SecurityMiddleware does not require a system admin. This middleware then
 * enforces PermissionService::isAdmin() (system admin OR delegated app admin).
 *
 * OvertimePayoutController is mixed: admin workflows are gated here;
 * employee self-service (myHistory) is excluded.
 *
 * Uses instanceof (not exact class names) so PHPUnit mocks still match.
 */
final class AppAdminMiddleware extends Middleware
{
	/** @var list<string> */
	private const OVERTIME_EXCLUDED_METHODS = ['myHistory'];

	public function __construct(
		private readonly IUserSession $userSession,
		private readonly PermissionService $permissionService,
		private readonly IL10N $l10n,
		private readonly IRequest $request,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$isGated = $controller instanceof AdminController
			|| $controller instanceof LicenseAdminController
			|| $controller instanceof KioskAdminController
			|| $controller instanceof OvertimePayoutController;
		if (!$isGated) {
			return;
		}

		if (
			$controller instanceof OvertimePayoutController
			&& in_array((string)$methodName, self::OVERTIME_EXCLUDED_METHODS, true)
		) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null || !$this->permissionService->isAdmin($user->getUID())) {
			throw new NotAppAdminException($this->l10n->t('Access denied. You are not an ArbeitszeitCheck app administrator.'));
		}
	}

	public function afterException($controller, $methodName, \Exception $exception): Response
	{
		if (!$exception instanceof NotAppAdminException) {
			throw $exception;
		}

		$path = '';
		$method = 'GET';
		$accept = '';
		$contentType = '';
		$xRequestedWith = '';
		try {
			$path = (string)($this->request->getPathInfo() ?? '');
		} catch (\Throwable) {
		}
		try {
			$method = (string)$this->request->getMethod();
		} catch (\Throwable) {
			$method = 'GET';
		}
		try {
			$accept = strtolower((string)$this->request->getHeader('Accept'));
			$contentType = strtolower((string)$this->request->getHeader('Content-Type'));
			$xRequestedWith = strtolower((string)$this->request->getHeader('X-Requested-With'));
		} catch (\Throwable) {
		}

		$isApi = str_contains($path, '/api/')
			|| str_contains($path, '/ocs/')
			|| ($method !== '' && $method !== 'GET');
		$wantsJson = str_contains($accept, 'application/json')
			|| str_contains($contentType, 'application/json')
			|| $xRequestedWith === 'xmlhttprequest';

		$message = $exception->getMessage();
		if ($message === '') {
			$message = $this->l10n->t('Access denied. You are not an ArbeitszeitCheck app administrator.');
		}
		$l = $this->l10nFactory->get(Application::APP_ID);
		$hint = $l->t('Ask a Nextcloud administrator to add you under ArbeitszeitCheck → Administration → Global settings → Access, or to grant you the Nextcloud admin role.');

		if ($isApi || $wantsJson) {
			return new JSONResponse([
				'ok' => false,
				'success' => false,
				'error' => ['code' => 'admin_required', 'message' => $message],
				'message' => $message,
				'hint' => $hint,
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
}
