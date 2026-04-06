<?php

declare(strict_types=1);

/**
 * Absence controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
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
use OCP\IConfig;
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
	private IConfig $config;

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
		IL10N $l10n,
		IConfig $config
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
		$this->config = $config;
		$this->setCspService($cspService);
	}

	/**
	 * Absence types for which a substitute must be designated (from admin config).
	 *
	 * @return string[]
	 */
	private function getRequireSubstituteTypes(): array
	{
		$json = $this->config->getAppValue('arbeitszeitcheck', 'require_substitute_types', '[]');
		$arr = json_decode($json, true);
		return is_array($arr) ? $arr : [];
	}

	/**
	 * Get a safe user-facing error message from an exception.
	 * Business logic exceptions (\Exception) contain user-safe messages; other Throwables use generic text.
	 */
	private function getSafeErrorMessage(\Throwable $e): string
	{
		if ($e instanceof \Exception && $e->getMessage() !== '') {
			return $e->getMessage();
		}
		return $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
	}

	/**
	 * Whether the request expects a JSON response (AJAX/API).
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
	 * Colleague list for substitute dropdown (same data as users() API).
	 * Built on page load so the form works even if the API request fails.
	 *
	 * @return list<array{userId: string, displayName: string, display_name: string}>
	 */
	private function getColleaguesForSubstitute(string $userId): array
	{
		$colleagueIds = $this->teamResolver->getColleagueIds($userId);
		\OCP\Log\logger('arbeitszeitcheck')->debug(
			'[Vertretung] getColleaguesForSubstitute userId=' . $userId . ' colleagueIds=' . count($colleagueIds) . ' ' . json_encode($colleagueIds),
			['app' => 'arbeitszeitcheck']
		);
		$list = [];
		foreach ($colleagueIds as $uid) {
			$user = $this->userManager->get($uid);
			if ($user !== null && $user->isEnabled()) {
				$displayName = $user->getDisplayName();
				$list[] = [
					'userId' => $user->getUID(),
					'displayName' => $displayName ?? $user->getUID(),
					'display_name' => $displayName ?? $user->getUID(),
				];
			} else {
				\OCP\Log\logger('arbeitszeitcheck')->debug(
					'[Vertretung] getColleaguesForSubstitute uid=' . $uid . ' skipped (user=null or disabled)',
					['app' => 'arbeitszeitcheck']
				);
			}
		}
		\OCP\Log\logger('arbeitszeitcheck')->debug(
			'[Vertretung] getColleaguesForSubstitute returning ' . count($list) . ' colleagues',
			['app' => 'arbeitszeitcheck']
		);
		return $list;
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
	 * API: Get absence by ID (JSON)
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function apiShow(int $id): JSONResponse
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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_BAD_REQUEST);
		}
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
	 * Cancel absence (set status to cancelled without deleting record).
	 *
	 * This is intended for employees to cancel their own future absences.
	 * The underlying service enforces that:
	 * - only the owner can cancel their own absences, and
	 * - the absence has not started yet.
	 *
	 * When called via form POST (e.g. from absence details page), redirects to
	 * absences list. When called via API with Accept: application/json, returns JSON.
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse|RedirectResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function cancel(int $id): JSONResponse|RedirectResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->cancelAbsence($id, $userId);

			if (!$this->wantsJson()) {
				$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.absences') . '?cancelled=1';
				return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
			}
			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (\Throwable $e) {
			$msg = $this->getSafeErrorMessage($e);
			if (!$this->wantsJson()) {
				$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.absences') . '?error=' . rawurlencode($msg);
				return new RedirectResponse($url, Http::STATUS_SEE_OTHER);
			}
			return new JSONResponse([
				'success' => false,
				'error' => $msg
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * API: Shorten absence (early return). Accepts JSON or form-encoded end_date.
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function shorten(int $id): JSONResponse
	{
		$params = $this->request->getParams();
		$endDate = isset($params['end_date']) ? trim((string)$params['end_date']) : '';

		if ($endDate === '') {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('New end date is required.')
			], Http::STATUS_BAD_REQUEST);
		}

		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->shortenAbsence($id, $userId, $endDate);

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->getSafeErrorMessage($e)
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Form POST: Shorten absence (early return). Redirects back to show page.
	 *
	 * @param int $id Absence ID
	 * @return RedirectResponse|TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function shortenForm(int $id)
	{
		$endDate = trim((string)($this->request->getParam('end_date') ?? ''));

		if ($endDate === '') {
			$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.show', ['id' => $id]);
			return new RedirectResponse($url . '?shorten_error=' . rawurlencode($this->l10n->t('New end date is required.')), Http::STATUS_SEE_OTHER);
		}

		try {
			$userId = $this->getUserId();
			$this->absenceService->shortenAbsence($id, $userId, $endDate);
			$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.show', ['id' => $id]);
			return new RedirectResponse($url . '?shortened=1', Http::STATUS_SEE_OTHER);
		} catch (\Throwable $e) {
			$url = $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.show', ['id' => $id]);
			return new RedirectResponse($url . '?shorten_error=' . rawurlencode($this->getSafeErrorMessage($e)), Http::STATUS_SEE_OTHER);
		}
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
	public function index_api(?string $status = null, ?string $type = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0): JSONResponse
	{
		return $this->index($status, $type, $limit, $offset);
	}

	/**
	 * Legacy API (CamelCase alias): Nextcloud routes may call `indexApi()` when the route is defined as `index_api`.
	 */
	#[NoAdminRequired]
	public function indexApi(?string $status = null, ?string $type = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0): JSONResponse
	{
		return $this->index_api($status, $type, $limit, $offset);
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
	public function index(?string $status = null, ?string $type = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$limit = $limit !== null ? min(max(1, (int)$limit), Constants::MAX_LIST_LIMIT) : Constants::DEFAULT_LIST_LIMIT;
			$offset = $offset !== null ? max(0, (int)$offset) : 0;
			$filters = [];

			if ($status) {
				$filters['status'] = $status;
			}
			if ($type) {
				$filters['type'] = $type;
			}

			$absences = $this->absenceService->getAbsencesByUser($userId, $filters, $limit, $offset);

			// Also include absences where the current user is configured as substitute,
			// so upcoming coverages appear in calendar and timeline views.
			$coverageAbsences = $this->absenceMapper->findBySubstituteUser($userId);
			$coverageAbsences = array_filter($coverageAbsences, function (Absence $a) use ($status, $type): bool {
				// Only show absences that the user is actually covering or will cover:
				// - pending: substitute has already approved, waiting for manager
				// - approved: fully approved absence
				if (!in_array($a->getStatus(), [Absence::STATUS_PENDING, Absence::STATUS_APPROVED], true)) {
					return false;
				}
				if ($type !== null && $type !== '' && $a->getType() !== $type) {
					return false;
				}
				if ($status !== null && $status !== '') {
					if ($status === 'pending') {
						// "pending" means awaiting any approval – for substitute role we
						// restrict this to the manager-pending state.
						return $a->getStatus() === Absence::STATUS_PENDING;
					}
					return $a->getStatus() === $status;
				}
				return true;
			});

			// Safely map absences to summaries, handling any potential null DateTime issues.
			// Track IDs so we don't accidentally duplicate entries if business rules change.
			$absenceSummaries = [];
			$seenIds = [];

			foreach ($absences as $absence) {
				try {
					$summary = $absence->getSummary();
					$absenceSummaries[] = $summary;
					if (isset($summary['id'])) {
						$seenIds[(int)$summary['id']] = true;
					}
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for absence ' . $absence->getId() . ': ' . $e->getMessage(), ["exception" => $e]);
					continue;
				}
			}

			// Add substitute-role absences with a small "role" flag and owner display name for frontend.
			foreach ($coverageAbsences as $absence) {
				$id = $absence->getId();
				if ($id !== null && isset($seenIds[(int)$id])) {
					continue;
				}
				try {
					$summary = $absence->getSummary();
					$summary['role'] = 'substitute';
					$owner = $this->userManager->get($absence->getUserId());
					$summary['ownerDisplayName'] = $owner !== null ? $owner->getDisplayName() : $absence->getUserId();
					$absenceSummaries[] = $summary;
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for substitute absence ' . $absence->getId() . ': ' . $e->getMessage(), ["exception" => $e]);
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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
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
		$colleagues = $this->getColleaguesForSubstitute($userId);
		$hasColleagues = count($colleagues) > 0;
		$requireSubstituteTypes = $this->getRequireSubstituteTypes();

		\OCP\Log\logger('arbeitszeitcheck')->info(
			'[Vertretung] create() userId=' . $userId . ' colleagues=' . count($colleagues) . ' hasColleagues=' . ($hasColleagues ? '1' : '0'),
			['app' => 'arbeitszeitcheck']
		);

		$response = new TemplateResponse(
			$this->appName,
			'absences',
			[
				'urlGenerator' => $this->urlGenerator,
				'mode' => 'create',
				'absence' => null,
				'absences' => [],
				'hasColleagues' => $hasColleagues,
				'requireSubstituteTypes' => $requireSubstituteTypes,
				'stats' => [],
				'currentUserId' => $userId,
				'colleagues' => $colleagues,
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
						'error' => $this->l10n->t('Absence not found'),
						'l' => $this->l10n,
					],
					'blank'
				);
				return $this->configureCSP($response);
			}

			$userId = $this->getUserId();
			$colleagues = $this->getColleaguesForSubstitute($userId);
			$hasColleagues = count($colleagues) > 0;
			$requireSubstituteTypes = $this->getRequireSubstituteTypes();

			$response = new TemplateResponse(
				$this->appName,
				'absences',
				[
					'urlGenerator' => $this->urlGenerator,
					'mode' => 'edit',
					'absence' => $absence,
					'absences' => [],
					'hasColleagues' => $hasColleagues,
					'requireSubstituteTypes' => $requireSubstituteTypes,
					'stats' => [],
					'currentUserId' => $userId,
					'colleagues' => $colleagues,
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
					'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
					'l' => $this->l10n,
				],
				'blank'
			);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Get absence details page (HTML view)
	 *
	 * @param int $id Absence ID
	 * @return TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(int $id): TemplateResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->getAbsence($id, $userId);

			if (!$absence) {
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
			}

			$colleagues = $this->getColleaguesForSubstitute($userId);
			$hasColleagues = count($colleagues) > 0;

			$substituteDisplayName = null;
			$subId = $absence->getSubstituteUserId();
			if ($subId !== null && $subId !== '') {
				$subUser = $this->userManager->get($subId);
				$substituteDisplayName = $subUser !== null ? $subUser->getDisplayName() : $subId;
			}

			// Precompute working days when days=NULL (HolidayService, state-aware)
			$displayDays = $absence->getDays() !== null
				? (float)$absence->getDays()
				: $this->absenceService->getWorkingDaysForDisplay($absence);

			$response = new TemplateResponse(
				$this->appName,
				'absences',
				[
					'urlGenerator' => $this->urlGenerator,
					'mode' => 'view',
					'absence' => $absence,
					'displayDays' => $displayDays,
					'computedWorkingDays' => [],
					'absences' => [],
					'hasColleagues' => $hasColleagues,
					'colleagues' => $colleagues,
					'substituteDisplayName' => $substituteDisplayName,
					'stats' => [],
					'currentUserId' => $userId,
					'usersUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.absence.users'),
					'l' => $this->l10n,
				]
			);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			$response = new TemplateResponse(
				$this->appName,
				'absences',
				[
					'urlGenerator' => $this->urlGenerator,
					'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
					'l' => $this->l10n,
				],
				'blank'
			);
			return $this->configureCSP($response);
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
		} catch (\Exception $e) {
			$msg = trim((string)$e->getMessage());
			$error = $msg !== '' ? $msg : $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
			return new JSONResponse(['success' => false, 'error' => $error], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
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
		} catch (\Exception $e) {
			$msg = trim((string)$e->getMessage());
			$error = $msg !== '' ? $msg : $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
			return new JSONResponse(['success' => false, 'error' => $error], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
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
			if ($id <= 0) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid absence ID')
				], Http::STATUS_BAD_REQUEST);
			}

			$userId = $this->getUserId();

			// First, distinguish between "not found" and "forbidden" using the mapper
			try {
				$absence = $this->absenceMapper->find($id);
			} catch (DoesNotExistException $e) {
				// Idempotent behaviour: deleting a non-existing absence returns 404,
				// so API clients can distinguish this from success.
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Absence not found')
				], Http::STATUS_NOT_FOUND);
			}

			if ($absence->getUserId() !== $userId) {
				// Do not leak whether the ID belongs to another user; just deny access.
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied')
				], Http::STATUS_FORBIDDEN);
			}

			// Delegate business rules (pending/substitute_pending only, etc.) to the service.
			try {
				$this->absenceService->deleteAbsence($id, $userId);
			} catch (\Exception $e) {
				$message = trim($e->getMessage());
				// Map known state-related messages to 409 Conflict to signal that the
				// resource exists but is not deletable in its current state.
				if ($message === $this->l10n->t('Only pending absences can be deleted')) {
					return new JSONResponse([
						'success' => false,
						'error' => $message
					], Http::STATUS_CONFLICT);
				}

				// Fallback: bad request with the service's message.
				return new JSONResponse([
					'success' => false,
					'error' => $message !== '' ? $message : $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
				], Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse([
				'success' => true
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
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
					$displayName = $user->getDisplayName();
					$usersData[] = [
						'userId' => $user->getUID(),
						'displayName' => $displayName ?? $user->getUID(),
						'display_name' => $displayName ?? $user->getUID(),
					];
				}
			}

			return new JSONResponse([
				'success' => true,
				'users' => $usersData,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Failed to load colleague list for substitute selection: ' . $e->getMessage(),
				[
					'app' => 'arbeitszeitcheck',
					'exception' => $e,
					'userId' => $this->userSession->getUser()?->getUID() ?? 'unknown',
				]
			);
			// Return empty list so the UI stays usable; user sees "None" and empty-state message
			return new JSONResponse([
				'success' => true,
				'users' => [],
			]);
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
					'entitlement' => $stats['entitlement'],
					'total_available' => $stats['total_available'] ?? null,
					'carryover_days' => $stats['carryover_days'] ?? 0,
					'carryover_usable' => $stats['carryover_usable'] ?? 0,
					'carryover_expires_on' => $stats['carryover_expires_on'] ?? null,
					'remaining' => $stats['remaining'],
				],
				'sickLeaveStats' => [
					'days' => $stats['sick_days']
				]
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AbsenceController::stats: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}