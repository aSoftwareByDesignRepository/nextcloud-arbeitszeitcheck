<?php

declare(strict_types=1);

/**
 * Absence controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
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
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private IUserManager $userManager;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		AbsenceService $absenceService,
		IUserSession $userSession,
		IURLGenerator $urlGenerator,
		IUserManager $userManager,
		CSPService $cspService,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->absenceService = $absenceService;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->setCspService($cspService);
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
		$response = new TemplateResponse(
			$this->appName,
			'absences',
			[
				'urlGenerator' => $this->urlGenerator,
				'mode' => 'create',
				'absence' => null,
				'absences' => [],
				'stats' => []
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
						'error' => 'Absence not found'
					],
					'blank'
				);
				return $this->configureCSP($response);
			}

			$response = new TemplateResponse(
				$this->appName,
				'absences',
				[
					'urlGenerator' => $this->urlGenerator,
					'mode' => 'edit',
					'absence' => $absence,
					'absences' => [],
					'stats' => []
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
					'error' => $this->l10n->t('Absence not found')
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
					'error' => $e->getMessage()
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
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function store(): JSONResponse
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
	 * Handles POST requests for updating absences. Delegates to the update() method.
	 *
	 * @param int $id Absence ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function updatePost(int $id): JSONResponse
	{
		$params = $this->request->getParams();
		$start_date = $params['start_date'] ?? null;
		$end_date = $params['end_date'] ?? null;
		$reason = $params['reason'] ?? null;

		return $this->update($id, $start_date, $end_date, $reason);
	}

	/**
	 * Update absence endpoint
	 *
	 * @param int $id Absence ID
	 * @param string|null $start_date New start date
	 * @param string|null $end_date New end date
	 * @param string|null $reason New reason
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function update(int $id, ?string $start_date = null, ?string $end_date = null, ?string $reason = null): JSONResponse
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

			$absence = $this->absenceService->updateAbsence($id, $data, $userId);

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
	 * Approve absence endpoint
	 *
	 * @param int $id Absence ID
	 * @param string|null $comment Approval comment
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function approve(int $id, ?string $comment = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->approveAbsence($id, $userId, $comment);

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
	 * Reject absence endpoint
	 *
	 * @param int $id Absence ID
	 * @param string|null $comment Rejection comment
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function reject(int $id, ?string $comment = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$absence = $this->absenceService->rejectAbsence($id, $userId, $comment);

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
	 * Get users list (for absence assignment etc.)
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function users(): JSONResponse
	{
		try {
			// Get all users from Nextcloud
			$users = $this->userManager->search('', null, 0);

			$usersData = [];
			foreach ($users as $user) {
				if (!$user->isEnabled()) {
					continue;
				}
				$usersData[] = [
					'userId' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
					'display_name' => $user->getDisplayName()
				];
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