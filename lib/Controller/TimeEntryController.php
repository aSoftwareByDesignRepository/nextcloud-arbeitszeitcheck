<?php

declare(strict_types=1);

/**
 * TimeEntry controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Controller;
use OCP\IConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\IL10N;

/**
 * TimeEntryController
 */
class TimeEntryController extends Controller
{
	use CSPTrait;

	private TimeEntryMapper $timeEntryMapper;
	private IUserSession $userSession;
	private \OCA\ArbeitszeitCheck\Service\OvertimeService $overtimeService;
	private IURLGenerator $urlGenerator;
	private IL10N $l10n;
	private AuditLogMapper $auditLogMapper;
	private ?ComplianceService $complianceService;
	private IConfig $config;
	private ?TimeTrackingService $timeTrackingService;

	public function __construct(
		string $appName,
		IRequest $request,
		TimeEntryMapper $timeEntryMapper,
		IUserSession $userSession,
		\OCA\ArbeitszeitCheck\Service\OvertimeService $overtimeService,
		IURLGenerator $urlGenerator,
		IL10N $l10n,
		AuditLogMapper $auditLogMapper,
		IConfig $config,
		CSPService $cspService,
		?ComplianceService $complianceService = null,
		?TimeTrackingService $timeTrackingService = null
	) {
		parent::__construct($appName, $request);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->userSession = $userSession;
		$this->overtimeService = $overtimeService;
		$this->urlGenerator = $urlGenerator;
		$this->l10n = $l10n;
		$this->auditLogMapper = $auditLogMapper;
		$this->config = $config;
		$this->setCspService($cspService);
		$this->complianceService = $complianceService;
		$this->timeTrackingService = $timeTrackingService;
	}

