<?php

declare(strict_types=1);

/**
 * TimeEntry controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\AppLocalNaiveDateTimeNormalizer;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeEntryDeletionPolicy;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Support\BreakCountable;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCP\Lock\LockedException;
use OCP\AppFramework\Controller;
use OCP\IConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
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
	use NavigationFlagsTrait;
	use PageShellTrait;

	private TimeEntryMapper $timeEntryMapper;
	private IUserSession $userSession;
	private \OCA\ArbeitszeitCheck\Service\OvertimeService $overtimeService;
	private \OCA\ArbeitszeitCheck\Service\OvertimeBankService $overtimeBankService;
	private IURLGenerator $urlGenerator;
	private IL10N $l10n;
	private AuditLogMapper $auditLogMapper;
	private ComplianceService $complianceService;
	private IConfig $config;
	private TimeTrackingService $timeTrackingService;
	private TeamResolverService $teamResolver;
	private NotificationService $notificationService;
	private MonthClosureGuard $monthClosureGuard;
	private AbsenceMapper $absenceMapper;
	private PermissionService $permissionService;
	private TimeZoneService $timeZoneService;
	private TimeEntryCorrectionService $correctionService;
	private LocaleFormatService $localeFormat;
	private NavigationFlagsService $navigationFlags;
	private ProjectCheckIntegrationService $projectCheckIntegration;
	private ProjectCheckLaborTimeSyncService $projectCheckLaborSync;
	private TimeCaptureMethodService $timeCaptureMethodService;
	private TimeEntryDeletionPolicy $deletionPolicy;

	public function __construct(
		string $appName,
		IRequest $request,
		TimeEntryMapper $timeEntryMapper,
		IUserSession $userSession,
		\OCA\ArbeitszeitCheck\Service\OvertimeService $overtimeService,
		\OCA\ArbeitszeitCheck\Service\OvertimeBankService $overtimeBankService,
		IURLGenerator $urlGenerator,
		IL10N $l10n,
		AuditLogMapper $auditLogMapper,
		IConfig $config,
		CSPService $cspService,
		ComplianceService $complianceService,
		TimeTrackingService $timeTrackingService,
		TeamResolverService $teamResolver,
		NotificationService $notificationService,
		MonthClosureGuard $monthClosureGuard,
		AbsenceMapper $absenceMapper,
		PermissionService $permissionService,
		TimeZoneService $timeZoneService,
		TimeEntryCorrectionService $correctionService,
		LocaleFormatService $localeFormat,
		NavigationFlagsService $navigationFlags,
		ProjectCheckIntegrationService $projectCheckIntegration,
		ProjectCheckLaborTimeSyncService $projectCheckLaborSync,
		TimeCaptureMethodService $timeCaptureMethodService,
		TimeEntryDeletionPolicy $deletionPolicy,
	) {
		parent::__construct($appName, $request);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->userSession = $userSession;
		$this->overtimeService = $overtimeService;
		$this->overtimeBankService = $overtimeBankService;
		$this->urlGenerator = $urlGenerator;
		$this->l10n = $l10n;
		$this->auditLogMapper = $auditLogMapper;
		$this->config = $config;
		$this->setCspService($cspService);
		$this->complianceService = $complianceService;
		$this->timeTrackingService = $timeTrackingService;
		$this->teamResolver = $teamResolver;
		$this->notificationService = $notificationService;
		$this->monthClosureGuard = $monthClosureGuard;
		$this->absenceMapper = $absenceMapper;
		$this->permissionService = $permissionService;
		$this->timeZoneService = $timeZoneService;
		$this->correctionService = $correctionService;
		$this->localeFormat = $localeFormat;
		$this->navigationFlags = $navigationFlags;
		$this->projectCheckIntegration = $projectCheckIntegration;
		$this->projectCheckLaborSync = $projectCheckLaborSync;
		$this->timeCaptureMethodService = $timeCaptureMethodService;
		$this->deletionPolicy = $deletionPolicy;
	}

	/**
	 * Render a stored DateTime as HH:MM in the affected user's display TZ.
	 * Used in overlap / conflict messages so what the user reads matches their
	 * own clock and not the server's.
	 */
	private function displayClock(?\DateTimeInterface $dt, ?string $userId = null): string
	{
		if ($dt === null) {
			return '?';
		}
		return $this->timeZoneService->formatForDisplay($dt, 'H:i', $userId);
	}

	private function jsonMonthFinalizedConflict(): JSONResponse
	{
		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.'),
			'error_code' => 'month_finalized',
		], Http::STATUS_CONFLICT);
	}

	private function assertGuardTimeEntry(TimeEntry $entry): ?JSONResponse
	{
		try {
			$this->monthClosureGuard->assertTimeEntryMutable($entry);
		} catch (MonthFinalizedException $e) {
			return $this->jsonMonthFinalizedConflict();
		}
		return null;
	}

	private function deleteBlockHttpStatus(?string $blockCode): int
	{
		return match ($blockCode) {
			'month_finalized', 'cancel_correction_first' => Http::STATUS_CONFLICT,
			default => Http::STATUS_BAD_REQUEST,
		};
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
	 * Configured max daily hours; profile supplies the fallback when unset (DE 10 / AT 12).
	 */
	private function getConfiguredMaxDailyHours(): float
	{
		$country = strtoupper(trim($this->config->getAppValue('arbeitszeitcheck', 'country', 'DE')));
		$default = (string)LaborLawProfileFactory::profileForCountry($country)->dailyMaxHoursDefault;

		return max(1.0, min(24.0, (float)$this->config->getAppValue('arbeitszeitcheck', 'max_daily_hours', $default)));
	}

	private function normalizeOptionalProjectCheckProjectId(mixed $raw): ?string
	{
		if ($raw === null) {
			return null;
		}
		$s = trim((string)$raw);
		if ($s === '') {
			return null;
		}
		return $s;
	}

	private function assertProjectCheckAttachAllowed(string $userId, ?string $projectId): ?JSONResponse
	{
		if ($projectId === null) {
			return null;
		}
		if (mb_strlen($projectId) > TimeEntry::PROJECT_CHECK_PROJECT_ID_MAX_LENGTH) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Project ID must not exceed %d characters', [TimeEntry::PROJECT_CHECK_PROJECT_ID_MAX_LENGTH]),
			], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->projectCheckIntegration->userMayAttachProjectCheckProjectToOwnTime($userId, $projectId)) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('You cannot link this time entry to the selected project. If the project uses per-person rates, you must be on the project team. Otherwise ask a ProjectCheck administrator for access.'),
				'error_code' => 'projectcheck_project_forbidden',
			], Http::STATUS_FORBIDDEN);
		}
		return null;
	}

	private function syncProjectCheckBillingAfterSave(TimeEntry $entry, string $actorUserId): void
	{
		if ($entry->getStatus() !== TimeEntry::STATUS_COMPLETED || $entry->getEndTime() === null) {
			return;
		}
		$this->projectCheckLaborSync->syncFromTimeEntry($entry, $actorUserId);
	}

	/**
	 * JSON list of ProjectCheck projects the current user may link to their time entries (clock-in / manual).
	 */
	#[NoAdminRequired]
	public function apiAssignableProjectcheckProjects(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$linkingEnabled = $this->projectCheckIntegration->isLinkingEnabledForUser($userId);
			return new JSONResponse([
				'success' => true,
				'enabled' => $linkingEnabled,
				'projects' => $linkingEnabled ? $this->projectCheckIntegration->getAvailableProjects($userId) : [],
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Could not load projects.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Shared template parameters for time-entries (month closure + navigation).
	 *
	 * @return array{monthClosureEnabled: bool, showSubstitutionLink: bool, showManagerLink: bool, showReportsLink: bool, showAdminNav: bool}
	 */
	private function getTimeEntriesSharedTemplateParams(string $userId): array
	{
		return [
			'monthClosureEnabled' => $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_MONTH_CLOSURE_ENABLED, '0') === '1',
			'timeEntryChangesRequireApproval' => $this->requiresChangeApproval(),
			'manualTimeEntriesRequireApproval' => $this->requiresManualEntryApproval(),
			'timeCapture' => $this->timeCaptureMethodService->getSettings($userId),
		] + $this->getNavigationFlags($userId);
	}

	private function manualTimeEntryBlockedResponse(string $userId): ?JSONResponse
	{
		if ($this->timeCaptureMethodService->isManualTimeEntryEnabled($userId)) {
			return null;
		}

		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t('Manual time entries are not enabled for your account. Please contact your administrator.'),
			'error_code' => 'manual_time_entry_disabled',
		], Http::STATUS_FORBIDDEN);
	}

	private function registerTimeEntryFormAssets(): void
	{
		$this->registerFrontEndAssets('arbeitszeitcheck-main', null, [
			'time-entries',
			'time-entry-correction',
			'time-entry-form',
			'time-entry-form-accessibility',
		], [
			'common/datepicker',
			'common/validation',
		]);
		\OCP\Util::addScript('arbeitszeitcheck', 'time-entry-correction');
		\OCP\Util::addScript('arbeitszeitcheck', 'time-entry-form');
		\OCP\Util::addScript('arbeitszeitcheck', 'time-entry-form-accessibility');
	}

	/**
	 * @param array<string, mixed> $pageData
	 * @return array<string, mixed>
	 */
	private function buildTimeEntryFormTemplateParams(string $mode, array $pageData): array
	{
		$userId = $this->getUserId();
		$navFlags = $this->getNavigationFlags($userId);
		$navFlags['pendingCorrectionCount'] = \count(
			$this->timeEntryMapper->findByUserAndStatus($userId, TimeEntry::STATUS_PENDING_APPROVAL)
		);
		$this->registerTimeEntryFormAssets();

		return \array_merge($this->buildTimeEntriesShellParams($mode, $navFlags), $pageData);
	}

	/**
	 * @param array<string, mixed> $pageData
	 * @return array<string, mixed>
	 */
	private function buildTimeEntriesListTemplateParams(array $pageData): array
	{
		$userId = $this->getUserId();
		$navFlags = $this->getNavigationFlags($userId);
		$navFlags['pendingCorrectionCount'] = \count(
			$this->timeEntryMapper->findByUserAndStatus($userId, TimeEntry::STATUS_PENDING_APPROVAL)
		);
		$this->registerFrontEndAssets('arbeitszeitcheck-main', null, [
			'time-entries',
			'time-entry-correction',
			'time-entry-form-accessibility',
		], [
			'common/datepicker',
			'common/validation',
		]);
		\OCP\Util::addScript('arbeitszeitcheck', 'time-entry-correction');
		\OCP\Util::addScript('arbeitszeitcheck', 'time-entry-form-accessibility');
		if ($this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_MONTH_CLOSURE_ENABLED, '0') === '1') {
			\OCP\Util::addScript('arbeitszeitcheck', 'month-closure');
		}

		if (!isset($pageData['deletionEligibility']) && isset($pageData['entries']) && is_array($pageData['entries'])) {
			$deletionEligibility = [];
			foreach ($pageData['entries'] as $entry) {
				if ($entry instanceof TimeEntry) {
					$deletionEligibility[$entry->getId()] = $this->deletionPolicy->evaluate($entry);
				}
			}
			$pageData['deletionEligibility'] = $deletionEligibility;
		}

		return \array_merge($this->buildTimeEntriesShellParams('list', $navFlags), $pageData);
	}

	private function requiresChangeApproval(): bool
	{
		return $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_TIME_ENTRY_CHANGES_REQUIRE_APPROVAL, '0') === '1';
	}

	private function requiresManualEntryApproval(): bool
	{
		return $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_MANUAL_TIME_ENTRIES_REQUIRE_APPROVAL, '0') === '1';
	}

	/**
	 * @param array<string, mixed> $parsedChanges Keys that will be applied (date, hours, description, …)
	 */
	private function assertCanDirectUpdate(TimeEntry $entry, array $parsedChanges = []): ?JSONResponse
	{
		if ($entry->getStatus() === TimeEntry::STATUS_PENDING_APPROVAL) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('A correction is pending approval. Withdraw it first or wait for your manager.'),
				'error_code' => 'correction_pending',
			], Http::STATUS_CONFLICT);
		}

		if (!$this->requiresChangeApproval()) {
			return null;
		}

		if ($entry->getStatus() === TimeEntry::STATUS_PAUSED && $this->isPausedCompletionOnlyUpdate($entry, $parsedChanges)) {
			return null;
		}

		if (in_array($entry->getStatus(), [TimeEntry::STATUS_COMPLETED, TimeEntry::STATUS_PAUSED], true)) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Direct edits are disabled. Please request a correction for manager approval.'),
				'error_code' => 'correction_required',
				'correction_url' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries'),
			], Http::STATUS_FORBIDDEN);
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $parsedChanges
	 */
	private function isPausedCompletionOnlyUpdate(TimeEntry $entry, array $parsedChanges): bool
	{
		if ($entry->getStatus() !== TimeEntry::STATUS_PAUSED) {
			return false;
		}
		$allowedKeys = ['endTime', 'end_time', 'status'];
		foreach (array_keys($parsedChanges) as $key) {
			if (!in_array($key, $allowedKeys, true)) {
				return false;
			}
		}
		return isset($parsedChanges['endTime']) || isset($parsedChanges['end_time']);
	}

	/**
	 * Defaults when user context is unavailable (error pages).
	 *
	 * @return array{monthClosureEnabled: bool, showSubstitutionLink: bool, showManagerLink: bool, showReportsLink: bool, showAdminNav: bool}
	 */
	private function getTimeEntriesSharedTemplateParamsFallback(): array
	{
		return [
			'monthClosureEnabled' => false,
			'showSubstitutionLink' => false,
			'showManagerLink' => false,
			'showReportsLink' => false,
			'showAdminNav' => false,
		];
	}

	/**
	 * Parse date string - supports both ISO (yyyy-mm-dd) and German format (dd.mm.yyyy)
	 *
	 * @param string $dateString Date string in either format
	 * @return \DateTime
	 * @throws \Exception if date cannot be parsed
	 */
	private function getAppTimeZone(): \DateTimeZone
	{
		return AppLocalNaiveDateTimeNormalizer::appStorageTimeZoneFromConfig($this->config);
	}

	private function parseDate(string $dateString): \DateTime
	{
		$dateString = trim($dateString);
		if ($dateString === '') {
			throw new \Exception($this->l10n->t('Date is required and cannot be empty'));
		}

		$appTz = $this->getAppTimeZone();

		// Try German format first (dd.mm.yyyy)
		if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateString, $matches)) {
			$day = (int)$matches[1];
			$month = (int)$matches[2];
			$year = (int)$matches[3];

			// Validate date
			if (!checkdate($month, $day, $year)) {
				throw new \Exception($this->l10n->t('Invalid date: %s', [$dateString]));
			}

			$parsed = \DateTime::createFromFormat('!d.m.Y', $dateString, $appTz);
			if ($parsed === false) {
				throw new \Exception($this->l10n->t('Invalid date: %s', [$dateString]));
			}
			return $parsed;
		}

		$iso = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateString, $appTz);
		$isoErrors = \DateTimeImmutable::getLastErrors();
		$isoIsValid = $iso !== false
			&& ($isoErrors === false || (($isoErrors['warning_count'] ?? 0) === 0 && ($isoErrors['error_count'] ?? 0) === 0))
			&& $iso->format('Y-m-d') === $dateString;
		if ($isoIsValid) {
			return \DateTime::createFromImmutable($iso);
		}

		throw new \Exception($this->l10n->t('Invalid date format. Expected yyyy-mm-dd or dd.mm.yyyy: %s', [$dateString]));
	}

	/**
	 * Parse localized decimal input (supports comma and dot).
	 *
	 * Returns null for null/empty/non-numeric input so callers can keep legacy
	 * optional semantics while avoiding PHP's locale-insensitive float cast.
	 */
	private function parseNullableDecimal(mixed $value): ?float
	{
		if ($value === null) {
			return null;
		}
		$normalized = str_replace(',', '.', trim((string)$value));
		if ($normalized === '' || !is_numeric($normalized)) {
			return null;
		}
		return (float)$normalized;
	}

	private function parseIsoDateTime(string $value, string $fieldName): \DateTime
	{
		$formats = [
			\DateTime::ATOM,
			'Y-m-d\TH:i:s.u\Z',
			'Y-m-d\TH:i:s\Z',
			'Y-m-d\TH:i:sP',
		];
		foreach ($formats as $format) {
			$parsed = \DateTime::createFromFormat($format, $value);
			if ($parsed !== false) {
				return $parsed;
			}
		}
		throw new \Exception($this->l10n->t('Invalid %s format. Use ISO-8601 (e.g. 2024-01-15T09:00:00Z).', [$fieldName]));
	}

	private function countableMinBreakMinutes(?string $userId = null): int
	{
		try {
			return BreakCountable::minMinutes(
				\OCP\Server::get(LaborLawProfileFactory::class)->getProfile($userId)->minBreakMinutes
			);
		} catch (\Throwable) {
			return BreakCountable::DEFAULT_MIN_MINUTES;
		}
	}

	/**
	 * Build proposed start/end (and optional breaks) from the same date + HH:mm
	 * payload the manual time-entry form uses. Returns null when ISO instants
	 * should be parsed instead.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>|null
	 */
	private function buildProposedWorkTimesFromDateAndClock(array $params, ?string $userId = null): ?array
	{
		return \OCA\ArbeitszeitCheck\Support\TimeEntryClockPayloadBuilder::buildFromParams(
			$params,
			$this->countableMinBreakMinutes($userId)
		);
	}

	/**
	 * Accept breaks payloads from form-encoded and JSON clients.
	 *
	 * @param mixed $payload
	 * @return array<int, array<string, mixed>>|null
	 */
	private function decodeBreaksPayload(mixed $payload): ?array
	{
		if ($payload === null || $payload === '') {
			return null;
		}

		if (is_array($payload)) {
			return $payload;
		}

		if (!is_string($payload)) {
			return null;
		}

		$decoded = json_decode($payload, true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Get time entries endpoint
	 *
	 * Retrieves time entries for the current user with optional filtering by date range and status.
	 * Supports pagination for large datasets.
	 *
	 *
	 * @param string|null $start_date Start date filter (Y-m-d format)
	 * @param string|null $end_date End date filter (Y-m-d format)
	 * @param string|null $status Status filter (active, completed, break, pending_approval, rejected)
	 * @param int|null $limit Maximum number of entries to return (default: Constants::DEFAULT_LIST_LIMIT)
	 * @param int|null $offset Number of entries to skip for pagination (default: 0)
	 * @return JSONResponse JSON response with 'success', 'entries' array, and 'total' count
	 */
	#[NoAdminRequired]
	public function index(?string $start_date = null, ?string $end_date = null, ?string $status = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$limit = $limit !== null ? min(max(1, (int)$limit), Constants::MAX_LIST_LIMIT) : Constants::DEFAULT_LIST_LIMIT;
			$offset = $offset !== null ? max(0, (int)$offset) : 0;
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
			$filters['limit'] = $limit;
			$filters['offset'] = $offset;

			// Build filters array for mapper count method (uses database filtering)
			$countFilters = ['user_id' => $userId];
			if ($start_date) {
				try {
					$countFilters['start_date'] = $this->parseDate($start_date);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Invalid start_date format: ' . $start_date, ['exception' => $e]);
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid start date format')
					], Http::STATUS_BAD_REQUEST);
				}
			}
			if ($end_date) {
				try {
					$countFilters['end_date'] = $this->parseDate($end_date);
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
					$appTz = $this->getAppTimeZone();
					try {
						$startDateTime = $start_date ? $this->parseDate($start_date) : new \DateTime('1970-01-01', $appTz);
						$startDateTime->setTime(0, 0, 0);
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->error('Invalid start_date format: ' . $start_date, ['exception' => $e]);
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Invalid start date format')
						], Http::STATUS_BAD_REQUEST);
					}
					try {
						$endDateTime = $end_date ? $this->parseDate($end_date) : new \DateTime('2099-12-31', $appTz);
						// Exclusive upper bound: start of the next calendar day so that entries at
						// any time on $end_date are included (findByUserAndDateRange uses strict <).
						$endDateTime->setTime(0, 0, 0);
						$endDateTime->modify('+1 day');
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->error('Invalid end_date format: ' . $end_date, ['exception' => $e]);
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Invalid end date format')
						], Http::STATUS_BAD_REQUEST);
					}
					if ($startDateTime > $endDateTime) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Start date cannot be after end date')
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
			$entries = array_slice($allEntries, $offset, $limit);

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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Show create time entry form page
	 *
	 * @return TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): TemplateResponse
	{
		\OCA\ArbeitszeitCheck\Service\FrontEndAssetService::registerTranslations();

		$userId = $this->getUserId();

		if (!$this->timeCaptureMethodService->isManualTimeEntryEnabled($userId)) {
			$entries = $this->timeEntryMapper->findByUser($userId, 100);
			$response = new TemplateResponse(
				$this->appName,
				'time-entries',
				$this->buildTimeEntriesListTemplateParams([
					'urlGenerator' => $this->urlGenerator,
					'mode' => 'list',
					'entries' => $entries,
					'stats' => [
						'total_time_entries' => $this->timeEntryMapper->countByUser($userId),
						'entries_this_month' => count(array_filter($entries, static function ($entry) {
							return $entry->getStartTime() && $entry->getStartTime()->format('Y-m') === date('Y-m');
						})),
						'total_hours' => array_reduce($entries, static function ($sum, $entry) {
							return $sum + $entry->getWorkingDurationHours();
						}, 0.0),
					],
					'error' => $this->l10n->t('Manual time entries are not enabled for your account. Please contact your administrator.'),
					'l' => $this->l10n,
				] + $this->getTimeEntriesSharedTemplateParams($userId))
			);
			return $this->configureCSP($response);
		}

		// Get compliance configuration for frontend validation
		$maxDailyHours = (float)$this->getConfiguredMaxDailyHours();
		$complianceStrictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
		$createFormLinkingEnabled = $this->projectCheckIntegration->isLinkingEnabledForUser($userId);

			$response = new TemplateResponse(
			$this->appName,
			'time-entries',
			$this->buildTimeEntryFormTemplateParams('create', [
				'urlGenerator' => $this->urlGenerator,
				'mode' => 'create',
				'entry' => null,
				'entries' => [],
				'stats' => [],
				'maxDailyHours' => $maxDailyHours,
				'complianceStrictMode' => $complianceStrictMode,
				'l' => $this->l10n,
				'projectCheckAvailable' => $this->projectCheckIntegration->isProjectCheckAvailable(),
				'projectCheckAdminLinkingEnabled' => $createFormLinkingEnabled,
				'projectCheckEnabled' => $createFormLinkingEnabled,
				'projectCheckProjects' => $createFormLinkingEnabled ? $this->projectCheckIntegration->getAvailableProjects($userId) : [],
				'projectCheckAssignableUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiAssignableProjectcheckProjects'),
			] + $this->getTimeEntriesSharedTemplateParams($userId))
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
	 * - Manual entries, pending approval, completed automatic entries, and paused (unfinished automatic) entries can be edited
	 * - Approved entries cannot be edited (use "Request Correction" instead)
	 *
	 * @param int $id Time entry ID to edit
	 * @return TemplateResponse Template response with time-entries template and entry data, or error message
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function edit(int $id): TemplateResponse
	{
		\OCA\ArbeitszeitCheck\Service\FrontEndAssetService::registerTranslations();

		try {
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			// Check ownership
			if ($entry->getUserId() !== $userId) {
				$maxDailyHours = (float)$this->getConfiguredMaxDailyHours();
				$complianceStrictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
				$response = new TemplateResponse(
					$this->appName,
					'time-entries',
					$this->buildTimeEntriesListTemplateParams([
						'mode' => 'list',
						'entry' => null,
						'entries' => [],
						'stats' => [],
						'error' => $this->l10n->t('Access denied'),
						'maxDailyHours' => $maxDailyHours,
						'complianceStrictMode' => $complianceStrictMode,
					] + $this->getTimeEntriesSharedTemplateParams($userId))
				);
				return $this->configureCSP($response);
			}

			// Check if entry can be edited (logic lives in TimeEntry::canEdit to stay DRY)
			$canEdit = $entry->canEdit(Constants::EDIT_WINDOW_DAYS);

			if (!$canEdit) {
				$isApproved        = $entry->isLockedForEmployeeEdit();
				$entryDate         = $entry->getStartTime();
				$editCutoff        = new \DateTime();
				$editCutoff->modify('-' . Constants::EDIT_WINDOW_DAYS . ' days');
				$editCutoff->setTime(0, 0, 0);
				$isWithinEditWindow = $entryDate && $entryDate >= $editCutoff;

				$errorMessage = $isApproved
					? $this->l10n->t('Cannot edit this time entry. Please use "Request Correction" for approved entries.')
					: (!$isWithinEditWindow
						? $this->l10n->t('Cannot edit this time entry. Only entries from the last 2 weeks can be edited.')
						: $this->l10n->t('Cannot edit this time entry.'));

				$maxDailyHours = (float)$this->getConfiguredMaxDailyHours();
				$complianceStrictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
				$response = new TemplateResponse(
					$this->appName,
					'time-entries',
					$this->buildTimeEntriesListTemplateParams([
						'mode' => 'list',
						'entry' => null,
						'entries' => [],
						'stats' => [],
						'error' => $errorMessage,
						'maxDailyHours' => $maxDailyHours,
						'complianceStrictMode' => $complianceStrictMode,
					] + $this->getTimeEntriesSharedTemplateParams($userId))
				);
				return $this->configureCSP($response);
			}

			// Get compliance configuration for frontend validation
			$maxDailyHours = (float)$this->getConfiguredMaxDailyHours();
			$complianceStrictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
			// Show the picker when the connection is on, or when this entry already
			// carries a link, so the user can still see (and clear) an existing
			// project even after switching the connection off.
			$editFormLinkingEnabled = $this->projectCheckIntegration->isLinkingEnabledForUser($userId)
				|| ($entry->getProjectCheckProjectId() !== null && $entry->getProjectCheckProjectId() !== '');
			$editAdminLinkingEnabled = $this->projectCheckIntegration->isLinkingEnabledForUser($userId);

			$response = new TemplateResponse(
				$this->appName,
				'time-entries',
				$this->buildTimeEntryFormTemplateParams('edit', [
					'urlGenerator' => $this->urlGenerator,
					'mode' => 'edit',
					'entry' => $entry,
					'entries' => [],
					'stats' => [],
					'maxDailyHours' => $maxDailyHours,
					'complianceStrictMode' => $complianceStrictMode,
					'l' => $this->l10n,
					'projectCheckAvailable' => $this->projectCheckIntegration->isProjectCheckAvailable(),
					'projectCheckAdminLinkingEnabled' => $editAdminLinkingEnabled,
					'projectCheckEnabled' => $editFormLinkingEnabled,
					'projectCheckProjects' => $editFormLinkingEnabled ? $this->projectCheckIntegration->getAvailableProjects($userId) : [],
					'projectCheckAssignableUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.apiAssignableProjectcheckProjects'),
				] + $this->getTimeEntriesSharedTemplateParams($userId))
			);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in edit method: ' . $e->getMessage(), ['exception' => $e]);
			$shared = $this->getTimeEntriesSharedTemplateParamsFallback();
			try {
				$shared = $this->getTimeEntriesSharedTemplateParams($this->getUserId());
			} catch (\Throwable $ignore) {
			}
			$maxDailyHours = (float)$this->getConfiguredMaxDailyHours();
			$complianceStrictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
			$response = new TemplateResponse(
				$this->appName,
				'time-entries',
				$this->buildTimeEntriesListTemplateParams([
					'mode' => 'list',
					'entry' => null,
					'entries' => [],
					'stats' => [],
					'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
					'maxDailyHours' => $maxDailyHours,
					'complianceStrictMode' => $complianceStrictMode,
				] + $shared)
			);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Get time entry by ID endpoint
	 *
	 *
	 * @param int $id Time entry ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function show(int $id): JSONResponse
	{
		try {
			if ($id <= 0) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid entry ID')], Http::STATUS_BAD_REQUEST);
			}
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			// Check ownership
			if ($entry->getUserId() !== $userId) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied')
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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create time entry endpoint (manual entry)
	 *
	 *
	 * @param string $date Date (Y-m-d)
	 * @param float $hours Hours worked
	 * @param string|null $description Description
	 * @param string|null $project_check_project_id Project ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function store(string $date, float $hours, ?string $description = null, ?string $project_check_project_id = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$blocked = $this->manualTimeEntryBlockedResponse($userId);
			if ($blocked !== null) {
				return $blocked;
			}

			// Reject invalid hours (ArbZG §3: max 10h/day, no negative/zero manual entries)
			if ($hours <= 0 || $hours > 24) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Hours must be between 0.01 and 24')
				], Http::STATUS_BAD_REQUEST);
			}

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
			$project_check_project_id = $this->normalizeOptionalProjectCheckProjectId($project_check_project_id);
			if ($project_check_project_id !== null && mb_strlen($project_check_project_id) > TimeEntry::PROJECT_CHECK_PROJECT_ID_MAX_LENGTH) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Project ID must not exceed %d characters', [TimeEntry::PROJECT_CHECK_PROJECT_ID_MAX_LENGTH])
				], Http::STATUS_BAD_REQUEST);
			}
			$pcBlock = $this->assertProjectCheckAttachAllowed($userId, $project_check_project_id);
			if ($pcBlock !== null) {
				return $pcBlock;
			}
			$timeEntry->setProjectCheckProjectId($project_check_project_id);
			$manualRequiresApproval = $this->requiresManualEntryApproval();
			$params = $this->request->getParams();
			$justificationText = trim((string)($params['justification'] ?? ''));
			if ($manualRequiresApproval) {
				if (mb_strlen($justificationText) < 10) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('A justification of at least 10 characters is required for manual time entries.'),
						'error_code' => 'justification_too_short',
					], Http::STATUS_BAD_REQUEST);
				}
			} else {
				$justificationText = $justificationText !== '' ? $justificationText : 'Manual entry created via employee portal';
			}

			$timeEntry->setStatus($manualRequiresApproval ? TimeEntry::STATUS_PENDING_APPROVAL : TimeEntry::STATUS_COMPLETED);
			$timeEntry->setIsManualEntry(true);
			// Always set plain justification before validate(); prepareManualPending()
			// rewrites it to the JSON envelope when four-eyes approval is required.
			$timeEntry->setJustification($justificationText);
			$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
			$timeEntry->setCreatedAt($nowAt);
			$timeEntry->setUpdatedAt(clone $nowAt);

			// Check rest period / overlap / insert under the same exclusive lock as clock mutations
			// so two parallel saves cannot both pass findOverlapping.
			try {
				return $this->timeTrackingService->withUserMutationLock($userId, function () use (
					$userId,
					$timeEntry,
					$manualRequiresApproval,
					$justificationText
				) {
					// Check rest period compliance before saving (ArbZG §5)
					if ($timeEntry->getStartTime()) {
						$restPeriodCheck = $this->complianceService->checkRestPeriodForStartTime($userId, $timeEntry->getStartTime());
						if (!$restPeriodCheck['valid']) {
							return new JSONResponse([
								'success' => false,
								'error' => $restPeriodCheck['message']
							], Http::STATUS_BAD_REQUEST);
						}
					}

					// CRITICAL: First calculate automatic breaks (ArbZG §4)
					// This must happen BEFORE adjusting end time, because breaks affect the working duration
					if ($timeEntry->getEndTime() && $timeEntry->getStartTime()) {
						// First: Calculate and set automatic break if no break was entered (ArbZG §4)
						$this->timeTrackingService->calculateAndSetAutomaticBreak($timeEntry);

						// Then: Adjust end time to comply with daily maximum working hours (ArbZG §3: max 10 hours per day)
						// This uses the correct break duration for accurate working time calculation
						$this->timeTrackingService->adjustEndTimeForDailyMaximum($timeEntry);

						// After adjustment, breaks might need recalculation if end time changed significantly
						// But this is rare, so we skip it to avoid infinite loops
					}

					// Check for overlapping entries before saving
					if ($timeEntry->getStartTime() && $timeEntry->getEndTime()) {
						$overlapping = $this->timeEntryMapper->findOverlapping(
							$userId,
							$timeEntry->getStartTime(),
							$timeEntry->getEndTime()
						);

						if (!empty($overlapping)) {
							$overlapDetails = [];
							foreach ($overlapping as $overlapEntry) {
								$overlapStart = $this->displayClock($overlapEntry->getStartTime(), $userId);
								$overlapEnd = $this->displayClock($overlapEntry->getEndTime(), $userId);
								$overlapDetails[] = $overlapStart . ' - ' . $overlapEnd;
							}
							$overlapMessage = $this->l10n->t('This time entry overlaps with existing entries: %s', [implode(', ', $overlapDetails)]);
							return new JSONResponse([
								'success' => false,
								'error' => $overlapMessage
							], Http::STATUS_BAD_REQUEST);
						}
					}

					// Validate entry before inserting
					$timeEntry->setCountableMinBreakMinutes($this->countableMinBreakMinutes($timeEntry->getUserId()));
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

					$mc = $this->assertGuardTimeEntry($timeEntry);
					if ($mc !== null) {
						return $mc;
					}

					$complianceBlock = $this->complianceSaveBlockResponse($timeEntry);
					if ($complianceBlock !== null) {
						return $complianceBlock;
					}

					if ($manualRequiresApproval) {
						$this->correctionService->prepareManualPending($timeEntry, $justificationText);
					}

					$savedEntry = $this->timeEntryMapper->insert($timeEntry);

					if ($manualRequiresApproval) {
						try {
							$this->notificationService->notifyTimeEntryCorrectionRequested(
								$userId,
								$savedEntry->getSummary(),
								$justificationText
							);
						} catch (\Throwable $e) {
							\OCP\Log\logger('arbeitszeitcheck')->warning('Failed to send manual entry approval notification', ['exception' => $e]);
						}
						if (!$this->teamResolver->hasAssignableManagerForEmployee($userId)) {
							$savedEntry = $this->correctionService->autoApprove($savedEntry);
							$this->auditLogMapper->logAction($userId, 'time_entry_correction_auto_approved', 'time_entry', $savedEntry->getId(), null, ['approved_by' => 'system'], 'system');
						} else {
							$this->auditLogMapper->logAction($userId, 'time_entry_manual_create_requested', 'time_entry', $savedEntry->getId(), null, $savedEntry->getSummary());
						}
						return new JSONResponse([
							'success' => true,
							'entry' => $savedEntry->getSummary(),
							'message' => $this->l10n->t('Manual time entry submitted for manager approval.')
						], Http::STATUS_CREATED);
					}

					$this->syncProjectCheckBillingAfterSave($savedEntry, $userId);

					// Real-time compliance check for completed entries
					if ($savedEntry->getStatus() === TimeEntry::STATUS_COMPLETED && $savedEntry->getEndTime() !== null) {
						$this->performRealTimeComplianceCheck($savedEntry);
					}

					try {
						$this->auditLogMapper->logAction(
							$userId,
							'time_entry_created',
							'time_entry',
							$savedEntry->getId(),
							null,
							$savedEntry->getSummary()
						);
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->error('Error creating audit log for time entry create: ' . $e->getMessage(), ['exception' => $e]);
					}

					return new JSONResponse([
						'success' => true,
						'entry' => $savedEntry->getSummary()
					], Http::STATUS_CREATED);
				});
			} catch (LockedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Another time-tracking change is in progress. Please try again in a moment.'),
					'error_code' => 'locked',
				], Http::STATUS_LOCKED);
			}
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
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
	 * - Manual entries, pending approval, completed automatic entries, and paused (unfinished automatic) entries can be edited
	 * - Approved entries cannot be edited (use "Request Correction" instead)
	 *
	 *
	 * @param int $id Time entry ID to update
	 * @param string|null $date New date (Y-m-d format, backward compatibility)
	 * @param float|null $hours New hours worked (backward compatibility)
	 * @param string|null $description New description
	 * @param string|null $project_check_project_id New ProjectCheck project ID
	 * @return JSONResponse JSON response with 'success' and updated 'entry' data, or 'error' on failure
	 * @throws DoesNotExistException If time entry not found
	 * @throws \Exception If user doesn't own the entry, entry cannot be edited, or validation fails
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(int $id, ?string $date = null, ?float $hours = null, ?string $description = null, ?string $project_check_project_id = null): JSONResponse
	{
		try {
			if ($id <= 0) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid entry ID')], Http::STATUS_BAD_REQUEST);
			}
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			// Check ownership
			if ($entry->getUserId() !== $userId) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied')
				], Http::STATUS_FORBIDDEN);
			}

			$mc0 = $this->assertGuardTimeEntry($entry);
			if ($mc0 !== null) {
				return $mc0;
			}

			// Check if entry can be edited (logic lives in TimeEntry::canEdit to stay DRY)
			$canEdit = $entry->canEdit(Constants::EDIT_WINDOW_DAYS);

			if (!$canEdit) {
				$isApproved        = $entry->isLockedForEmployeeEdit();
				$entryDate         = $entry->getStartTime();
				$editCutoff        = new \DateTime();
				$editCutoff->modify('-' . Constants::EDIT_WINDOW_DAYS . ' days');
				$editCutoff->setTime(0, 0, 0);
				$isWithinEditWindow = $entryDate && $entryDate >= $editCutoff;

				$errorMessage = $isApproved
					? $this->l10n->t('Cannot edit this time entry. Please use "Request Correction" for approved entries.')
					: (!$isWithinEditWindow
						? $this->l10n->t('Cannot edit this time entry. Only entries from the last 2 weeks can be edited.')
						: $this->l10n->t('Cannot edit this time entry.'));

				return new JSONResponse([
					'success' => false,
					'error' => $errorMessage
				], Http::STATUS_BAD_REQUEST);
			}

			// Get data from request body
			$params = $this->request->getParams();
			$dateParam = $params['date'] ?? $date;
			$startTime = $params['startTime'] ?? null;
			$endTime = $params['endTime'] ?? null;
			$breakStartTime = $params['breakStartTime'] ?? null;
			$breakEndTime = $params['breakEndTime'] ?? null;
			$breaksJson = $params['breaks'] ?? null;

			$directUpdateBlock = $this->assertCanDirectUpdate($entry, array_filter([
				'date' => $dateParam,
				'startTime' => $startTime,
				'endTime' => $endTime,
				'hours' => $hours,
				'description' => $description ?? ($params['description'] ?? null),
				'breakStartTime' => $breakStartTime,
				'breakEndTime' => $breakEndTime,
				'breaks' => $breaksJson,
			], static fn ($v) => $v !== null && $v !== ''));
			if ($directUpdateBlock !== null) {
				return $directUpdateBlock;
			}

			// New format: startTime and endTime
			if ($startTime && $endTime) {
				try {
					// Combine date + local time for correct calendar day.
					// If $startTime / $endTime already contain a full ISO datetime, respect that.
					$baseDate = null;
					if ($dateParam !== null && $dateParam !== '') {
						try {
							$baseDate = $this->parseDate($dateParam);
						} catch (\Throwable $e) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Invalid date: %s', [$dateParam]),
							], Http::STATUS_BAD_REQUEST);
						}
					}

					$startDateTime = null;
					$endDateTime = null;

					$isPlainTime = static function (string $value): bool {
						return (bool)\preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value);
					};

					// Start
					if ($baseDate instanceof \DateTime && $isPlainTime($startTime)) {
						$startDateTime = clone $baseDate;
						[$h, $m] = \explode(':', $startTime, 2);
						$startDateTime->setTime((int)$h, (int)$m, 0);
					} else {
						$startDateTime = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($startTime, $this->getAppTimeZone());
					}

					// End
					if ($baseDate instanceof \DateTime && $isPlainTime($endTime)) {
						$endDateTime = clone $baseDate;
						[$h, $m] = \explode(':', $endTime, 2);
						$endDateTime->setTime((int)$h, (int)$m, 0);
					} else {
						$endDateTime = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($endTime, $this->getAppTimeZone());
					}

					// Overnight work: if we have a base date and end < start, treat end as next day
					if ($baseDate instanceof \DateTime && $endDateTime < $startDateTime) {
						$endDateTime->modify('+1 day');
					}

					$entry->setStartTime($startDateTime);
					$entry->setEndTime($endDateTime);
				} catch (\Throwable $e) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid start or end time format. Use ISO 8601 (e.g. Y-m-dTH:i:s).')
					], Http::STATUS_BAD_REQUEST);
				}

				// Handle breaks: prefer breaks JSON (multiple breaks) over single break fields
				$breaks = $this->decodeBreaksPayload($breaksJson);
				if ($breaks !== null) {
					if (!empty($breaks)) {
						// Filter out breaks shorter than the profile floor (DE/CH 15, AT 10)
						$validBreaks = [];
						$minBreakDurationSeconds = BreakCountable::minSeconds(
							$this->countableMinBreakMinutes($entry->getUserId())
						);
						foreach ($breaks as $break) {
							// Support both {start,end} and {start_time,end_time}
							$startKey = isset($break['start']) ? 'start' : (isset($break['start_time']) ? 'start_time' : null);
							$endKey = isset($break['end']) ? 'end' : (isset($break['end_time']) ? 'end_time' : null);
							if ($startKey === null || $endKey === null) {
								continue;
							}

							try {
								$rawStart = (string)$break[$startKey];
								$rawEnd = (string)$break[$endKey];

								// Combine date + time if only local times were provided
								if ($baseDate instanceof \DateTime && $isPlainTime($rawStart)) {
									$breakStart = clone $baseDate;
									[$h, $m] = \explode(':', $rawStart, 2);
									$breakStart->setTime((int)$h, (int)$m, 0);
								} else {
									$breakStart = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($rawStart, $this->getAppTimeZone());
								}

								if ($baseDate instanceof \DateTime && $isPlainTime($rawEnd)) {
									$breakEnd = clone $baseDate;
									[$h, $m] = \explode(':', $rawEnd, 2);
									$breakEnd->setTime((int)$h, (int)$m, 0);
								} else {
									$breakEnd = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($rawEnd, $this->getAppTimeZone());
								}

								// Handle overnight breaks
								if ($breakEnd < $breakStart) {
									$breakEnd->modify('+1 day');
								}

								$breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();

								// Only include breaks that meet the profile countable floor
								if ($breakDurationSeconds >= $minBreakDurationSeconds) {
									$validBreaks[] = [
										'start' => $breakStart->format('c'),
										'end' => $breakEnd->format('c')
									];
								}
							} catch (\Exception $e) {
								// Skip invalid break times
								continue;
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
						// Empty breaks payload, clear everything
						$entry->setBreaks(null);
						$entry->setBreakStartTime(null);
						$entry->setBreakEndTime(null);
					}
				} elseif ($breakStartTime && $breakEndTime) {
					// Fallback to single break fields (backward compatibility)
					try {
						$singleBreakStart = null;
						$singleBreakEnd = null;

						if ($baseDate instanceof \DateTime && $isPlainTime($breakStartTime)) {
							$singleBreakStart = clone $baseDate;
							[$h, $m] = \explode(':', $breakStartTime, 2);
							$singleBreakStart->setTime((int)$h, (int)$m, 0);
						} else {
							$singleBreakStart = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($breakStartTime, $this->getAppTimeZone());
						}

						if ($baseDate instanceof \DateTime && $isPlainTime($breakEndTime)) {
							$singleBreakEnd = clone $baseDate;
							[$h, $m] = \explode(':', $breakEndTime, 2);
							$singleBreakEnd->setTime((int)$h, (int)$m, 0);
						} else {
							$singleBreakEnd = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($breakEndTime, $this->getAppTimeZone());
						}

						if ($singleBreakEnd < $singleBreakStart) {
							$singleBreakEnd->modify('+1 day');
						}

						$entry->setBreakStartTime($singleBreakStart);
						$entry->setBreakEndTime($singleBreakEnd);
					} catch (\Throwable $e) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Invalid break start or end time format.')
						], Http::STATUS_BAD_REQUEST);
					}
					// Clear breaks JSON when using single break fields
					$entry->setBreaks(null);
				} else {
					// Clear all break times if not provided
					$entry->setBreakStartTime(null);
					$entry->setBreakEndTime(null);
					$entry->setBreaks(null);
				}

				// Adjust end time to comply with daily maximum working hours (ArbZG §3: max 10 hours per day)
				if ($entry->getEndTime() && $entry->getStartTime()) {
					$this->timeTrackingService->calculateAndSetAutomaticBreak($entry);
					$this->timeTrackingService->adjustEndTimeForDailyMaximum($entry);
				}
			}
			// Old format: date and hours (backward compatibility)
			else {
				if ($date) {
					try {
						$entry->setStartTime($this->parseDate($date));
					} catch (\Throwable $e) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Invalid date: %s', [$date]),
						], Http::STATUS_BAD_REQUEST);
					}
				}
				if ($hours !== null) {
					if ($hours <= 0 || $hours > 24) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Hours must be between 0.01 and 24')
						], Http::STATUS_BAD_REQUEST);
					}
					// Calculate end time based on hours from start time
					if ($entry->getStartTime()) {
						$startTime = clone $entry->getStartTime();
						$endTime = clone $startTime;
						$endTime->modify('+' . round($hours * 3600) . ' seconds');
						$entry->setEndTime($endTime);
					}
				}

				// Adjust end time to comply with daily maximum working hours (ArbZG §3: max 10 hours per day)
				if ($entry->getEndTime() && $entry->getStartTime()) {
					$this->timeTrackingService->calculateAndSetAutomaticBreak($entry);
					$this->timeTrackingService->adjustEndTimeForDailyMaximum($entry);
				}
			}

			// Update description from params or function parameter
			if (isset($params['description'])) {
				$entry->setDescription($params['description']);
			} elseif ($description !== null) {
				$entry->setDescription($description);
			}

			$rawProjectId = null;
			if (\array_key_exists('project_check_project_id', $params) || \array_key_exists('projectCheckProjectId', $params)) {
				$rawProjectId = $params['project_check_project_id'] ?? $params['projectCheckProjectId'] ?? '';
			} elseif ($project_check_project_id !== null) {
				$rawProjectId = $project_check_project_id;
			}
			if ($rawProjectId !== null) {
				$normalizedPid = $this->normalizeOptionalProjectCheckProjectId($rawProjectId);
				$currentPid = $entry->getProjectCheckProjectId();
				$currentPid = ($currentPid === null || $currentPid === '') ? null : (string)$currentPid;
				// Only authorise (and write) the link when it actually changes.
				// This lets a user edit other fields of an entry that already
				// carries a project they can no longer attach — e.g. their team
				// membership/access was revoked, or they switched the ProjectCheck
				// connection off — without being blocked, while still validating
				// every genuine add/change. Clearing a link (to null) is always
				// permitted by assertProjectCheckAttachAllowed().
				if ($normalizedPid !== $currentPid) {
					$pcBlock = $this->assertProjectCheckAttachAllowed($userId, $normalizedPid);
					if ($pcBlock !== null) {
						return $pcBlock;
					}
					$entry->setProjectCheckProjectId($normalizedPid);
				}
			}

			// Finalise paused entries: when the user supplies an end_time, the entry
			// is no longer orphaned – promote it to completed so all downstream logic
			// (compliance checks, reports, approval flow) treats it correctly.
			if ($entry->getStatus() === TimeEntry::STATUS_PAUSED && $entry->getEndTime() !== null) {
				$entry->setStatus(TimeEntry::STATUS_COMPLETED);
				if (!$entry->getEndedReason()) {
					$entry->setEndedReason(TimeEntry::ENDED_REASON_MANUAL_CLOCK_OUT);
				}
			}

			// Check rest / overlap / update under the same exclusive lock as clock mutations.
			try {
				return $this->timeTrackingService->withUserMutationLock($userId, function () use ($userId, $entry, $id) {
					// Check rest period compliance before saving (ArbZG §5)
					if ($entry->getStartTime()) {
						$restPeriodCheck = $this->complianceService->checkRestPeriodForStartTime($userId, $entry->getStartTime(), $id);
						if (!$restPeriodCheck['valid']) {
							return new JSONResponse([
								'success' => false,
								'error' => $restPeriodCheck['message']
							], Http::STATUS_BAD_REQUEST);
						}
					}

					// CRITICAL: First calculate automatic breaks (ArbZG §4), then adjust for daily maximum (ArbZG §3)
					if ($entry->getEndTime() && $entry->getStartTime()) {
						$this->timeTrackingService->calculateAndSetAutomaticBreak($entry);
						$this->timeTrackingService->adjustEndTimeForDailyMaximum($entry);
					}

					// Check for overlapping entries before saving (exclude this entry from overlap check)
					if ($entry->getStartTime() && $entry->getEndTime()) {
						$overlapping = $this->timeEntryMapper->findOverlapping(
							$userId,
							$entry->getStartTime(),
							$entry->getEndTime(),
							$id // Exclude this entry from overlap check
						);

						if (!empty($overlapping)) {
							$overlapDetails = [];
							foreach ($overlapping as $overlapEntry) {
								$overlapStart = $this->displayClock($overlapEntry->getStartTime(), $userId);
								$overlapEnd = $this->displayClock($overlapEntry->getEndTime(), $userId);
								$overlapDetails[] = $overlapStart . ' - ' . $overlapEnd;
							}
							$overlapMessage = $this->l10n->t('This time entry overlaps with existing entries: %s', [implode(', ', $overlapDetails)]);
							return new JSONResponse([
								'success' => false,
								'error' => $overlapMessage
							], Http::STATUS_BAD_REQUEST);
						}
					}

					// Validate entry (automatically adjusts end time to 10h if exceeded)
					$entry->setCountableMinBreakMinutes($this->countableMinBreakMinutes($entry->getUserId()));
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

					$entry->setUpdatedAt(AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config));
					$mc1 = $this->assertGuardTimeEntry($entry);
					if ($mc1 !== null) {
						return $mc1;
					}

					$complianceBlock = $this->complianceSaveBlockResponse($entry);
					if ($complianceBlock !== null) {
						return $complianceBlock;
					}

					$updatedEntry = $this->timeEntryMapper->update($entry);
					$this->syncProjectCheckBillingAfterSave($updatedEntry, $userId);

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
				});
			} catch (LockedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Another time-tracking change is in progress. Please try again in a moment.'),
					'error_code' => 'locked',
				], Http::STATUS_LOCKED);
			}
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update time entry endpoint (POST method for form submissions)
	 *
	 * Handles POST requests for updating time entries. Delegates to the update() method.
	 * Supports both old format (date, hours) and new format (startTime, endTime, breakStartTime, breakEndTime).
	 *
	 * @param int $id Time entry ID to update
	 * @return JSONResponse JSON response with 'success' and updated 'entry' data, or 'error' on failure
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
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
		$hours = $this->parseNullableDecimal($params['hours'] ?? null);
		$description = $params['description'] ?? null;
		$project_check_project_id = $this->normalizeOptionalProjectCheckProjectId($params['project_check_project_id'] ?? $params['projectCheckProjectId'] ?? null);

		return $this->update($id, $date, $hours, $description, $project_check_project_id);
	}

	/**
	 * Get deletion impact information for a time entry
	 *
	 * Returns information about what will be affected if the time entry is deleted,
	 * such as related compliance violations, reports, or other dependencies.
	 *
	 *
	 * @param int $id Time entry ID
	 * @return JSONResponse JSON response with 'success' and 'impact' information
	 */
	#[NoAdminRequired]
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

			$eligibility = $this->deletionPolicy->evaluate($entry);
			$impact = [
				'canDelete' => $eligibility['canDelete'],
				'isManualEntry' => $eligibility['isManualEntry'],
				'status' => $eligibility['status'],
				'blockCode' => $eligibility['blockCode'],
				'blockMessage' => $eligibility['blockMessage'],
				'warnings' => $eligibility['warnings'],
			];

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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
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
	 *
	 * @param int $id Time entry ID to request correction for
	 * @return JSONResponse JSON response with 'success' and updated 'entry' data, or 'error' on failure
	 * @throws DoesNotExistException If time entry not found
	 * @throws \Exception If user doesn't own the entry, correction already pending, or validation fails
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function requestCorrection(int $id): JSONResponse
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

			$mcReq = $this->assertGuardTimeEntry($entry);
			if ($mcReq !== null) {
				return $mcReq;
			}

			// Check if entry can be corrected (not already pending)
			$currentStatus = $entry->getStatus();
			if ($currentStatus === TimeEntry::STATUS_PENDING_APPROVAL) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Correction request already pending'),
					'error_code' => 'correction_pending',
				], Http::STATUS_BAD_REQUEST);
			}

			// Get data from request body
			$params = $this->request->getParams();
			$justification = $params['justification'] ?? null;
			$startTime = $params['startTime'] ?? null;
			$endTime = $params['endTime'] ?? null;
			$breakStartTime = $params['breakStartTime'] ?? null;
			$breakEndTime = $params['breakEndTime'] ?? null;
			$breaks = isset($params['breaks']) && is_array($params['breaks']) ? $params['breaks'] : null;
			$description = $params['description'] ?? null;

			// Backward compatibility: support old format (newDate, newHours, newDescription)
			$newDate = $params['newDate'] ?? null;
			$newHours = $this->parseNullableDecimal($params['newHours'] ?? null);
			$newDescription = $params['newDescription'] ?? null;

			// Require justification for correction request — enforce length server-side
			// so a malicious client cannot bypass the wizard's 10-char minimum.
			if (!is_string($justification)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Justification is required for correction requests'),
					'error_code' => 'justification_required',
				], Http::STATUS_BAD_REQUEST);
			}
			$justification = trim($justification);
			if (mb_strlen($justification) < 10) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Please provide a reason of at least 10 characters.'),
					'error_code' => 'justification_too_short',
				], Http::STATUS_BAD_REQUEST);
			}
			// Bound DB write to prevent abuse / log-blowup.
			if (mb_strlen($justification) > 2000) {
				$justification = mb_substr($justification, 0, 2000);
			}

			// Store proposed changes in justification field (format: JSON with original and proposed values)
			$entryStartTime = $entry->getStartTime();
			if (!$entryStartTime) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Time entry has no start time'),
					'error_code' => 'time_entry_no_start',
				], Http::STATUS_BAD_REQUEST);
			}

			$originalBreaks = null;
			if ($entry->getBreaks() !== null && $entry->getBreaks() !== '') {
				$decodedBreaks = json_decode($entry->getBreaks(), true);
				if (is_array($decodedBreaks)) {
					$originalBreaks = $decodedBreaks;
				}
			}

			$originalData = [
				'startTime' => $entryStartTime->format('c'),
				'endTime' => $entry->getEndTime() ? $entry->getEndTime()->format('c') : null,
				'status' => $currentStatus,
				'breakStartTime' => $entry->getBreakStartTime() ? $entry->getBreakStartTime()->format('c') : null,
				'breakEndTime' => $entry->getBreakEndTime() ? $entry->getBreakEndTime()->format('c') : null,
				'breaks' => $originalBreaks,
				'durationHours' => $entry->getDurationHours(),
				'description' => $entry->getDescription()
			];

			$proposedData = [];

			$clockBased = null;
			try {
				$clockBased = $this->buildProposedWorkTimesFromDateAndClock($params, $entry->getUserId());
			} catch (\Exception $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $e->getMessage(),
				], Http::STATUS_BAD_REQUEST);
			}
			if ($clockBased !== null) {
				$proposedData = $clockBased;
				if ($description !== null) {
					$proposedData['description'] = $description;
				}
			} elseif ($startTime && $endTime) {
				// ISO-8601 instants (legacy clients / API integrations)
				$proposedStartTime = $this->parseIsoDateTime($startTime, 'start_time');
				$proposedEndTime = $this->parseIsoDateTime($endTime, 'end_time');
				$proposedData['startTime'] = $proposedStartTime->format('c');
				$proposedData['endTime'] = $proposedEndTime->format('c');

				if ($breakStartTime && $breakEndTime) {
					$proposedBreakStartTime = $this->parseIsoDateTime($breakStartTime, 'break_start_time');
					$proposedBreakEndTime = $this->parseIsoDateTime($breakEndTime, 'break_end_time');
					$proposedData['breakStartTime'] = $proposedBreakStartTime->format('c');
					$proposedData['breakEndTime'] = $proposedBreakEndTime->format('c');
				}
				if ($breaks !== null) {
					$proposedData['breaks'] = $breaks;
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

			if ($proposedData === []) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('At least one proposed change is required.'),
					'error_code' => 'proposed_change_required',
				], Http::STATUS_BAD_REQUEST);
			}

			if (!$entry->canRequestCorrection(Constants::EDIT_WINDOW_DAYS)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This time entry cannot be corrected.'),
					'error_code' => 'correction_not_allowed',
				], Http::STATUS_BAD_REQUEST);
			}

			$proposalValidationError = $this->correctionService->validateProposal($entry, $proposedData);
			if ($proposalValidationError !== null) {
				return new JSONResponse([
					'success' => false,
					'error' => $proposalValidationError
				], Http::STATUS_BAD_REQUEST);
			}

			// Update entry with correction request
			$entry->setJustification(json_encode($correctionData));
			$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
			$entry->setUpdatedAt(AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config));

			$updatedEntry = $this->timeEntryMapper->update($entry);

			// Create audit log
			$this->auditLogMapper->logAction(
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
				$this->notificationService->notifyTimeEntryCorrectionRequested(
					$userId,
					$updatedEntry->getSummary(),
					$justification
				);
			} catch (\Throwable $e) {
				// Notification failure shouldn't block the correction request
				\OCP\Log\logger('arbeitszeitcheck')->warning('Failed to send correction request notification', ['exception' => $e]);
			}

			// Auto-approve when no assignable manager exists (same rule as absences)
			if (!$this->teamResolver->hasAssignableManagerForEmployee($userId)) {
				$updatedEntry = $this->correctionService->autoApprove($updatedEntry);
				$this->auditLogMapper->logAction($userId, 'time_entry_correction_auto_approved', 'time_entry', $updatedEntry->getId(), null, ['approved_by' => 'system'], 'system');
				try {
					$this->notificationService->notifyTimeEntryCorrectionApproved($userId, $updatedEntry->getSummary());
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning('Failed to send auto-approval notification', ['exception' => $e]);
				}
				return new JSONResponse([
					'success' => true,
					'entry' => $updatedEntry->getSummary(),
					'message' => $this->l10n->t('Correction request submitted and auto-approved (no approver assigned in the app).')
				]);
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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Cancel a pending correction request (owner only).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function cancelCorrection(int $id): JSONResponse
	{
		try {
			if ($id <= 0) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid entry ID')], Http::STATUS_BAD_REQUEST);
			}
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			if ($entry->getUserId() !== $userId) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Access denied')], Http::STATUS_FORBIDDEN);
			}

			if ($entry->getStatus() !== TimeEntry::STATUS_PENDING_APPROVAL) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('No pending correction to cancel.')
				], Http::STATUS_BAD_REQUEST);
			}

			$mc = $this->assertGuardTimeEntry($entry);
			if ($mc !== null) {
				return $mc;
			}

			$justificationData = json_decode($entry->getJustification() ?? '{}', true);
			$isManualCreate = is_array($justificationData) && ($justificationData['type'] ?? '') === 'manual_create';
			$oldSummary = $entry->getSummary();

			$result = $this->correctionService->cancelByEmployee($entry);
			if ($result === null) {
				$this->projectCheckLaborSync->onTimeEntryDeleted($oldSummary, $userId);
				$this->timeEntryMapper->delete($entry);
				$this->auditLogMapper->logAction($userId, 'time_entry_correction_cancelled', 'time_entry', $id, $oldSummary, ['cascade_delete' => true]);
				return new JSONResponse(['success' => true, 'deleted' => true]);
			}

			$this->auditLogMapper->logAction($userId, 'time_entry_correction_cancelled', 'time_entry', $id, $oldSummary, $result->getSummary());

			return new JSONResponse(['success' => true, 'entry' => $result->getSummary()]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Time entry not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error cancelling correction: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete time entry endpoint
	 *
	 *
	 * @param int $id Time entry ID
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function delete(int $id): JSONResponse
	{
		try {
			if ($id <= 0) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid entry ID')], Http::STATUS_BAD_REQUEST);
			}
			$userId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($id);

			// Check ownership
			if ($entry->getUserId() !== $userId) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied')
				], Http::STATUS_FORBIDDEN);
			}

			$eligibility = $this->deletionPolicy->evaluate($entry);
			if (!$eligibility['canDelete']) {
				return new JSONResponse([
					'success' => false,
					'error' => $eligibility['blockMessage'] ?? $this->l10n->t('This time entry cannot be deleted.'),
					'error_code' => $eligibility['blockCode'],
				], $this->deleteBlockHttpStatus($eligibility['blockCode']));
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

			// Delete the entry itself
			$entryId = $entry->getId();
			$this->projectCheckLaborSync->onTimeEntryDeleted($deletedSummary ?? [], $userId);
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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Finalise a {@see TimeEntry::STATUS_PAUSED} entry in a single click.
	 *
	 * This is the safety-net endpoint for the scenario where a previous clock-out
	 * (in an older app version, on an unstable connection, or after a backend
	 * exception) left the entry in `paused` without an `end_time`. Forcing users
	 * to manually re-enter start/end times in the edit form is poor UX and a
	 * recurring support burden; this endpoint completes the entry safely with a
	 * deterministic end-time:
	 *
	 *   1. an explicit `end_time` from the request body (ISO 8601 or local time
	 *      `HH:MM`), or
	 *   2. the `updated_at` timestamp (the moment the entry was frozen — the
	 *      audit-log truth for when work stopped), or
	 *   3. the original `start_time` as a zero-duration fallback so the row at
	 *      least leaves the broken `paused` state.
	 *
	 * The new end is run through automatic break calculation (ArbZG §4) and the
	 * daily-maximum adjustment (ArbZG §3) so the resulting `completed` row is
	 * compliance-equivalent to a normal clock-out. Compliance checks, audit
	 * logging and ownership enforcement match the regular `update()` path.
	 *
	 * @param int $id Time entry ID
	 * @return JSONResponse JSON response with the finalised entry summary
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function complete(int $id): JSONResponse
	{
		try {
			if ($id <= 0) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid entry ID')], Http::STATUS_BAD_REQUEST);
			}
			$userId = $this->getUserId();

			// Parse an optional caller-supplied end time. The service applies the
			// smart fallback (updated_at -> start_time) when no override is given.
			$params = $this->request->getParams();
			$rawEnd = $params['endTime'] ?? $params['end_time'] ?? null;

			$explicitEndTime = null;
			if (is_string($rawEnd) && $rawEnd !== '') {
				try {
					if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $rawEnd)) {
						// Short HH:MM combined with today's date in the app timezone
						// keeps the JS one-click flow simple while still anchoring the
						// override on a real calendar day. The service still ensures
						// `end >= start` and applies break/daily-max logic.
						$now = new \DateTime('now', $this->getAppTimeZone());
						[$h, $m] = explode(':', $rawEnd, 2);
						$explicitEndTime = new \DateTime($now->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', (int)$h, (int)$m));
					} else {
						$explicitEndTime = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($rawEnd, $this->getAppTimeZone());
					}
				} catch (\Throwable $e) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid end time format. Use ISO 8601 (e.g. Y-m-dTH:i:s) or HH:MM.'),
					], Http::STATUS_BAD_REQUEST);
				}
			}

			$updatedEntry = $this->timeTrackingService->completePausedEntry($userId, $id, $explicitEndTime);

			return new JSONResponse([
				'success' => true,
				'entry' => $updatedEntry->getSummary(),
				'message' => $this->l10n->t('Paused session was completed and recorded successfully.'),
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found'),
			], Http::STATUS_NOT_FOUND);
		} catch (MonthFinalizedException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage(),
			], Http::STATUS_CONFLICT);
		} catch (LockedException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Another change to your time tracking is in progress. Please wait a moment and try again.'),
			], Http::STATUS_LOCKED);
		} catch (BusinessRuleException $e) {
			$message = $e->getMessage();
			$status = Http::STATUS_BAD_REQUEST;
			if (stripos($message, 'access denied') !== false) {
				$status = Http::STATUS_FORBIDDEN;
			}
			return new JSONResponse([
				'success' => false,
				'error' => $message,
			], $status);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController::complete: ' . $e->getMessage(), ['exception' => $e, 'entry_id' => $id]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get time entry statistics endpoint
	 *
	 *
	 * @param string|null $start_date Start date for statistics
	 * @param string|null $end_date End date for statistics
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getStats(?string $start_date = null, ?string $end_date = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();

			try {
				$appTz = $this->getAppTimeZone();
				$now = new \DateTime('now', $appTz);
				$start = $start_date ? $this->parseDate($start_date) : (clone $now)->modify('-30 days');
				$end = $end_date ? $this->parseDate($end_date) : clone $now;
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Invalid date in getStats: ' . $e->getMessage(), ['exception' => $e]);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date format. Expected yyyy-mm-dd')
				], Http::STATUS_BAD_REQUEST);
			}
			$start->setTime(0, 0, 0);
			// Normalise end to midnight of the requested end date.
			$end->setTime(0, 0, 0);
			if ($start > $end) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Start date cannot be after end date')
				], Http::STATUS_BAD_REQUEST);
			}
			// Exclusive upper bound for DB queries (strict < comparison in findByUserAndDateRange).
			$endExclusive = (clone $end)->modify('+1 day');

			$totalHours = $this->timeTrackingService->getWorkingHoursForPeriod($userId, $start, $endExclusive);
			$totalBreakHours = $this->timeEntryMapper->getTotalBreakHoursByUserAndDateRange($userId, $start, $endExclusive);
			$totalEntries = $this->timeEntryMapper->countByUser($userId);

			// calculateWorkingDays uses inclusive comparison ($current <= $end), so keep $end at midnight.
			$workingDays = $this->calculateWorkingDays($start, $end);
			$averageHoursPerDay = $workingDays > 0 ? $totalHours / $workingDays : 0;

			// Calculate overtime using injected OvertimeService
			$overtimeData = $this->overtimeService->calculateOvertime($userId, $start, $endExclusive);

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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
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
	 *
	 * @param string|null $start_date Start date filter (Y-m-d format)
	 * @param string|null $end_date End date filter (Y-m-d format)
	 * @param string|null $status Status filter
	 * @param int|null $limit Maximum number of entries to return (default: Constants::DEFAULT_LIST_LIMIT)
	 * @param int|null $offset Number of entries to skip for pagination (default: 0)
	 * @return JSONResponse JSON response with 'success', 'entries' array, and 'total' count
	 */
	#[NoAdminRequired]
	public function index_api(?string $start_date = null, ?string $end_date = null, ?string $status = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0): JSONResponse
	{
		return $this->index($start_date, $end_date, $status, $limit, $offset);
	}

	/**
	 * Legacy API (CamelCase alias): Nextcloud routes may call `indexApi()` when the route is defined as `index_api`.
	 */
	#[NoAdminRequired]
	public function indexApi(?string $start_date = null, ?string $end_date = null, ?string $status = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0): JSONResponse
	{
		return $this->index_api($start_date, $end_date, $status, $limit, $offset);
	}

	/**
	 * API: Get time entries (alias for index)
	 *
	 * REST API endpoint for retrieving time entries. Delegates to the index() method.
	 *
	 *
	 * @param string|null $start_date Start date filter (Y-m-d format)
	 * @param string|null $end_date End date filter (Y-m-d format)
	 * @param string|null $status Status filter
	 * @param int|null $limit Maximum number of entries to return (default: Constants::DEFAULT_LIST_LIMIT)
	 * @param int|null $offset Number of entries to skip for pagination (default: 0)
	 * @return JSONResponse JSON response with 'success', 'entries' array, and 'total' count
	 */
	#[NoAdminRequired]
	public function apiIndex(?string $start_date = null, ?string $end_date = null, ?string $status = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0): JSONResponse
	{
		return $this->index($start_date, $end_date, $status, $limit, $offset);
	}

	/**
	 * API: Get time entry by ID (alias for show)
	 *
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function apiShow(int $id): JSONResponse
	{
		return $this->show($id);
	}

	/**
	 * API: Create time entry (accepts JSON body)
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function apiStore(): JSONResponse
	{
		$params = $this->request->getParams();

		// Support both old format (date + hours) and new format (date + startTime + endTime)
		$date = $params['date'] ?? null;
		$startTime = $params['startTime'] ?? null;
		$endTime = $params['endTime'] ?? null;
		$breakStartTime = $params['breakStartTime'] ?? null;
		$breakEndTime = $params['breakEndTime'] ?? null;
		$hours = $this->parseNullableDecimal($params['hours'] ?? null);
		$description = $params['description'] ?? null;
		$project_check_project_id = $this->normalizeOptionalProjectCheckProjectId($params['project_check_project_id'] ?? $params['projectCheckProjectId'] ?? null);

		// New format: startTime and endTime
		if ($startTime && $endTime) {
			try {
				$userId = $this->getUserId();
				$blocked = $this->manualTimeEntryBlockedResponse($userId);
				if ($blocked !== null) {
					return $blocked;
				}

				// Combine date + local time for correct calendar day.
				// If $startTime / $endTime already contain a full ISO datetime, respect that.
				$baseDate = null;
				if ($date !== null && $date !== '') {
					try {
						$baseDate = $this->parseDate($date);
					} catch (\Throwable $e) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Invalid date: %s', [$date]),
						], Http::STATUS_BAD_REQUEST);
					}
				}

				$startDateTime = null;
				$endDateTime = null;

				// Helper: detect plain "HH:MM" time string
				$isPlainTime = static function (string $value): bool {
					return (bool)\preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value);
				};

				if ($baseDate instanceof \DateTime && $isPlainTime($startTime)) {
					$startDateTime = clone $baseDate;
					[$h, $m] = \explode(':', $startTime, 2);
					$startDateTime->setTime((int)$h, (int)$m, 0);
				} else {
					$startDateTime = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($startTime, $this->getAppTimeZone());
				}

				if ($baseDate instanceof \DateTime && $isPlainTime($endTime)) {
					$endDateTime = clone $baseDate;
					[$h, $m] = \explode(':', $endTime, 2);
					$endDateTime->setTime((int)$h, (int)$m, 0);
				} else {
					$endDateTime = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($endTime, $this->getAppTimeZone());
				}

				// Overnight work: if we have a base date and end < start, treat end as next day
				if ($baseDate instanceof \DateTime && $endDateTime < $startDateTime) {
					$endDateTime->modify('+1 day');
				}

				$timeEntry = new TimeEntry();
				$timeEntry->setUserId($userId);
				$timeEntry->setStartTime($startDateTime);
				$timeEntry->setEndTime($endDateTime);

				// Handle breaks: prefer breaks JSON (multiple breaks) over single break fields
				$breaksJson = $params['breaks'] ?? null;
				$breaks = $this->decodeBreaksPayload($breaksJson);
				if ($breaks !== null) {
					if (!empty($breaks)) {
						// Filter out breaks shorter than the profile floor (DE/CH 15, AT 10)
						$validBreaks = [];
						$minBreakDurationSeconds = BreakCountable::minSeconds(
							$this->countableMinBreakMinutes($userId)
						);
						foreach ($breaks as $break) {
							// Support both {start,end} and {start_time,end_time} from clients
							$startKey = isset($break['start']) ? 'start' : (isset($break['start_time']) ? 'start_time' : null);
							$endKey = isset($break['end']) ? 'end' : (isset($break['end_time']) ? 'end_time' : null);
							if ($startKey === null || $endKey === null) {
								continue;
							}

							try {
								$rawStart = (string)$break[$startKey];
								$rawEnd = (string)$break[$endKey];

								// Combine date + time if only local times were provided
								if ($baseDate instanceof \DateTime && $isPlainTime($rawStart)) {
									$breakStart = clone $baseDate;
									[$h, $m] = \explode(':', $rawStart, 2);
									$breakStart->setTime((int)$h, (int)$m, 0);
								} else {
									$breakStart = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($rawStart, $this->getAppTimeZone());
								}

								if ($baseDate instanceof \DateTime && $isPlainTime($rawEnd)) {
									$breakEnd = clone $baseDate;
									[$h, $m] = \explode(':', $rawEnd, 2);
									$breakEnd->setTime((int)$h, (int)$m, 0);
								} else {
									$breakEnd = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($rawEnd, $this->getAppTimeZone());
								}

								// Handle overnight breaks
								if ($breakEnd < $breakStart) {
									$breakEnd->modify('+1 day');
								}

								$breakDurationSeconds = $breakEnd->getTimestamp() - $breakStart->getTimestamp();

								// Only include breaks that meet the profile countable floor
								if ($breakDurationSeconds >= $minBreakDurationSeconds) {
									$validBreaks[] = [
										'start' => $breakStart->format('c'),
										'end' => $breakEnd->format('c'),
									];
								}
							} catch (\Exception $e) {
								// Skip invalid break times
								continue;
							}
						}

						if (!empty($validBreaks)) {
							$timeEntry->setBreaks(json_encode($validBreaks));
							// Clear single break fields when using breaks JSON
							$timeEntry->setBreakStartTime(null);
							$timeEntry->setBreakEndTime(null);
						} else {
							// No valid breaks, clear everything
							$timeEntry->setBreaks(null);
							$timeEntry->setBreakStartTime(null);
							$timeEntry->setBreakEndTime(null);
						}
					} else {
						// Empty breaks payload, clear everything
						$timeEntry->setBreaks(null);
						$timeEntry->setBreakStartTime(null);
						$timeEntry->setBreakEndTime(null);
					}
				} elseif ($breakStartTime && $breakEndTime) {
					// Fallback to single break fields (backward compatibility)
					$singleBreakStart = null;
					$singleBreakEnd = null;

					if ($baseDate instanceof \DateTime && $isPlainTime($breakStartTime)) {
						$singleBreakStart = clone $baseDate;
						[$h, $m] = \explode(':', $breakStartTime, 2);
						$singleBreakStart->setTime((int)$h, (int)$m, 0);
					} else {
						$singleBreakStart = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($breakStartTime, $this->getAppTimeZone());
					}

					if ($baseDate instanceof \DateTime && $isPlainTime($breakEndTime)) {
						$singleBreakEnd = clone $baseDate;
						[$h, $m] = \explode(':', $breakEndTime, 2);
						$singleBreakEnd->setTime((int)$h, (int)$m, 0);
					} else {
						$singleBreakEnd = AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($breakEndTime, $this->getAppTimeZone());
					}

					if ($singleBreakEnd < $singleBreakStart) {
						$singleBreakEnd->modify('+1 day');
					}

					$timeEntry->setBreakStartTime($singleBreakStart);
					$timeEntry->setBreakEndTime($singleBreakEnd);
					// Clear breaks JSON when using single break fields
					$timeEntry->setBreaks(null);
				}

				// Set all required fields
				$timeEntry->setDescription($description);
				if ($project_check_project_id !== null && mb_strlen($project_check_project_id) > TimeEntry::PROJECT_CHECK_PROJECT_ID_MAX_LENGTH) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Project ID must not exceed %d characters', [TimeEntry::PROJECT_CHECK_PROJECT_ID_MAX_LENGTH])
					], Http::STATUS_BAD_REQUEST);
				}
				$pcBlock = $this->assertProjectCheckAttachAllowed($userId, $project_check_project_id);
				if ($pcBlock !== null) {
					return $pcBlock;
				}
				$timeEntry->setProjectCheckProjectId($project_check_project_id);
				$manualRequiresApproval = $this->requiresManualEntryApproval();
				$justificationText = trim((string)($params['justification'] ?? ''));
				if ($manualRequiresApproval) {
					if (mb_strlen($justificationText) < 10) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('A justification of at least 10 characters is required for manual time entries.'),
							'error_code' => 'justification_too_short',
						], Http::STATUS_BAD_REQUEST);
					}
				} else {
					$justificationText = $justificationText !== '' ? $justificationText : 'Manual entry created via employee portal';
				}
				$timeEntry->setStatus($manualRequiresApproval ? TimeEntry::STATUS_PENDING_APPROVAL : TimeEntry::STATUS_COMPLETED);
				$timeEntry->setIsManualEntry(true);
				// Always set plain justification before validate(); prepareManualPending()
				// rewrites it to the JSON envelope when four-eyes approval is required.
				$timeEntry->setJustification($justificationText);
				$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
				$timeEntry->setCreatedAt($nowAt);
				$timeEntry->setUpdatedAt(clone $nowAt);

				// Check rest period compliance before saving (ArbZG §5)
				if ($timeEntry->getStartTime()) {
					$restPeriodCheck = $this->complianceService->checkRestPeriodForStartTime($userId, $timeEntry->getStartTime());
					if (!$restPeriodCheck['valid']) {
						return new JSONResponse([
							'success' => false,
							'error' => $restPeriodCheck['message']
						], Http::STATUS_BAD_REQUEST);
					}
				}

				// Adjust end time to comply with daily maximum working hours (ArbZG §3: max 10 hours per day)
				if ($timeEntry->getEndTime() && $timeEntry->getStartTime()) {
					$this->timeTrackingService->calculateAndSetAutomaticBreak($timeEntry);
					$adjusted = $this->timeTrackingService->adjustEndTimeForDailyMaximum($timeEntry);
					
					// If adjustment failed (max already reached), check if we need to reject
					if (!$adjusted && $timeEntry->getIsManualEntry()) {
						$violation = $this->timeTrackingService->findCalendarDayExceedingMaximum($timeEntry);
						if ($violation !== null) {
							$lawProfile = \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::profileForCountry(
								$this->config->getAppValue('arbeitszeitcheck', 'country', 'DE')
							);
							$maxDailyHours = max(1.0, min(24.0, (float)$this->config->getAppValue(
								'arbeitszeitcheck',
								'max_daily_hours',
								(string)$lawProfile->dailyMaxHoursDefault
							)));
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t(
									'This time entry would exceed the maximum daily working hours of %1$d hours (%2$s) on %3$s (%4$s hours on that calendar day).',
									[(int)$maxDailyHours, $lawProfile->lawLabel('daily'), $violation['date'], $violation['hours']]
								),
								'errors' => [
									'endTime' => $this->l10n->t('Maximum daily working hours already reached'),
								],
							], Http::STATUS_BAD_REQUEST);
						}
					}
				}

				// Check for overlapping entries
				if ($timeEntry->getStartTime() && $timeEntry->getEndTime()) {
					$overlapping = $this->timeEntryMapper->findOverlapping(
						$userId,
						$timeEntry->getStartTime(),
						$timeEntry->getEndTime()
					);
					
					if (!empty($overlapping)) {
						$overlapDetails = [];
						foreach ($overlapping as $overlapEntry) {
							$overlapStart = $this->displayClock($overlapEntry->getStartTime(), $userId);
							$overlapEnd = $this->displayClock($overlapEntry->getEndTime(), $userId);
							$overlapDetails[] = $overlapStart . ' - ' . $overlapEnd;
						}
						$overlapMessage = $this->l10n->t('This time entry overlaps with existing entries: %s', [implode(', ', $overlapDetails)]);
						return new JSONResponse([
							'success' => false,
							'error' => $overlapMessage
						], Http::STATUS_BAD_REQUEST);
					}
				}

				// Validate entry after all adjustments
				$timeEntry->setCountableMinBreakMinutes($this->countableMinBreakMinutes($timeEntry->getUserId()));
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

				$mc = $this->assertGuardTimeEntry($timeEntry);
				if ($mc !== null) {
					return $mc;
				}

				$complianceBlock = $this->complianceSaveBlockResponse($timeEntry);
				if ($complianceBlock !== null) {
					return $complianceBlock;
				}

				return $this->timeTrackingService->withUserMutationLock($userId, function () use (
					$userId,
					$timeEntry,
					$manualRequiresApproval,
					$justificationText
				) {
					// Re-check overlap under the same exclusive lock as clock mutations.
					if ($timeEntry->getStartTime() && $timeEntry->getEndTime()) {
						$overlapping = $this->timeEntryMapper->findOverlapping(
							$userId,
							$timeEntry->getStartTime(),
							$timeEntry->getEndTime()
						);
						if (!empty($overlapping)) {
							$overlapDetails = [];
							foreach ($overlapping as $overlapEntry) {
								$overlapStart = $this->displayClock($overlapEntry->getStartTime(), $userId);
								$overlapEnd = $this->displayClock($overlapEntry->getEndTime(), $userId);
								$overlapDetails[] = $overlapStart . ' - ' . $overlapEnd;
							}
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('This time entry overlaps with existing entries: %s', [implode(', ', $overlapDetails)]),
							], Http::STATUS_BAD_REQUEST);
						}
					}
					if ($manualRequiresApproval) {
						$this->correctionService->prepareManualPending($timeEntry, $justificationText);
					}
					$savedEntry = $this->timeEntryMapper->insert($timeEntry);
					if ($manualRequiresApproval) {
						try {
							$this->notificationService->notifyTimeEntryCorrectionRequested(
								$userId,
								$savedEntry->getSummary(),
								$justificationText
							);
						} catch (\Throwable $e) {
							\OCP\Log\logger('arbeitszeitcheck')->warning('Failed to send manual entry approval notification', ['exception' => $e]);
						}
						if (!$this->teamResolver->hasAssignableManagerForEmployee($userId)) {
							$savedEntry = $this->correctionService->autoApprove($savedEntry);
							$this->auditLogMapper->logAction($userId, 'time_entry_correction_auto_approved', 'time_entry', $savedEntry->getId(), null, ['approved_by' => 'system'], 'system');
						} else {
							$this->auditLogMapper->logAction($userId, 'time_entry_manual_create_requested', 'time_entry', $savedEntry->getId(), null, $savedEntry->getSummary());
						}
						return new JSONResponse([
							'success' => true,
							'entry' => $savedEntry->getSummary(),
							'message' => $this->l10n->t('Manual time entry submitted for manager approval.'),
						], Http::STATUS_CREATED);
					}
					$this->syncProjectCheckBillingAfterSave($savedEntry, $userId);
					if ($savedEntry->getStatus() === TimeEntry::STATUS_COMPLETED && $savedEntry->getEndTime() !== null) {
						$this->performRealTimeComplianceCheck($savedEntry);
					}
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
						\OCP\Log\logger('arbeitszeitcheck')->error('Error creating audit log for time entry apiStore: ' . $e->getMessage(), ['exception' => $e]);
					}
					return new JSONResponse([
						'success' => true,
						'entry' => $savedEntry->getSummary()
					], Http::STATUS_CREATED);
				});
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController::apiStore: ' . $e->getMessage(), ['exception' => $e]);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
				], Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		}

		// Old format: date + hours (backward compatibility)
		if (!$date || $hours === null) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Either (date and hours) or (startTime and endTime) are required')
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
	 * @param int $id Time entry ID to update
	 * @return JSONResponse JSON response with 'success' and updated 'entry' data, or 'error' on failure
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function apiUpdate(int $id): JSONResponse
	{
		$params = $this->request->getParams();
		$date = $params['date'] ?? null;
		$hours = $this->parseNullableDecimal($params['hours'] ?? null);
		$description = $params['description'] ?? null;
		$project_check_project_id = $this->normalizeOptionalProjectCheckProjectId($params['project_check_project_id'] ?? $params['projectCheckProjectId'] ?? null);

		return $this->update($id, $date, $hours, $description, $project_check_project_id);
	}

	/**
	 * API: Update time entry via POST (accepts JSON body)
	 *
	 * REST API endpoint for updating time entries via POST method. Accepts data in the request body.
	 * Supports both old format (date, hours) and new format (startTime, endTime, breakStartTime, breakEndTime).
	 * Delegates to the update() method for actual processing.
	 *
	 * @param int $id Time entry ID to update
	 * @return JSONResponse JSON response with 'success' and updated 'entry' data, or 'error' on failure
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
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
		$hours = $this->parseNullableDecimal($params['hours'] ?? null);
		$description = $params['description'] ?? null;
		$project_check_project_id = $this->normalizeOptionalProjectCheckProjectId($params['project_check_project_id'] ?? $params['projectCheckProjectId'] ?? null);

		return $this->update($id, $date, $hours, $description, $project_check_project_id);
	}

	/**
	 * API: Delete time entry (alias for delete)
	 *
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
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
	 *
	 * @param string|null $period Period type: 'daily', 'weekly', 'monthly', 'yearly', or 'custom' (default: 'monthly')
	 * @param string|null $start_date Start date for custom period (Y-m-d format, required if period is 'custom')
	 * @param string|null $end_date End date for custom period (Y-m-d format, required if period is 'custom')
	 * @return JSONResponse JSON response with 'success', 'overtime' hours, and 'period' information
	 */
	#[NoAdminRequired]
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
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get overtime balance (cumulative)
	 *
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getOvertimeBalance(): JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$balance = $this->overtimeService->getOvertimeBalance($userId);
			$bank = $this->overtimeBankService->getBankStatus($userId);

			return new JSONResponse([
				'success' => true,
				'balance' => $balance,
				'effective_balance' => $bank['effective_balance'],
				'bank' => $bank,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function getOvertimeBank(): JSONResponse
	{
		try {
			$userId = $this->getUserId();

			return new JSONResponse([
				'success' => true,
				'bank' => $this->overtimeBankService->getBankStatus($userId),
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in TimeEntryController::getOvertimeBank: ' . $e->getMessage(), ['exception' => $e]);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
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
		try {
			$start = $this->parseDate($start_date);
			$end = $this->parseDate($end_date);
		} catch (\Throwable $e) {
			throw new \Exception($this->l10n->t('Invalid date format. Expected yyyy-mm-dd'));
		}
		$start->setTime(0, 0, 0);
		$end->setTime(0, 0, 0);
		if ($start > $end) {
			throw new \Exception($this->l10n->t('Start date cannot be after end date'));
		}
		$endExclusive = (clone $end)->modify('+1 day');
		return $overtimeService->calculateOvertime($userId, $start, $endExclusive);
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
	/**
	 * Check whether a proposed time window overlaps with existing entries.
	 *
	 * Used by the frontend for real-time overlap warnings before the user saves.
	 * Always checks against the authenticated user's own entries regardless of the
	 * optional `userId` query parameter that legacy JS callers may send.
	 *
	 * @param string $startTime ISO-8601 start time
	 * @param string $endTime ISO-8601 end time
	 * @param int|null $excludeEntryId Entry ID to exclude (for edit flows)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function checkOverlap(string $startTime, string $endTime, ?int $excludeEntryId = null): JSONResponse
	{
		try {
			$userId = $this->getUserId();

			$appTz = $this->getAppTimeZone();
			$parse = function (string $ts) use ($appTz): ?\DateTime {
				$dt = \DateTime::createFromFormat(\DateTime::ATOM, $ts)
					?: \DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $ts)
					?: \DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $ts)
					?: \DateTime::createFromFormat('Y-m-d\TH:i:sP', $ts);
				if ($dt instanceof \DateTime) {
					return $dt;
				}
				try {
					return AppLocalNaiveDateTimeNormalizer::parseFlexibleDateTime($ts, $appTz);
				} catch (\Throwable $e) {
					return null;
				}
			};

			$startDt = $parse($startTime);
			$endDt = $parse($endTime);

			if ($startDt === null || $startDt === false) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid start time format.')
				], Http::STATUS_BAD_REQUEST);
			}
			if ($endDt === null || $endDt === false) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid end time format.')
				], Http::STATUS_BAD_REQUEST);
			}
			if ($startDt >= $endDt) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Start time must be before end time.')
				], Http::STATUS_BAD_REQUEST);
			}

			$overlapping = $this->timeEntryMapper->findOverlapping($userId, $startDt, $endDt, $excludeEntryId);

			$entries = [];
			foreach ($overlapping as $entry) {
				$entries[] = [
					'id' => $entry->getId(),
					'startTime' => $entry->getStartTime() ? $entry->getStartTime()->format(\DateTime::ATOM) : null,
					'endTime' => $entry->getEndTime() ? $entry->getEndTime()->format(\DateTime::ATOM) : null,
				];
			}

			return new JSONResponse([
				'success' => true,
				'hasOverlap' => !empty($overlapping),
				'entries' => $entries,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('checkOverlap error: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Server-side ArbZG gate before persisting a completed manual/API entry.
	 */
	private function complianceSaveBlockResponse(TimeEntry $timeEntry): ?JSONResponse
	{
		if ($timeEntry->getStatus() !== TimeEntry::STATUS_COMPLETED || $timeEntry->getEndTime() === null) {
			return null;
		}

		$strictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
		$issues = $this->complianceService->blockingIssuesForCompletedEntry($timeEntry);

		if ($strictMode) {
			try {
				$this->complianceService->checkComplianceForCompletedEntry($timeEntry, true, false);
			} catch (\Exception $e) {
				$msg = trim($e->getMessage());
				if ($msg !== '' && !\in_array($msg, $issues, true)) {
					$issues[] = $msg;
				}
			}
		}

		if ($issues === []) {
			return null;
		}

		return new JSONResponse([
			'success' => false,
			'error' => $issues[0],
			'error_code' => 'compliance_blocked',
		], Http::STATUS_BAD_REQUEST);
	}

	private function performRealTimeComplianceCheck(TimeEntry $timeEntry): void
	{
		// Check if real-time compliance checking is enabled
		$realTimeComplianceEnabled = $this->config->getAppValue('arbeitszeitcheck', 'realtime_compliance_check', '1') === '1';
		if (!$realTimeComplianceEnabled) {
			return; // Real-time checking disabled, rely on daily batch job
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
