<?php

declare(strict_types=1);

/**
 * Absence controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IURLGenerator;
use OCP\IL10N;

/**
 * AbsenceController
 */
class AbsenceController extends Controller
{
	use CSPTrait;

	private AbsenceService $absenceService;
	private AbsenceMapper $absenceMapper;
	private PermissionService $permissionService;
	private TeamResolverService $teamResolver;
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private IUserManager $userManager;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		AbsenceService $absenceService,
		AbsenceMapper $absenceMapper,
		PermissionService $permissionService,
		TeamResolverService $teamResolver,
		IUserSession $userSession,
		IURLGenerator $urlGenerator,
		IUserManager $userManager,
		CSPService $cspService,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->absenceService = $absenceService;
		$this->absenceMapper = $absenceMapper;
		$this->permissionService = $permissionService;
		$this->teamResolver = $teamResolver;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->setCspService($cspService);
	}

	/**
	 * Whether the request expects a JSON response (AJAX/API).
	 * When false, success responses use redirect instead of JSON so the user never sees raw JSON.
	 *
	 * @return bool
	 */
	private function wantsJson(): bool
	{
		$accept = $this->request->getHeader('Accept');
		$contentType = $this->request->getHeader('Content-Type');
		return str_contains($accept, 'application/json') || str_contains($contentType, 'application/json');
	}

	/**
	 * Get current user ID
	 *
	 * @return string
	 */
	private function getUserId(): string
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			throw new \Exception('User not authenticated');
		}
		return $user->getUID();
	}

	/**
	 * API: Create absence (delegates to store)
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function apiStore(): JSONResponse
	{
		return $this->store();
	}

	/**
	 * API: Get absence by ID (delegates to show)
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function apiShow(int $id): JSONResponse
	{
		return $this->show($id);
	}

	/**
	 * API: Update absence (delegates to update)
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function apiUpdate(int $id): JSONResponse
	{
		$params = $this->request->getParams();
		$sub = $params['substitute_user_id'] ?? null;
		return $this->update(
			$id,
			$params['start_date'] ?? null,
			$params['end_date'] ?? null,
			$params['reason'] ?? null,
			$sub !== null ? (string)$sub : null
		);
	}

	/**
	 * API: Delete absence (delegates to delete)
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function apiDelete(int $id): JSONResponse
	{
		return $this->delete($id);
	}

	/**
	 * Legacy API: Get absences (alias for index)
	 *
	 * Legacy endpoint for backward compatibility. Delegates to the index() method.
	 *
	 *
	 * @param string|null $status Filter by status
	 * @param string|null $type Filter by type
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function index_api(?string $status = null, ?string $type = null, ?int $limit = 25, ?int $offset = 0): JSONResponse
	{
		return $this->index($status, $type, $limit, $offset);
	}

	/**
	 * Get absences endpoint
	 *
	 *
	 * @param string|null $status Filter by status
	 * @param string|null $type Filter by type
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function index(?string $status = null, ?string $type = null, ?int $limit = 25, ?int $offset = 0): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$limit = $limit !== null ? min(max(1, (int)$limit), 500) : 25;
			$offset = $offset !== null ? max(0, (int)$offset) : 0;
			$filters = [];

			if ($status) {
				$filters['status'] = $status;
			}
			if ($type) {
				$filters['type'] = $type;
			}

			$absences = $this->absenceService->getAbsencesByUser($userId, $filters, $limit, $offset);

			// Safely map absences to summaries, handling any potential null DateTime issues
			$absenceSummaries = [];
			foreach ($absences as $absence) {
				try {
					$absenceSummaries[] = $absence->getSummary();
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for absence ' . $absence->getId() . ': ' . $e->getMessage(), ["exception" => $e]);
					continue;
				}
			}

			return new JSONResponse([
				'success' => true,
				'absences' => $absenceSummaries
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Show create absence form page
	 *
	 * @return TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): TemplateResponse
	{
		$userId = $this->getUserId();
		$colleagueIds = $this->teamResolver->getColleagueIds($userId);
		$hasColleagues = count($colleagueIds) > 0;

		$response = new TemplateResponse(
			$this->appName,
			'absences',
			[
				'urlGenerator' => $this->urlGenerator,
				'mode' => 'create',
				'absence' => null,
				'absences' => [],
				'hasColleagues' => $hasColleagues,
				'stats' => [],
				'currentUserId' => $userId,
				'usersUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.users'),
				'l' => $this->l10n,
			]
		);
		return $this->configureCSP($response);
	}

	/**
	 * Show edit absence form page
	 *
	 * @param int $id Absence ID
	 * @return TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function edit(int $id): TemplateResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->getAbsence($id, $userId);

			if (!$absence) {
				// Redirect to absences list if not found
				$response = new TemplateResponse(
					$this->appName,
					'absences',
					[
						'urlGenerator' => $this->urlGenerator,
						'error' => 'Absence not found',
						'l' => $this->l10n,
					],
					'blank'
				);
				return $this->configureCSP($response);
			}

			$userId = $this->getUserId();
			$colleagueIds = $this->teamResolver->getColleagueIds($userId);
			$hasColleagues = count($colleagueIds) > 0;

			$response = new TemplateResponse(
				$this->appName,
				'absences',
				[
					'urlGenerator' => $this->urlGenerator,
					'mode' => 'edit',
					'absence' => $absence,
					'absences' => [],
					'hasColleagues' => $hasColleagues,
					'stats' => [],
					'currentUserId' => $userId,
					'usersUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.users'),
					'l' => $this->l10n,
				]
			);
			return $this->configureCSP($response);
		} catch (DoesNotExistException $e) {
			// Absence not found - redirect to absences list
			$response = new TemplateResponse(
				$this->appName,
				'absences',
				[
					'urlGenerator' => $this->urlGenerator,
					'error' => $this->l10n->t('Absence not found'),
					'l' => $this->l10n,
				],
				'blank'
			);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			// Redirect to absences list on error
			$response = new TemplateResponse(
				$this->appName,
				'absences',
				[
					'urlGenerator' => $this->urlGenerator,
					'error' => $e->getMessage(),
					'l' => $this->l10n,
				],
				'blank'
			);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Get absence by ID endpoint
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->getAbsence($id, $userId);

			if (!$absence) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Absence not found')
				], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Create absence endpoint
	 *
	 * NoCSRFRequired: JSON POST body is not decoded before CSRF check, so requesttoken in body/header is not seen. Session still required.
	 * When request does not expect JSON (e.g. form POST without JS), returns redirect so the user never sees raw JSON.
	 *
	 * @return JSONResponse|RedirectResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function store(): JSONResponse|RedirectResponse
	{
		try {
			$userId = $this->getUserId();
			
			// Get data from request body
			$params = $this->request->getParams();
			
			// Ensure type is a string (handle case where it might be an array)
			$type = $params['type'] ?? '';
			if (is_array($type)) {
				$type = !empty($type) ? (string)reset($type) : '';
			} else {
				$type = (string)$type;
			}
			
			$data = [
				'type' => $type,
				'start_date' => is_array($params['start_date'] ?? '') ? (string)reset($params['start_date']) : (string)($params['start_date'] ?? ''),
				'end_date' => is_array($params['end_date'] ?? '') ? (string)reset($params['end_date']) : (string)($params['end_date'] ?? ''),
				'reason' => is_array($params['reason'] ?? null) ? (string)reset($params['reason']) : ($params['reason'] ?? null),
				'substitute_user_id' => is_array($params['substitute_user_id'] ?? null) ? (string)reset($params['substitute_user_id']) : ($params['substitute_user_id'] ?? null)
			];

			// Validate required fields
			if (empty($data['type']) || empty($data['start_date']) || empty($data['end_date'])) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Type, start_date, and end_date are required')
				], Http::STATUS_BAD_REQUEST);
			}

			$absence = $this->absenceService->createAbsence($data, $userId);

			if (!$this->wantsJson()) {
				$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.absences') . '?created=1';
				return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
			}
			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Update absence endpoint (POST method for form submissions)
	 *
	 * NoCSRFRequired: same as store(); JSON body not decoded before CSRF check.
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse|RedirectResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updatePost(int $id): JSONResponse|RedirectResponse
	{
		$params = $this->request->getParams();
		$start_date = $params['start_date'] ?? null;
		$end_date = $params['end_date'] ?? null;
		$reason = $params['reason'] ?? null;
		$substitute_user_id = isset($params['substitute_user_id']) ? (string)$params['substitute_user_id'] : null;

		return $this->update($id, $start_date, $end_date, $reason, $substitute_user_id);
	}

	/**
	 * Update absence endpoint
	 *
	 * When request does not expect JSON, returns redirect so the user never sees raw JSON.
	 *
	 * @param int $id Absence ID
	 * @param string|null $start_date New start date
	 * @param string|null $end_date New end date
	 * @param string|null $reason New reason
	 * @param string|null $substitute_user_id New substitute user ID (empty to clear)
	 * @return JSONResponse|RedirectResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(int $id, ?string $start_date = null, ?string $end_date = null, ?string $reason = null, ?string $substitute_user_id = null): JSONResponse|RedirectResponse
	{
		try {
			$userId = $this->getUserId();
			$data = [];

			if ($start_date) {
				$data['start_date'] = $start_date;
			}
			if ($end_date) {
				$data['end_date'] = $end_date;
			}
			if ($reason !== null) {
				$data['reason'] = $reason;
			}
			if ($substitute_user_id !== null) {
				$data['substitute_user_id'] = $substitute_user_id === '' ? null : $substitute_user_id;
			}

			$absence = $this->absenceService->updateAbsence($id, $data, $userId);

			if (!$this->wantsJson()) {
				$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.absences') . '?updated=1';
				return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
			}
			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete absence endpoint
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function delete(int $id): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$this->absenceService->deleteAbsence($id, $userId);

			return new JSONResponse([
				'success' => true
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Approve absence endpoint.
	 * Only users who can manage the absence owner (same-group team) may approve.
	 *
	 * @param int $id Absence ID
	 * @param string|null $comment Approval comment
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function approve(int $id, ?string $comment = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceMapper->find($id);
			if (!$this->permissionService->canManageEmployee($userId, $absence->getUserId())) {
				$this->permissionService->logPermissionDenied($userId, 'approve_absence', 'absence', (string) $id);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only approve absences for members of your team.')
				], Http::STATUS_FORBIDDEN);
			}
			$absence = $this->absenceService->approveAbsence($id, $userId, $comment);

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Absence not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Reject absence endpoint.
	 * Only users who can manage the absence owner (same-group team) may reject.
	 *
	 * @param int $id Absence ID
	 * @param string|null $comment Rejection comment
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reject(int $id, ?string $comment = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceMapper->find($id);
			if (!$this->permissionService->canManageEmployee($userId, $absence->getUserId())) {
				$this->permissionService->logPermissionDenied($userId, 'reject_absence', 'absence', (string) $id);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only reject absences for members of your team.')
				], Http::STATUS_FORBIDDEN);
			}
			$absence = $this->absenceService->rejectAbsence($id, $userId, $comment);

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Absence not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Get substitute candidates (colleagues in same team/group).
	 * Restricted to team members for data minimization and security.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function users(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$colleagueIds = $this->teamResolver->getColleagueIds($userId);

			$usersData = [];
			foreach ($colleagueIds as $uid) {
				$user = $this->userManager->get($uid);
				if ($user !== null && $user->isEnabled()) {
					$usersData[] = [
						'userId' => $user->getUID(),
						'displayName' => $user->getDisplayName(),
						'display_name' => $user->getDisplayName()
					];
				}
			}

			return new JSONResponse([
				'success' => true,
				'users' => $usersData
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get absence statistics endpoint
	 *
	 * @param int|null $year Year for statistics (defaults to current year)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function stats(?int $year = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			if ($year === null) {
				$year = (int)date('Y');
			}

			$stats = $this->absenceService->getVacationStats($userId, $year);

			return new JSONResponse([
				'success' => true,
				'vacationStats' => [
					'used' => $stats['used'],
					'total' => $stats['entitlement'],
					'remaining' => $stats['remaining']
				],
				'sickLeaveStats' => [
					'days' => $stats['sick_days']
				]
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AbsenceController::stats: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}