	/**
	 * Get current user ID from session
	 *
	 * @return string Current user's UID
	 * @throws \Exception If user is not authenticated
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
	 * Parse date string - supports both ISO (yyyy-mm-dd) and German format (dd.mm.yyyy)
	 *
	 * @param string $dateString Date string in either format
	 * @return \DateTime
	 * @throws \Exception if date cannot be parsed
	 */
	private function parseDate(string $dateString): \DateTime
	{
		// Try German format first (dd.mm.yyyy)
		if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateString, $matches)) {
			$day = (int)$matches[1];
			$month = (int)$matches[2];
			$year = (int)$matches[3];

			// Validate date
			if (!checkdate($month, $day, $year)) {
				throw new \Exception($this->l10n->t('Invalid date: %s', [$dateString]));
			}

			return new \DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
		}

		// Try ISO format (yyyy-mm-dd)
		try {
			return new \DateTime($dateString);
		} catch (\Throwable $e) {
			throw new \Exception($this->l10n->t('Invalid date format. Expected yyyy-mm-dd or dd.mm.yyyy: %s', [$dateString]));
		}
	}

	/**
	 * Get time entries endpoint
	 *
	 * Retrieves time entries for the current user with optional filtering by date range and status.
	 * Supports pagination for large datasets.
	 *
	 * @NoAdminRequired
	 * @param string|null $start_date Start date filter (Y-m-d format)
	 * @param string|null $end_date End date filter (Y-m-d format)
	 * @param string|null $status Status filter (active, completed, break, pending_approval, rejected)
	 * @param int|null $limit Maximum number of entries to return (default: 25)
	 * @param int|null $offset Number of entries to skip for pagination (default: 0)
	 * @return JSONResponse JSON response with 'success', 'entries' array, and 'total' count
	 */
	public function index(?string $start_date = null, ?string $end_date = null, ?string $status = null, ?int $limit = 25, ?int $offset = 0): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$filters = [];

			if ($start_date) {
				$filters['start_date'] = $start_date;
			}
			if ($end_date) {
				$filters['end_date'] = $end_date;
			}
			if ($status) {
				$filters['status'] = $status;
			}
			if ($limit) {
				$filters['limit'] = $limit;
			}
			if ($offset) {
				$filters['offset'] = $offset;
			}

			// Build filters array for mapper count method (uses database filtering)
			$countFilters = ['user_id' => $userId];
			if ($start_date) {
				try {
					$countFilters['start_date'] = new \DateTime($start_date);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Invalid start_date format: ' . $start_date, ['exception' => $e]);
					return new JSONResponse([
						'success' => false,
						'error' => 'Invalid start date format'
					], Http::STATUS_BAD_REQUEST);
				}
			}
			if ($end_date) {
				try {
					$countFilters['end_date'] = new \DateTime($end_date);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Invalid end_date format: ' . $end_date, ['exception' => $e]);
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid end date format')
					], Http::STATUS_BAD_REQUEST);
				}
			}
			if ($status) {
				$countFilters['status'] = $status;
			}

			// Get total count for pagination using mapper's count method (efficient database query)
			try {
				$totalCount = $this->timeEntryMapper->count($countFilters);
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error counting time entries: ' . $e->getMessage(), ['exception' => $e]);
				$totalCount = 0;
			}

			// Use findByUserAndDateRange when date filters are provided, otherwise use findByUser
			// Wrap in try-catch to handle any entity mapping errors
			try {
				if ($start_date || $end_date) {
					try {
						$startDateTime = $start_date ? new \DateTime($start_date) : new \DateTime('1970-01-01');
						$startDateTime->setTime(0, 0, 0);
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->error('Invalid start_date format: ' . $start_date, ['exception' => $e]);
						return new JSONResponse([
							'success' => false,
							'error' => 'Invalid start date format'
						], Http::STATUS_BAD_REQUEST);
					}
					try {
						$endDateTime = $end_date ? new \DateTime($end_date) : new \DateTime('2099-12-31');
						$endDateTime->setTime(23, 59, 59);
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->error('Invalid end_date format: ' . $end_date, ['exception' => $e]);
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Invalid end date format')
						], Http::STATUS_BAD_REQUEST);
					}
					$allEntries = $this->timeEntryMapper->findByUserAndDateRange($userId, $startDateTime, $endDateTime);
				} else {
					$allEntries = $this->timeEntryMapper->findByUser($userId);
				}
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error loading time entries for user ' . $userId, ['exception' => $e]);
				// Return empty array instead of failing completely
				$allEntries = [];
			}

			// Apply status filter if provided (date filters already applied via findByUserAndDateRange)
			if ($status) {
				$allEntries = array_filter($allEntries, function ($entry) use ($status) {
					return $entry->getStatus() === $status;
				});
			}

			// Apply pagination to filtered entries
			$entries = array_slice($allEntries, $offset ?? 0, $limit ?? 25);

			// Safely map entries to summaries, handling any potential null DateTime issues
			$entrySummaries = [];
			foreach ($entries as $entry) {
				try {
					$entrySummaries[] = $entry->getSummary();
				} catch (\Throwable $e) {
					// Log the error but continue processing other entries
					\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for entry ' . $entry->getId(), ['exception' => $e]);
					// Skip this entry
					continue;
				}
			}

			return new JSONResponse([
				'success' => true,
				'entries' => $entrySummaries,
				'total' => $totalCount
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Show create time entry form page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @return TemplateResponse
	 */
	public function create(): TemplateResponse
	{
		\OCP\Util::addTranslations('arbeitszeitcheck');

		// Get compliance configuration for frontend validation
		$maxDailyHours = (float)$this->config->getAppValue('arbeitszeitcheck', 'max_daily_hours', '10');
		$complianceStrictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';

		$response = new TemplateResponse(
			$this->appName,
			'time-entries',
			[
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'mode' => 'create',
				'entry' => null,
				'entries' => [],
				'stats' => [],
				'maxDailyHours' => $maxDailyHours,
				'complianceStrictMode' => $complianceStrictMode,
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]
		);
		return $this->configureCSP($response);
	}

	/**
	 * Show edit time entry form page
	 *
	 * Renders the time entry edit form for the specified entry. Verifies ownership
	 * before displaying the form. Redirects to list if access is denied.
	 *
	 * Editing restrictions:
	 * - Only entries from the last 2 weeks (14 days) can be edited
	 * - Only manual entries, entries with pending approval, or completed automatic entries can be edited
	 * - Approved entries cannot be edited (use "Request Correction" instead)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param int $id Time entry ID to edit
	 * @return TemplateResponse Template response with time-entries template and entry data, or error message
	 */
	public function edit(int $id): TemplateResponse
	{
		\OCP\Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			// Check ownership
			if ($entry->getUserId() !== $userId) {
				// Redirect to time entries list if access denied
				$response = new TemplateResponse(
					$this->appName,
					'time-entries',
					[
						'urlGenerator' => \OC::$server->getURLGenerator(),
						'error' => 'Access denied',
						'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
					]
				);
				return $this->configureCSP($response);
			}

			// Check if entry can be edited
			// Allow editing if:
			// 1. It's a manual entry (user created it themselves) - but not if already approved
			// 2. It has pending approval status (correction request)
			// 3. It's an automatic entry that is completed (not yet approved) - allow direct editing for convenience
			// 4. The entry date is within the last 2 weeks (14 days) - for data integrity and compliance
			// Do NOT allow editing if entry is already approved (approvedBy is set) or older than 2 weeks
			$isApproved = $entry->getApprovedBy() !== null;
			$entryDate = $entry->getStartTime();
			$twoWeeksAgo = new \DateTime();
			$twoWeeksAgo->modify('-14 days');
			$twoWeeksAgo->setTime(0, 0, 0); // Start of day
			$isWithinTwoWeeks = $entryDate && $entryDate >= $twoWeeksAgo;

			$canEdit = !$isApproved && $isWithinTwoWeeks && (
				$entry->getIsManualEntry()
				|| $entry->getStatus() === TimeEntry::STATUS_PENDING_APPROVAL
				|| ($entry->getStatus() === TimeEntry::STATUS_COMPLETED && !$entry->getIsManualEntry())
			);

			if (!$canEdit) {
				$errorMessage = $isApproved
					? $this->l10n->t('Cannot edit this time entry. Please use "Request Correction" for approved entries.')
					: (!$isWithinTwoWeeks
						? $this->l10n->t('Cannot edit this time entry. Only entries from the last 2 weeks can be edited.')
						: $this->l10n->t('Cannot edit this time entry.'));

				// Redirect to time entries list with error message
				$response = new TemplateResponse(
					$this->appName,
					'time-entries',
					[
						'urlGenerator' => \OC::$server->getURLGenerator(),
						'error' => $errorMessage,
						'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
					]
				);
				return $this->configureCSP($response);
			}

			// Get compliance configuration for frontend validation
			$maxDailyHours = (float)$this->config->getAppValue('arbeitszeitcheck', 'max_daily_hours', '10');
			$complianceStrictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';

			$response = new TemplateResponse(
				$this->appName,
				'time-entries',
				[
					'urlGenerator' => \OC::$server->getURLGenerator(),
					'mode' => 'edit',
					'entry' => $entry,
					'entries' => [],
					'stats' => [],
					'maxDailyHours' => $maxDailyHours,
					'complianceStrictMode' => $complianceStrictMode,
					'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
				]
			);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			// Redirect to time entries list on error
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in edit method: ' . $e->getMessage(), ['exception' => $e]);
			$response = new TemplateResponse(
				$this->appName,
				'time-entries',
				[
					'urlGenerator' => \OC::$server->getURLGenerator(),
					'error' => $e->getMessage(),
					'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
				]
			);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Get time entry by ID endpoint
	 *
	 * @NoAdminRequired
	 * @param int $id Time entry ID
	 * @return JSONResponse
	 */
	public function show(int $id): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			// Check ownership
			if ($entry->getUserId() !== $userId) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Access denied'
				], Http::STATUS_FORBIDDEN);
			}

			return new JSONResponse([
				'success' => true,
				'entry' => $entry->getSummary()
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create time entry endpoint (manual entry)
	 *
	 * @NoAdminRequired
	 * @param string $date Date (Y-m-d)
	 * @param float $hours Hours worked
	 * @param string|null $description Description
	 * @param string|null $project_check_project_id Project ID
	 * @return JSONResponse
	 */
	public function store(string $date, float $hours, ?string $description = null, ?string $project_check_project_id = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();

			$timeEntry = new TimeEntry();
			$timeEntry->setUserId($userId);
			// Parse date - supports both ISO (yyyy-mm-dd) and German format (dd.mm.yyyy)
			$startDateTime = $this->parseDate($date);
			$startDateTime->setTime(9, 0, 0); // Default start time 9:00
			$timeEntry->setStartTime($startDateTime);

			// Calculate end time based on hours
			$endDateTime = clone $startDateTime;
			$endDateTime->modify('+' . round($hours * 3600) . ' seconds');
			$timeEntry->setEndTime($endDateTime);
			$timeEntry->setDescription($description);
			$timeEntry->setProjectCheckProjectId($project_check_project_id);
			$timeEntry->setStatus(TimeEntry::STATUS_COMPLETED);
			$timeEntry->setIsManualEntry(true);
			$timeEntry->setJustification('Manual entry created via employee portal');
			$timeEntry->setCreatedAt(new \DateTime());
			$timeEntry->setUpdatedAt(new \DateTime());

			// Check rest period compliance before saving (ArbZG §5)
			if (!$this->complianceService) {
				try {
					$this->complianceService = \OCP\Server::get(ComplianceService::class);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning('ComplianceService not available for rest period check: ' . $e->getMessage());
				}
			}
			if ($this->complianceService && $timeEntry->getStartTime()) {
				$restPeriodCheck = $this->complianceService->checkRestPeriodForStartTime($userId, $timeEntry->getStartTime());
				if (!$restPeriodCheck['valid']) {
					return new JSONResponse([
						'success' => false,
						'error' => $restPeriodCheck['message']
					], Http::STATUS_BAD_REQUEST);
				}
			}

			// Calculate and set automatic break if no break was entered (ArbZG §4)
			if (!$this->timeTrackingService) {
				try {
					$this->timeTrackingService = \OCP\Server::get(TimeTrackingService::class);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning('TimeTrackingService not available for automatic break calculation: ' . $e->getMessage());
				}
			}
			if ($this->timeTrackingService) {
				$this->timeTrackingService->calculateAndSetAutomaticBreak($timeEntry);
			}

			// Validate entry before inserting
			$errors = $timeEntry->validate();

			// Additional compliance validation: check maximum working hours (ArbZG §3)
			// AUTOMATIC LIMIT: TimeEntry::validate() already automatically adjusts end time to 10h
			// This ensures compliance - no need for additional validation here
			// The automatic adjustment in validate() handles it perfectly

			if (!empty($errors)) {
				// Translate validation errors
				$translatedErrors = [];
				foreach ($errors as $field => $message) {
					$translatedErrors[$field] = $this->l10n->t($message);
				}
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Validation failed'),
					'errors' => $translatedErrors
				], Http::STATUS_BAD_REQUEST);
			}

			$savedEntry = $this->timeEntryMapper->insert($timeEntry);

			// Real-time compliance check for completed entries
			// Based on industry best practices (Personio, Flintec): immediate compliance checking
			if ($savedEntry->getStatus() === TimeEntry::STATUS_COMPLETED && $savedEntry->getEndTime() !== null) {
				$this->performRealTimeComplianceCheck($savedEntry);
			}

			// Log the action
			try {
				$summary = $savedEntry->getSummary();
				$this->auditLogMapper->logAction(
					$userId,
					'time_entry_created',
					'time_entry',
					$savedEntry->getId(),
					null,
					$summary
				);
			} catch (\Throwable $e) {
				// Log error but don't fail the request
				\OCP\Log\logger('arbeitszeitcheck')->error('Error creating audit log for time entry create: ' . $e->getMessage(), ['exception' => $e]);
			}

			return new JSONResponse([
				'success' => true,
				'entry' => $savedEntry->getSummary()
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update time entry endpoint
	 *
	 * Updates an existing time entry. Only manual entries or entries with pending_approval
	 * status can be updated. Ownership is verified before allowing updates. Changes are
	 * validated and logged in the audit trail.
	 *
	 * Editing restrictions:
	 * - Only entries from the last 2 weeks (14 days) can be edited
	 * - Only manual entries, entries with pending approval, or completed automatic entries can be edited
	 * - Approved entries cannot be edited (use "Request Correction" instead)
	 *
	 * @NoAdminRequired
	 * @param int $id Time entry ID to update
	 * @param string|null $date New date (Y-m-d format, backward compatibility)
	 * @param float|null $hours New hours worked (backward compatibility)
	 * @param string|null $description New description
	 * @param string|null $project_check_project_id New ProjectCheck project ID
	 * @return JSONResponse JSON response with 'success' and updated 'entry' data, or 'error' on failure
	 * @throws DoesNotExistException If time entry not found
	 * @throws \Exception If user doesn't own the entry, entry cannot be edited, or validation fails
	 */
	public function update(int $id, ?string $date = null, ?float $hours = null, ?string $description = null, ?string $project_check_project_id = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			// Check ownership
			if ($entry->getUserId() !== $userId) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Access denied'
				], Http::STATUS_FORBIDDEN);
			}

			// Check if entry can be edited
			// Allow editing if:
			// 1. It's a manual entry (user created it themselves) - but not if already approved
			// 2. It has pending approval status (correction request)
			// 3. It's an automatic entry that is completed (not yet approved) - allow direct editing for convenience
			// 4. The entry date is within the last 2 weeks (14 days) - for data integrity and compliance
			// Do NOT allow editing if entry is already approved (approvedBy is set) or older than 2 weeks
			$isApproved = $entry->getApprovedBy() !== null;
			$entryDate = $entry->getStartTime();
			$twoWeeksAgo = new \DateTime();
			$twoWeeksAgo->modify('-14 days');
			$twoWeeksAgo->setTime(0, 0, 0); // Start of day
			$isWithinTwoWeeks = $entryDate && $entryDate >= $twoWeeksAgo;

			$canEdit = !$isApproved && $isWithinTwoWeeks && (
				$entry->getIsManualEntry()
				|| $entry->getStatus() === TimeEntry::STATUS_PENDING_APPROVAL
				|| ($entry->getStatus() === TimeEntry::STATUS_COMPLETED && !$entry->getIsManualEntry())
			);

			if (!$canEdit) {
				$errorMessage = $isApproved
					? $this->l10n->t('Cannot edit this time entry. Please use "Request Correction" for approved entries.')
					: (!$isWithinTwoWeeks
						? $this->l10n->t('Cannot edit this time entry. Only entries from the last 2 weeks can be edited.')
						: $this->l10n->t('Cannot edit this time entry.'));

				return new JSONResponse([
					'success' => false,
					'error' => $errorMessage
				], Http::STATUS_BAD_REQUEST);
			}

			// Get data from request body
			$params = $this->request->getParams();
			$startTime = $params['startTime'] ?? null;
			$endTime = $params['endTime'] ?? null;
			$breakStartTime = $params['breakStartTime'] ?? null;
			$breakEndTime = $params['breakEndTime'] ?? null;
			$breaksJson = $params['breaks'] ?? null;

			// New format: startTime and endTime
			if ($startTime && $endTime) {
				$entry->setStartTime(new \DateTime($startTime));
				$entry->setEndTime(new \DateTime($endTime));

				// Handle breaks: prefer breaks JSON (multiple breaks) over single break fields
				if ($breaksJson) {
					// Validate and set breaks JSON (multiple breaks)
					$breaks = json_decode($breaksJson, true);
					if (is_array($breaks) && !empty($breaks)) {
						// Filter out breaks shorter than 15 minutes (ArbZG §4)
						$validBreaks = [];
						foreach ($breaks as $break) {
							if (isset($break['start']) && isset($break['end'])) {
								try {
									$breakStart = new \DateTime($break['start']);
									$breakEnd = new \DateTime($break['end']);
									$breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();
									$minBreakDurationSeconds = 900; // 15 minutes

									// Only include breaks that are at least 15 minutes
									if ($breakDurationSeconds >= $minBreakDurationSeconds) {
										$validBreaks[] = [
											'start' => $breakStart->format('c'),
											'end' => $breakEnd->format('c')
										];
									}
								} catch (\Exception $e) {
									// Skip invalid break times
								}
							}
						}

						if (!empty($validBreaks)) {
							$entry->setBreaks(json_encode($validBreaks));
							// Clear single break fields when using breaks JSON
							$entry->setBreakStartTime(null);
							$entry->setBreakEndTime(null);
						} else {
							// No valid breaks, clear everything
							$entry->setBreaks(null);
							$entry->setBreakStartTime(null);
							$entry->setBreakEndTime(null);
						}
					} else {
						// Invalid breaks JSON, clear everything
						$entry->setBreaks(null);
						$entry->setBreakStartTime(null);
						$entry->setBreakEndTime(null);
					}
				} elseif ($breakStartTime && $breakEndTime) {
					// Fallback to single break fields (backward compatibility)
					$entry->setBreakStartTime(new \DateTime($breakStartTime));
					$entry->setBreakEndTime(new \DateTime($breakEndTime));
					// Clear breaks JSON when using single break fields
					$entry->setBreaks(null);
				} else {
					// Clear all break times if not provided
					$entry->setBreakStartTime(null);
					$entry->setBreakEndTime(null);
					$entry->setBreaks(null);
				}

				// Calculate and set automatic break if no break was entered (ArbZG §4)
				if (!$this->timeTrackingService) {
					try {
						$this->timeTrackingService = \OCP\Server::get(TimeTrackingService::class);
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->warning('TimeTrackingService not available for automatic break calculation: ' . $e->getMessage());
					}
				}
				if ($this->timeTrackingService) {
					$this->timeTrackingService->calculateAndSetAutomaticBreak($entry);
				}
			}
			// Old format: date and hours (backward compatibility)
			else {
				if ($date) {
					$entry->setStartTime(new \DateTime($date));
				}
				if ($hours !== null) {
					// Calculate end time based on hours from start time
					if ($entry->getStartTime()) {
						$startTime = clone $entry->getStartTime();
						$endTime = clone $startTime;
						$endTime->modify('+' . round($hours * 3600) . ' seconds');
						$entry->setEndTime($endTime);
					}
				}

				// Calculate and set automatic break if no break was entered (ArbZG §4)
				if (!$this->timeTrackingService) {
					try {
						$this->timeTrackingService = \OCP\Server::get(TimeTrackingService::class);
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->warning('TimeTrackingService not available for automatic break calculation: ' . $e->getMessage());
					}
				}
				if ($this->timeTrackingService) {
					$this->timeTrackingService->calculateAndSetAutomaticBreak($entry);
				}
			}

			// Update description from params or function parameter
			if (isset($params['description'])) {
				$entry->setDescription($params['description']);
			} elseif ($description !== null) {
				$entry->setDescription($description);
			}

			if ($project_check_project_id !== null) {
				$entry->setProjectCheckProjectId($project_check_project_id);
			}

			// Check rest period compliance before saving (ArbZG §5)
			if (!$this->complianceService) {
				try {
					$this->complianceService = \OCP\Server::get(ComplianceService::class);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning('ComplianceService not available for rest period check: ' . $e->getMessage());
				}
			}
			if ($this->complianceService && $entry->getStartTime()) {
				$restPeriodCheck = $this->complianceService->checkRestPeriodForStartTime($userId, $entry->getStartTime(), $id);
				if (!$restPeriodCheck['valid']) {
					return new JSONResponse([
						'success' => false,
						'error' => $restPeriodCheck['message']
					], Http::STATUS_BAD_REQUEST);
				}
			}

			// Calculate and set automatic break if no break was entered (ArbZG §4)
			if (!$this->timeTrackingService) {
				try {
					$this->timeTrackingService = \OCP\Server::get(TimeTrackingService::class);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning('TimeTrackingService not available for automatic break calculation: ' . $e->getMessage());
				}
			}
			if ($this->timeTrackingService) {
				$this->timeTrackingService->calculateAndSetAutomaticBreak($entry);
			}

			// Validate entry (automatically adjusts end time to 10h if exceeded)
			$errors = $entry->validate();

			// Additional compliance validation: check maximum working hours (ArbZG §3)
			// AUTOMATIC LIMIT: TimeEntry::validate() already automatically adjusts end time to 10h
			// This ensures compliance - no need for additional validation here

			if (!empty($errors)) {
				// Translate validation errors
				$translatedErrors = [];
				foreach ($errors as $field => $message) {
					$translatedErrors[$field] = $this->l10n->t($message);
				}
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Validation failed'),
					'errors' => $translatedErrors
				], Http::STATUS_BAD_REQUEST);
			}

			// Get old values before update
			$oldSummary = null;
			try {
				$oldSummary = $entry->getSummary();
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error getting old summary for time entry update audit log: ' . $e->getMessage(), ['exception' => $e]);
			}

			$entry->setUpdatedAt(new \DateTime());
			$updatedEntry = $this->timeEntryMapper->update($entry);

			// Real-time compliance check if entry is now completed
			// Check if status changed to COMPLETED or if it was already COMPLETED
			if ($updatedEntry->getStatus() === TimeEntry::STATUS_COMPLETED && $updatedEntry->getEndTime() !== null) {
				$this->performRealTimeComplianceCheck($updatedEntry);
			}

			// Log the action
			try {
				$newSummary = $updatedEntry->getSummary();
				$this->auditLogMapper->logAction(
					$userId,
					'time_entry_updated',
					'time_entry',
					$id,
					$oldSummary,
					$newSummary
				);
			} catch (\Throwable $e) {
				// Log error but don't fail the request
				\OCP\Log\logger('arbeitszeitcheck')->error('Error creating audit log for time entry update: ' . $e->getMessage(), ['exception' => $e]);
			}

			return new JSONResponse([
				'success' => true,
				'entry' => $updatedEntry->getSummary()
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update time entry endpoint (POST method for form submissions)
	 *
	 * Handles POST requests for updating time entries. Delegates to the update() method.
	 * Supports both old format (date, hours) and new format (startTime, endTime, breakStartTime, breakEndTime).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param int $id Time entry ID to update
	 * @return JSONResponse JSON response with 'success' and updated 'entry' data, or 'error' on failure
	 */
	public function updatePost(int $id): JSONResponse
	{
		$params = $this->request->getParams();

		// Support new format: startTime, endTime, breakStartTime, breakEndTime
		$startTime = $params['startTime'] ?? null;
		$endTime = $params['endTime'] ?? null;
		$breakStartTime = $params['breakStartTime'] ?? null;
		$breakEndTime = $params['breakEndTime'] ?? null;

		// If new format is provided, pass it directly to update() which handles it
		if ($startTime && $endTime) {
			// The update() method will handle startTime, endTime, breakStartTime, breakEndTime from params
			return $this->update($id);
		}

		// Old format: date, hours (backward compatibility)
		$date = $params['date'] ?? null;
		$hours = isset($params['hours']) ? (float)$params['hours'] : null;
		$description = $params['description'] ?? null;
		$project_check_project_id = $params['project_check_project_id'] ?? $params['projectCheckProjectId'] ?? null;

		return $this->update($id, $date, $hours, $description, $project_check_project_id);
	}

	/**
	 * Get deletion impact information for a time entry
	 *
	 * Returns information about what will be affected if the time entry is deleted,
	 * such as related compliance violations, reports, or other dependencies.
	 *
	 * @NoAdminRequired
	 * @param int $id Time entry ID
	 * @return JSONResponse JSON response with 'success' and 'impact' information
	 */
	public function getDeletionImpact(int $id): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			// Check ownership
			if ($entry->getUserId() !== $userId) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied')
				], Http::STATUS_FORBIDDEN);
			}

			// Check if entry can be deleted
			$canDelete = $entry->getIsManualEntry();
			$impact = [
				'canDelete' => $canDelete,
				'isManualEntry' => $entry->getIsManualEntry(),
				'status' => $entry->getStatus(),
				'warnings' => []
			];

			if (!$canDelete) {
				$impact['warnings'][] = $this->l10n->t('Only manual time entries can be deleted. Automatic entries cannot be deleted.');
			}

			if ($entry->getStatus() === TimeEntry::STATUS_PENDING_APPROVAL) {
				$impact['warnings'][] = $this->l10n->t('This entry has a pending correction request. Deleting it may affect the approval process.');
			}

			return new JSONResponse([
				'success' => true,
				'impact' => $impact
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController::getDeletionImpact: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Request correction for a time entry
	 *
	 * Allows users to request corrections to their time entries. Changes the entry
	 * status to pending_approval and sends a notification to the user's manager.
	 * The original data and proposed changes are logged in the audit trail.
	 *
	 * Supports both old format (newDate, newHours) and new format (startTime, endTime)
	 * for backward compatibility.
	 *
	 * @NoAdminRequired
	 * @param int $id Time entry ID to request correction for
	 * @return JSONResponse JSON response with 'success' and updated 'entry' data, or 'error' on failure
	 * @throws DoesNotExistException If time entry not found
	 * @throws \Exception If user doesn't own the entry, correction already pending, or validation fails
	 */
	public function requestCorrection(int $id): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			// Check ownership
			if ($entry->getUserId() !== $userId) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Access denied'
				], Http::STATUS_FORBIDDEN);
			}

			// Check if entry can be corrected (not already pending)
			$currentStatus = $entry->getStatus();
			if ($currentStatus === TimeEntry::STATUS_PENDING_APPROVAL) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Correction request already pending')
				], Http::STATUS_BAD_REQUEST);
			}

			// Get data from request body
			$params = $this->request->getParams();
			$justification = $params['justification'] ?? null;
			$startTime = $params['startTime'] ?? null;
			$endTime = $params['endTime'] ?? null;
			$breakStartTime = $params['breakStartTime'] ?? null;
			$breakEndTime = $params['breakEndTime'] ?? null;
			$description = $params['description'] ?? null;

			// Backward compatibility: support old format (newDate, newHours, newDescription)
			$newDate = $params['newDate'] ?? null;
			$newHours = isset($params['newHours']) ? (float)$params['newHours'] : null;
			$newDescription = $params['newDescription'] ?? null;

			// Require justification for correction request
			if (empty($justification)) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Justification is required for correction requests'
				], Http::STATUS_BAD_REQUEST);
			}

			// Store proposed changes in justification field (format: JSON with original and proposed values)
			$entryStartTime = $entry->getStartTime();
			if (!$entryStartTime) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Time entry has no start time'
				], Http::STATUS_BAD_REQUEST);
			}

			$originalData = [
				'startTime' => $entryStartTime->format('c'),
				'endTime' => $entry->getEndTime() ? $entry->getEndTime()->format('c') : null,
				'breakStartTime' => $entry->getBreakStartTime() ? $entry->getBreakStartTime()->format('c') : null,
				'breakEndTime' => $entry->getBreakEndTime() ? $entry->getBreakEndTime()->format('c') : null,
				'durationHours' => $entry->getDurationHours(),
				'description' => $entry->getDescription()
			];

			$proposedData = [];

			// New format: startTime and endTime
			if ($startTime && $endTime) {
				$proposedStartTime = new \DateTime($startTime);
				$proposedEndTime = new \DateTime($endTime);
				$proposedData['startTime'] = $proposedStartTime->format('c');
				$proposedData['endTime'] = $proposedEndTime->format('c');

				if ($breakStartTime && $breakEndTime) {
					$proposedBreakStartTime = new \DateTime($breakStartTime);
					$proposedBreakEndTime = new \DateTime($breakEndTime);
					$proposedData['breakStartTime'] = $proposedBreakStartTime->format('c');
					$proposedData['breakEndTime'] = $proposedBreakEndTime->format('c');
				}

				if ($description !== null) {
					$proposedData['description'] = $description;
				}
			}
			// Old format: newDate and newHours (backward compatibility)
			elseif ($newDate || $newHours !== null) {
				if ($newDate) {
					$proposedData['date'] = $newDate;
				}
				if ($newHours !== null) {
					$proposedData['hours'] = $newHours;
				}
				if ($newDescription !== null) {
					$proposedData['description'] = $newDescription;
				}
			}

			$correctionData = [
				'justification' => $justification,
				'original' => $originalData,
				'proposed' => $proposedData,
				'requested_at' => date('c')
			];

			// Update entry with correction request
			$entry->setJustification(json_encode($correctionData));
			$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
			$entry->setUpdatedAt(new \DateTime());

			// Apply proposed changes temporarily (will be finalized on approval)
			if ($startTime && $endTime) {
				// New format
				$entry->setStartTime(new \DateTime($startTime));
				$entry->setEndTime(new \DateTime($endTime));

				if ($breakStartTime && $breakEndTime) {
					$entry->setBreakStartTime(new \DateTime($breakStartTime));
					$entry->setBreakEndTime(new \DateTime($breakEndTime));
				}

				if ($description !== null) {
					$entry->setDescription($description);
				}
			} elseif ($newDate || $newHours !== null) {
				// Old format (backward compatibility)
				if ($newDate) {
					$entry->setStartTime($this->parseDate($newDate));
					if ($entry->getEndTime() && $newHours !== null) {
						$endTime = clone $entry->getStartTime();
						$endTime->modify('+' . round($newHours * 3600) . ' seconds');
						$entry->setEndTime($endTime);
					}
				} elseif ($newHours !== null && $entry->getStartTime()) {
					$endTime = clone $entry->getStartTime();
					$endTime->modify('+' . round($newHours * 3600) . ' seconds');
					$entry->setEndTime($endTime);
				}
				if ($newDescription !== null) {
					$entry->setDescription($newDescription);
				}
			}

			$updatedEntry = $this->timeEntryMapper->update($entry);

			// Create audit log
			$auditLogMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class);
			$auditLogMapper->logAction(
				$userId,
				'time_entry_correction_requested',
				'time_entry',
				$id, // entityId
				$originalData, // oldValues
				[
					'original_status' => $currentStatus,
					'justification' => $justification,
					'proposed_changes' => $proposedData
				] // newValues
			);

			// Send notification to manager (if manager exists)
			try {
				$notificationService = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\NotificationService::class);
				$notificationService->notifyTimeEntryCorrectionRequested(
					$userId,
					$updatedEntry->getSummary(),
					$justification
				);
			} catch (\Throwable $e) {
				// Notification failure shouldn't block the correction request
				\OCP\Log\logger('arbeitszeitcheck')->warning('Failed to send correction request notification', ['exception' => $e]);
			}

			return new JSONResponse([
				'success' => true,
				'entry' => $updatedEntry->getSummary(),
				'message' => $this->l10n->t('Correction request submitted successfully')
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete time entry endpoint
	 *
	 * @NoAdminRequired
	 * @param int $id Time entry ID
	 * @return JSONResponse
	 */
	public function delete(int $id): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			// Check ownership
			if ($entry->getUserId() !== $userId) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Access denied'
				], Http::STATUS_FORBIDDEN);
			}

			// Check if entry can be deleted (only manual entries)
			if (!$entry->getIsManualEntry()) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Cannot delete automatic time entries'
				], Http::STATUS_BAD_REQUEST);
			}

			// Get entry data before deletion for audit log
			$deletedSummary = null;
			try {
				$deletedSummary = $entry->getSummary();
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error getting summary for time entry delete audit log: ' . $e->getMessage(), ['exception' => $e]);
				$deletedSummary = [
					'id' => $entry->getId(),
					'userId' => $entry->getUserId(),
					'status' => $entry->getStatus()
				];
			}

			$this->timeEntryMapper->delete($entry);

			// Log the action
			try {
				$this->auditLogMapper->logAction(
					$userId,
					'time_entry_deleted',
					'time_entry',
					$id,
					$deletedSummary,
					null
				);
			} catch (\Throwable $e) {
				// Log error but don't fail the request
				\OCP\Log\logger('arbeitszeitcheck')->error('Error creating audit log for time entry delete: ' . $e->getMessage(), ['exception' => $e]);
			}

			return new JSONResponse([
				'success' => true
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get time entry statistics endpoint
	 *
	 * @NoAdminRequired
	 * @param string|null $start_date Start date for statistics
	 * @param string|null $end_date End date for statistics
	 * @return JSONResponse
	 */
	public function getStats(?string $start_date = null, ?string $end_date = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();

			$start = $start_date ? new \DateTime($start_date) : (new \DateTime())->modify('-30 days');
			$end = $end_date ? new \DateTime($end_date) : new \DateTime();
			$start->setTime(0, 0, 0);
			$end->setTime(23, 59, 59);

			$totalHours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange($userId, $start, $end);
			$totalBreakHours = $this->timeEntryMapper->getTotalBreakHoursByUserAndDateRange($userId, $start, $end);
			$totalEntries = $this->timeEntryMapper->countByUser($userId);

			$workingDays = $this->calculateWorkingDays($start, $end);
			$averageHoursPerDay = $workingDays > 0 ? $totalHours / $workingDays : 0;

			// Calculate overtime using injected OvertimeService
			$overtimeData = $this->overtimeService->calculateOvertime($userId, $start, $end);

			return new JSONResponse([
				'success' => true,
				'stats' => [
					'total_hours' => $totalHours,
					'total_break_hours' => $totalBreakHours,
					'total_entries' => $totalEntries,
					'working_days' => $workingDays,
					'average_hours_per_day' => $averageHoursPerDay,
					'overtime' => [
						'overtime_hours' => $overtimeData['overtime_hours'],
						'required_hours' => $overtimeData['required_hours'],
						'total_hours_worked' => $overtimeData['total_hours_worked'],
						'cumulative_balance' => $overtimeData['cumulative_balance_after']
					],
					'period' => [
						'start' => $start->format('Y-m-d'),
						'end' => $end->format('Y-m-d')
					]
				]
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Calculate working days between two dates (excluding weekends)
	 *
	 * Counts the number of working days (Monday to Friday) between two dates, inclusive.
	 * Used for calculating overtime and working time statistics.
	 *
	 * @param \DateTime $start Start date (inclusive)
	 * @param \DateTime $end End date (inclusive)
	 * @return int Number of working days between the dates (excluding weekends)
	 */
	private function calculateWorkingDays(\DateTime $start, \DateTime $end): int
	{
		$workingDays = 0;
		$current = clone $start;

		while ($current <= $end) {
			// Monday = 1, Sunday = 7
			if ($current->format('N') < 6) { // Monday to Friday
				$workingDays++;
			}
			$current->modify('+1 day');
		}

		return $workingDays;
	}

	/**
	 * Legacy API: Get time entries (alias for index)
	 *
	 * Legacy endpoint for backward compatibility. Delegates to the index() method.
	 *
	 * @NoAdminRequired
	 * @param string|null $start_date Start date filter (Y-m-d format)
	 * @param string|null $end_date End date filter (Y-m-d format)
	 * @param string|null $status Status filter
	 * @param int|null $limit Maximum number of entries to return (default: 25)
	 * @param int|null $offset Number of entries to skip for pagination (default: 0)
	 * @return JSONResponse JSON response with 'success', 'entries' array, and 'total' count
	 */
	public function index_api(?string $start_date = null, ?string $end_date = null, ?string $status = null, ?int $limit = 25, ?int $offset = 0): JSONResponse
	{
		return $this->index($start_date, $end_date, $status, $limit, $offset);
	}

	/**
	 * API: Get time entries (alias for index)
	 *
	 * REST API endpoint for retrieving time entries. Delegates to the index() method.
	 *
	 * @NoAdminRequired
	 * @param string|null $start_date Start date filter (Y-m-d format)
	 * @param string|null $end_date End date filter (Y-m-d format)
	 * @param string|null $status Status filter
	 * @param int|null $limit Maximum number of entries to return (default: 25)
	 * @param int|null $offset Number of entries to skip for pagination (default: 0)
	 * @return JSONResponse JSON response with 'success', 'entries' array, and 'total' count
	 */
	public function apiIndex(?string $start_date = null, ?string $end_date = null, ?string $status = null, ?int $limit = 25, ?int $offset = 0): JSONResponse
	{
		return $this->index($start_date, $end_date, $status, $limit, $offset);
	}

	/**
	 * API: Get time entry by ID (alias for show)
	 *
	 * @NoAdminRequired
	 * @param int $id
	 * @return JSONResponse
	 */
	public function apiShow(int $id): JSONResponse
	{
		return $this->show($id);
	}

	/**
	 * API: Create time entry (accepts JSON body)
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @return JSONResponse
	 */
	public function apiStore(): JSONResponse
	{
		$params = $this->request->getParams();

		// Support both old format (date + hours) and new format (date + startTime + endTime)
		$date = $params['date'] ?? null;
		$startTime = $params['startTime'] ?? null;
		$endTime = $params['endTime'] ?? null;
		$breakStartTime = $params['breakStartTime'] ?? null;
		$breakEndTime = $params['breakEndTime'] ?? null;
		$hours = isset($params['hours']) ? (float)$params['hours'] : null;
		$description = $params['description'] ?? null;
		$project_check_project_id = $params['project_check_project_id'] ?? $params['projectCheckProjectId'] ?? null;

		// New format: startTime and endTime
		if ($startTime && $endTime) {
			try {
				$userId = $this->getUserId();

				$timeEntry = new TimeEntry();
				$timeEntry->setUserId($userId);
				$timeEntry->setStartTime(new \DateTime($startTime));
				$timeEntry->setEndTime(new \DateTime($endTime));

				// Set break times if provided
				if ($breakStartTime && $breakEndTime) {
					$timeEntry->setBreakStartTime(new \DateTime($breakStartTime));
					$timeEntry->setBreakEndTime(new \DateTime($breakEndTime));
				}

				$timeEntry->setDescription($description);
				$timeEntry->setProjectCheckProjectId($project_check_project_id);
				$timeEntry->setStatus(TimeEntry::STATUS_COMPLETED);
				$timeEntry->setIsManualEntry(true);
				$timeEntry->setJustification('Manual entry created via employee portal');
				$timeEntry->setCreatedAt(new \DateTime());
				$timeEntry->setUpdatedAt(new \DateTime());

				// Check rest period compliance before saving (ArbZG §5)
				if (!$this->complianceService) {
					try {
						$this->complianceService = \OCP\Server::get(ComplianceService::class);
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->warning('ComplianceService not available for rest period check: ' . $e->getMessage());
					}
				}
				if ($this->complianceService && $timeEntry->getStartTime()) {
					$restPeriodCheck = $this->complianceService->checkRestPeriodForStartTime($userId, $timeEntry->getStartTime());
					if (!$restPeriodCheck['valid']) {
						return new JSONResponse([
							'success' => false,
							'error' => $restPeriodCheck['message']
						], Http::STATUS_BAD_REQUEST);
					}
				}

				// Calculate and set automatic break if no break was entered (ArbZG §4)
				if (!$this->timeTrackingService) {
					try {
						$this->timeTrackingService = \OCP\Server::get(TimeTrackingService::class);
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->warning('TimeTrackingService not available for automatic break calculation: ' . $e->getMessage());
					}
				}
				if ($this->timeTrackingService) {
					$this->timeTrackingService->calculateAndSetAutomaticBreak($timeEntry);
				}

				// Validate entry
				$errors = $timeEntry->validate();

				// Additional compliance validation: check maximum working hours (ArbZG §3)
				// AUTOMATIC LIMIT: TimeEntry::validate() already automatically adjusts end time to 10h
				// This ensures compliance - no need for additional validation here

				if (!empty($errors)) {
					// Translate validation errors
					$translatedErrors = [];
					foreach ($errors as $field => $message) {
						$translatedErrors[$field] = $this->l10n->t($message);
					}
					return new JSONResponse([
						'success' => false,
						'error' => implode(', ', $translatedErrors),
						'errors' => $translatedErrors
					], Http::STATUS_BAD_REQUEST);
				}

				$savedEntry = $this->timeEntryMapper->insert($timeEntry);

				// Real-time compliance check for completed entries
				// Based on industry best practices (Personio, Flintec): immediate compliance checking
				if ($savedEntry->getStatus() === TimeEntry::STATUS_COMPLETED && $savedEntry->getEndTime() !== null) {
					$this->performRealTimeComplianceCheck($savedEntry);
				}

				// Log the action
				try {
					$summary = $savedEntry->getSummary();
					$this->auditLogMapper->logAction(
						$userId,
						'time_entry_created',
						'time_entry',
						$savedEntry->getId(),
						null,
						$summary
					);
				} catch (\Throwable $e) {
					// Log error but don't fail the request
					\OCP\Log\logger('arbeitszeitcheck')->error('Error creating audit log for time entry apiStore: ' . $e->getMessage(), ['exception' => $e]);
				}

				return new JSONResponse([
					'success' => true,
					'entry' => $savedEntry->getSummary()
				], Http::STATUS_CREATED);
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController::apiStore: ' . $e->getMessage(), ['exception' => $e]);
				return new JSONResponse([
					'success' => false,
					'error' => $e->getMessage()
				], Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		}

		// Old format: date + hours (backward compatibility)
		if (!$date || $hours === null) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Either (date and hours) or (startTime and endTime) are required'
			], Http::STATUS_BAD_REQUEST);
		}

		return $this->store($date, $hours, $description, $project_check_project_id);
	}

	/**
	 * API: Update time entry (accepts JSON body)
	 *
	 * REST API endpoint for updating time entries. Accepts data in the request body.
	 * Delegates to the update() method for actual processing.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param int $id Time entry ID to update
	 * @return JSONResponse JSON response with 'success' and updated 'entry' data, or 'error' on failure
	 */
	public function apiUpdate(int $id): JSONResponse
	{
		$params = $this->request->getParams();
		$date = $params['date'] ?? null;
		$hours = isset($params['hours']) ? (float)$params['hours'] : null;
		$description = $params['description'] ?? null;
		$project_check_project_id = $params['project_check_project_id'] ?? $params['projectCheckProjectId'] ?? null;

		return $this->update($id, $date, $hours, $description, $project_check_project_id);
	}

	/**
	 * API: Update time entry via POST (accepts JSON body)
	 *
	 * REST API endpoint for updating time entries via POST method. Accepts data in the request body.
	 * Supports both old format (date, hours) and new format (startTime, endTime, breakStartTime, breakEndTime).
	 * Delegates to the update() method for actual processing.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param int $id Time entry ID to update
	 * @return JSONResponse JSON response with 'success' and updated 'entry' data, or 'error' on failure
	 */
	public function apiUpdatePost(int $id): JSONResponse
	{
		$params = $this->request->getParams();

		// Support new format: startTime, endTime, breakStartTime, breakEndTime
		$startTime = $params['startTime'] ?? null;
		$endTime = $params['endTime'] ?? null;
		$breakStartTime = $params['breakStartTime'] ?? null;
		$breakEndTime = $params['breakEndTime'] ?? null;

		// If new format is provided, pass it directly to update() which handles it
		if ($startTime && $endTime) {
			// The update() method will handle startTime, endTime, breakStartTime, breakEndTime from params
			return $this->update($id);
		}

		// Old format: date, hours (backward compatibility)
		$date = $params['date'] ?? null;
		$hours = isset($params['hours']) ? (float)$params['hours'] : null;
		$description = $params['description'] ?? null;
		$project_check_project_id = $params['project_check_project_id'] ?? $params['projectCheckProjectId'] ?? null;

		return $this->update($id, $date, $hours, $description, $project_check_project_id);
	}

	/**
	 * API: Delete time entry (alias for delete)
	 *
	 * @NoAdminRequired
	 * @param int $id
	 * @return JSONResponse
	 */
	public function apiDelete(int $id): JSONResponse
	{
		return $this->delete($id);
	}

	/**
	 * API: Get overtime information
	 *
	 * Calculates and returns overtime information for the current user for the specified period.
	 * Overtime is calculated based on working time models and actual hours worked.
	 *
	 * @NoAdminRequired
	 * @param string|null $period Period type: 'daily', 'weekly', 'monthly', 'yearly', or 'custom' (default: 'monthly')
	 * @param string|null $start_date Start date for custom period (Y-m-d format, required if period is 'custom')
	 * @param string|null $end_date End date for custom period (Y-m-d format, required if period is 'custom')
	 * @return JSONResponse JSON response with 'success', 'overtime' hours, and 'period' information
	 */
	public function getOvertime(?string $period = 'monthly', ?string $start_date = null, ?string $end_date = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();

			$overtimeData = match ($period) {
				'daily' => $this->overtimeService->getDailyOvertime($userId),
				'weekly' => $this->overtimeService->getWeeklyOvertime($userId),
				'monthly' => $this->overtimeService->calculateMonthlyOvertime($userId),
				'yearly' => $this->overtimeService->calculateYearlyOvertime($userId),
				'custom' => $this->getCustomPeriodOvertime($this->overtimeService, $userId, $start_date, $end_date),
				default => $this->overtimeService->calculateMonthlyOvertime($userId)
			};

			return new JSONResponse([
				'success' => true,
				'overtime' => $overtimeData
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get overtime balance (cumulative)
	 *
	 * @NoAdminRequired
	 * @return JSONResponse
	 */
	public function getOvertimeBalance(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$balance = $this->overtimeService->getOvertimeBalance($userId);

			return new JSONResponse([
				'success' => true,
				'balance' => $balance
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Helper method to get custom period overtime
	 *
	 * @param OvertimeService $overtimeService
	 * @param string $userId
	 * @param string|null $start_date
	 * @param string|null $end_date
	 * @return array
	 */
	private function getCustomPeriodOvertime($overtimeService, string $userId, ?string $start_date, ?string $end_date): array
	{
		if (!$start_date || !$end_date) {
			throw new \Exception($this->l10n->t('Start date and end date are required for custom period'));
		}

		$start = new \DateTime($start_date);
		$start->setTime(0, 0, 0);
		$end = new \DateTime($end_date);
		$end->setTime(23, 59, 59);

		return $overtimeService->calculateOvertime($userId, $start, $end);
	}

	/**
	 * Perform real-time compliance check for a completed time entry
	 * 
	 * This method implements industry best practices (Personio, Flintec) by performing
	 * immediate compliance checks when a time entry is completed. This ensures:
	 * - Immediate detection of violations
	 * - Proactive compliance management
	 * - Reduced legal risk
	 * - Better auditability
	 * 
	 * The check respects the configured compliance mode:
	 * - Warning mode (default): Violations are logged and notified, but entry can be saved
	 * - Strict mode: Critical violations prevent saving (throws exception)
	 * 
	 * @param TimeEntry $timeEntry The completed time entry to check
	 * @return void
	 * @throws \Exception If strict mode is enabled and critical violations are found
	 */
	private function performRealTimeComplianceCheck(TimeEntry $timeEntry): void
	{
		// Check if real-time compliance checking is enabled
		$realTimeComplianceEnabled = $this->config->getAppValue('arbeitszeitcheck', 'realtime_compliance_check', '1') === '1';
		if (!$realTimeComplianceEnabled) {
			return; // Real-time checking disabled, rely on daily batch job
		}

		// Get compliance service (may be null if not available)
		if (!$this->complianceService) {
			try {
				$this->complianceService = \OCP\Server::get(ComplianceService::class);
			} catch (\Throwable $e) {
				// Compliance service not available, log and continue
				\OCP\Log\logger('arbeitszeitcheck')->warning('ComplianceService not available for real-time check: ' . $e->getMessage());
				return;
			}
		}

		try {
			// Check if strict mode is enabled
			$strictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';

			// Perform compliance check
			$violations = $this->complianceService->checkComplianceForCompletedEntry($timeEntry, $strictMode);

			// Log compliance check result
			if (!empty($violations)) {
				\OCP\Log\logger('arbeitszeitcheck')->info('Real-time compliance check detected violations', [
					'time_entry_id' => $timeEntry->getId(),
					'user_id' => $timeEntry->getUserId(),
					'violation_count' => count($violations),
					'strict_mode' => $strictMode
				]);
			} else {
				\OCP\Log\logger('arbeitszeitcheck')->debug('Real-time compliance check passed', [
					'time_entry_id' => $timeEntry->getId(),
					'user_id' => $timeEntry->getUserId()
				]);
			}
		} catch (\Exception $e) {
			// In strict mode, re-throw the exception to prevent saving
			if ($this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1') {
				throw $e;
			}

			// In warning mode, log the error but don't prevent saving
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in real-time compliance check: ' . $e->getMessage(), [
				'time_entry_id' => $timeEntry->getId(),
				'user_id' => $timeEntry->getUserId(),
				'exception' => $e
			]);
		}
	}
}
