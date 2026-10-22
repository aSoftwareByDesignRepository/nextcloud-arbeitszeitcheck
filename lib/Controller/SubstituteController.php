<?php

declare(strict_types=1);

/**
 * Substitute controller for the arbeitszeitcheck app
 * Handles Vertretungs-Freigabe (substitute approval) workflow
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;

use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IL10N;
use OCP\Util;

/**
 * SubstituteController
 */
class SubstituteController extends Controller
{
	use CSPTrait;
	use NavigationFlagsTrait;
	use PageShellTrait;

	private AbsenceService $absenceService;
	private AbsenceMapper $absenceMapper;
	protected IUserSession $userSession;
	private IUserManager $userManager;
	protected IURLGenerator $urlGenerator;
	protected IL10N $l10n;
	protected PermissionService $permissionService;
	protected LocaleFormatService $localeFormat;
	protected NavigationFlagsService $navigationFlags;

	public function __construct(
		string $appName,
		IRequest $request,
		AbsenceService $absenceService,
		AbsenceMapper $absenceMapper,
		IUserSession $userSession,
		IUserManager $userManager,
		IURLGenerator $urlGenerator,
		CSPService $cspService,
		LocaleFormatService $localeFormat,
		IL10N $l10n,
		PermissionService $permissionService,
		NavigationFlagsService $navigationFlags,
	) {
		parent::__construct($appName, $request);
		$this->absenceService = $absenceService;
		$this->absenceMapper = $absenceMapper;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->urlGenerator = $urlGenerator;
		$this->localeFormat = $localeFormat;
		$this->l10n = $l10n;
		$this->permissionService = $permissionService;
		$this->navigationFlags = $navigationFlags;
		$this->setCspService($cspService);
	}

	private function getUserId(): string
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			throw new \Exception($this->l10n->t('User not authenticated'));
		}
		return $user->getUID();
	}

	/**
	 * Translate a service-level exception into a safe user-facing message.
	 *
	 * Business-rule errors raised by AbsenceService use plain \Exception with
	 * a localized message; {@see BusinessRuleException} is also forwarded when safe.
	 * Anything else (or any
	 * message that smells like a leak from a deeper layer) collapses to a
	 * generic localized message so we never expose stack traces, SQL state,
	 * or internal class names to end users.
	 */
	private function getSafeErrorMessage(\Throwable $e): string
	{
		$generic = $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
		$forwardUserMessage = (get_class($e) === \Exception::class) || ($e instanceof BusinessRuleException);
		if (!$forwardUserMessage) {
			return $generic;
		}
		$msg = trim((string)$e->getMessage());
		if ($msg === '' || strlen($msg) > 500) {
			return $generic;
		}
		$lower = strtolower($msg);
		$blocked = [
			'sqlstate[',
			'syntax error',
			'pdoexception',
			'doctrine\\',
			'stack trace',
			' in /var/',
			' in /home/',
			' in /usr/',
			'/lib/',
			'/vendor/',
			'oc\\',
			'oca\\',
			'ocp\\',
			'fatal error',
			'argument #',
			'must be of type',
			'must be an instance of',
			'has not been initialized',
		];
		foreach ($blocked as $needle) {
			if (str_contains($lower, $needle)) {
				return $generic;
			}
		}
		return $msg;
	}

	private function getDisplayName(string $userId): string
	{
		$user = $this->userManager->get($userId);
		return $user ? $user->getDisplayName() : $userId;
	}

	private function getTypeLabel(string $type): string
	{
		return AbsenceTypeCatalog::label($this->l10n, $type);
	}

	/**
	 * Page to view and respond to substitution requests
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		$this->registerFrontEndAssets('substitution-requests');

		try {
			$userId = $this->getUserId();
			$requests = $this->absenceMapper->findSubstitutePendingForUser($userId, 50, 0);
			$items = [];
			foreach ($requests as $absence) {
				$summary = $absence->getSummary();
				$summary['displayName'] = $this->getDisplayName($absence->getUserId());
				$items[] = $summary;
			}

			$navFlags = $this->getNavigationFlags($userId);

			$response = new TemplateResponse('arbeitszeitcheck', 'substitution-requests', $this->buildShellParams(
				'substitution-requests',
				$this->l10n->t('Substitution requests'),
				$this->l10n->t('Review and respond to colleague coverage requests'),
				$navFlags,
			) + [
				'requests' => $items,
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('SubstituteController::index failed: ' . $e->getMessage(), ['exception' => $e]);
			$navFlags = $this->getNavigationFlagsForSession();
			$user = $this->userSession->getUser();
			$errorMessage = $user === null
				? $this->l10n->t('User not authenticated')
				: $this->getSafeErrorMessage($e);
			$response = new TemplateResponse('arbeitszeitcheck', 'substitution-requests', $this->buildShellParams(
				'substitution-requests',
				$this->l10n->t('Substitution requests'),
				$this->l10n->t('Review and respond to colleague coverage requests'),
				$navFlags,
			) + [
				'requests' => [],
				'error' => $errorMessage,
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Get pending substitution requests for the current user
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getPending(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$requests = $this->absenceMapper->findSubstitutePendingForUser($userId, 50, 0);
			$items = [];
			foreach ($requests as $absence) {
				$summary = $absence->getSummary();
				$summary['displayName'] = $this->getDisplayName($absence->getUserId());
				$summary['typeLabel'] = $this->getTypeLabel($absence->getType());
				$items[] = $summary;
			}

			return new JSONResponse([
				'success' => true,
				'requests' => $items,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('SubstituteController::getPending: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Approve substitution request
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approve(int $absenceId): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->approveBySubstitute($absenceId, $userId);

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary(),
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Absence not found'),
			], Http::STATUS_NOT_FOUND);
		} catch (\Exception $e) {
			return $this->handleSubstituteException($e, 'approve', $absenceId);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('SubstituteController::approve: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Map a service-level \Exception to the right HTTP status:
	 * - "User not authenticated" -> 401 (kept generic to avoid log scraping)
	 * - Known business rule (clean message) -> 400
	 * - Anything that smells like a leak -> 500 with a generic message
	 */
	private function handleSubstituteException(\Exception $e, string $op, int $absenceId): JSONResponse
	{
		$generic = $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');

		if (strpos($e->getMessage(), 'User not authenticated') !== false) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('User not authenticated'),
			], Http::STATUS_UNAUTHORIZED);
		}

		\OCP\Log\logger('arbeitszeitcheck')->info(
			'SubstituteController::' . $op . ' business rule rejected: ' . $e->getMessage(),
			['exception' => $e, 'absenceId' => $absenceId]
		);

		$message = $this->getSafeErrorMessage($e);
		if ($message === $generic) {
			return new JSONResponse([
				'success' => false,
				'error' => $generic,
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse([
			'success' => false,
			'error' => $message,
		], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * Decline substitution request
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function decline(int $absenceId, ?string $comment = null): JSONResponse
	{
		try {
			// Read comment from POST body if not passed (JSON requests)
			if ($comment === null) {
				$params = $this->request->getParams();
				$comment = isset($params['comment']) ? (string)$params['comment'] : null;
			}
			$userId = $this->getUserId();
			$absence = $this->absenceService->declineBySubstitute($absenceId, $userId, $comment ?? '');

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary(),
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Absence not found'),
			], Http::STATUS_NOT_FOUND);
		} catch (\Exception $e) {
			return $this->handleSubstituteException($e, 'decline', $absenceId);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('SubstituteController::decline: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
