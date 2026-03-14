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

use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\PermissionService;
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

	private AbsenceService $absenceService;
	private AbsenceMapper $absenceMapper;
	private IUserSession $userSession;
	private IUserManager $userManager;
	private IURLGenerator $urlGenerator;
	private IL10N $l10n;
	private PermissionService $permissionService;

	public function __construct(
		string $appName,
		IRequest $request,
		AbsenceService $absenceService,
		AbsenceMapper $absenceMapper,
		IUserSession $userSession,
		IUserManager $userManager,
		IURLGenerator $urlGenerator,
		CSPService $cspService,
		IL10N $l10n,
		PermissionService $permissionService
	) {
		parent::__construct($appName, $request);
		$this->absenceService = $absenceService;
		$this->absenceMapper = $absenceMapper;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->urlGenerator = $urlGenerator;
		$this->l10n = $l10n;
		$this->permissionService = $permissionService;
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

	private function getDisplayName(string $userId): string
	{
		$user = $this->userManager->get($userId);
		return $user ? $user->getDisplayName() : $userId;
	}

	private function getTypeLabel(string $type): string
	{
		$map = [
			'vacation' => $this->l10n->t('Vacation'),
			'sick_leave' => $this->l10n->t('Sick Leave'),
			'personal_leave' => $this->l10n->t('Personal Leave'),
			'parental_leave' => $this->l10n->t('Parental Leave'),
			'special_leave' => $this->l10n->t('Special Leave'),
			'unpaid_leave' => $this->l10n->t('Unpaid Leave'),
			'home_office' => $this->l10n->t('Home Office'),
			'business_trip' => $this->l10n->t('Business Trip'),
		];
		return $map[$type] ?? $type;
	}

	/**
	 * Page to view and respond to substitution requests
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');
		Util::addStyle('arbeitszeitcheck', 'common/colors');
		Util::addStyle('arbeitszeitcheck', 'common/typography');
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'common/accessibility');
		Util::addStyle('arbeitszeitcheck', 'common/app-layout');
		Util::addStyle('arbeitszeitcheck', 'common/responsive');
		Util::addStyle('arbeitszeitcheck', 'navigation');
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'substitution-requests');

		try {
			$userId = $this->getUserId();
			$requests = $this->absenceMapper->findSubstitutePendingForUser($userId, 50, 0);
			$items = [];
			foreach ($requests as $absence) {
				$summary = $absence->getSummary();
				$summary['displayName'] = $this->getDisplayName($absence->getUserId());
				$items[] = $summary;
			}

			$response = new TemplateResponse('arbeitszeitcheck', 'substitution-requests', [
				'requests' => $items,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
				// Navigation flags
				'showSubstitutionLink' => \count($requests) > 0,
				'showManagerLink' => $this->permissionService->canAccessManagerDashboard($userId),
				'showReportsLink' => $this->permissionService->canAccessManagerDashboard($userId) || $this->permissionService->isAdmin($userId),
				'showAdminNav' => $this->permissionService->isAdmin($userId),
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			$response = new TemplateResponse('arbeitszeitcheck', 'substitution-requests', [
				'requests' => [],
				'urlGenerator' => $this->urlGenerator,
				'error' => $e->getMessage(),
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Get pending substitution requests for the current user
	 */
	#[NoAdminRequired]
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
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('SubstituteController::approve: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
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
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('SubstituteController::decline: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
