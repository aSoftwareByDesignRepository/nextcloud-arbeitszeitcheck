<?php

declare(strict_types=1);

/**
 * Admin controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\AuditLog;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Db\Team;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleModule;
use OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSet;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\InvalidEmployeeListFilterException;
use OCA\ArbeitszeitCheck\Service\AdminEmployeeDirectoryService;
use OCA\ArbeitszeitCheck\Service\AdminUserProfileUpdateService;
use OCA\ArbeitszeitCheck\Service\AuditLogPresenter;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Service\HolidayAdminService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\LayeredVacationConflictException;
use OCA\ArbeitszeitCheck\Service\LayeredVacationDefaultsService;
use OCA\ArbeitszeitCheck\Service\LayeredVacationNotFoundException;
use OCA\ArbeitszeitCheck\Service\LayeredVacationValidationException;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine;
use OCA\ArbeitszeitCheck\Service\VacationProrationService;
use OCA\ArbeitszeitCheck\Service\VacationYearWindowResolver;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCA\ArbeitszeitCheck\Support\HolidayCatalogResolver;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\OpeningBalanceYearValidator;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCA\ArbeitszeitCheck\Support\SchemaHealth;
use OCA\ArbeitszeitCheck\Support\StrictYmdDates;
use OCA\ArbeitszeitCheck\Support\TariffRuleModuleValidator;
use OCA\ArbeitszeitCheck\Support\UserDirectorySearch;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\Exception as DBException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\Util;

/**
 * Admin controller – all routes require ArbeitszeitCheck app-admin privileges.
 *
 * Every public action MUST carry #[NoAdminRequired] so Nextcloud's
 * SecurityMiddleware does not demand a system admin. AppAdminMiddleware
 * then enforces PermissionService::isAdmin() (system admin OR delegated
 * app administrator from app_admin_user_ids).
 */
class AdminController extends Controller
{
	use CSPTrait;
	use PageShellTrait;
	use TTransactional;
	private const MAX_NOTIFICATION_RECIPIENTS = 20;
	private const MAX_NOTIFICATION_RECIPIENT_LENGTH = 254;
	private const MAX_NOTIFICATION_RECIPIENTS_RAW_LENGTH = 4000;

	/** Hard cap on candidate users scanned by getDashboardEmployees to bound memory/CPU. */
	private const DASHBOARD_EMPLOYEES_MAX_SCAN = 5000;

	/** Maximum length of the search string for getDashboardEmployees. */
	private const DASHBOARD_EMPLOYEES_MAX_SEARCH_LENGTH = 200;

	/** Max date range for admin exports (prevents heavy queries / DoS) */
	private TimeEntryMapper $timeEntryMapper;
	private ComplianceViolationMapper $violationMapper;
	private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
	private WorkingTimeModelMapper $workingTimeModelMapper;
	private AuditLogMapper $auditLogMapper;
	private IUserManager $userManager;
	private IAppConfig $appConfig;
	protected IL10N $l10n;
	private UserSettingsMapper $userSettingsMapper;
	private TeamMapper $teamMapper;
	private TeamMemberMapper $teamMemberMapper;
	private TeamManagerMapper $teamManagerMapper;
	private IGroupManager $groupManager;
	private IAppManager $appManager;
	protected IUserSession $userSession;
	protected IURLGenerator $urlGenerator;
	protected PermissionService $permissionService;
	protected LocaleFormatService $localeFormat;
	private HolidayMapper $holidayMapper;
	private HolidayService $holidayCalendarService;
	private HolidayAdminService $holidayAdminService;
	private VacationYearBalanceMapper $vacationYearBalanceMapper;
	private VacationAllocationService $vacationAllocationService;
	private TariffRuleSetMapper $tariffRuleSetMapper;
	private TariffRuleModuleMapper $tariffRuleModuleMapper;
	private UserVacationPolicyAssignmentMapper $userVacationPolicyAssignmentMapper;
	private VacationEntitlementEngine $vacationEntitlementEngine;
	private LayeredVacationDefaultsService $layeredVacationDefaultsService;
	private UserOvertimeSettingsService $userOvertimeSettingsService;
	private UserEmploymentSettingsService $userEmploymentSettingsService;
	private VacationProrationService $vacationProrationService;
	private TimeCaptureMethodService $timeCaptureMethodService;
	private AdminUserProfileUpdateService $adminUserProfileUpdateService;
	private AdminEmployeeDirectoryService $adminEmployeeDirectoryService;
	private AuditLogPresenter $auditLogPresenter;
	private IDBConnection $db;
	private ?LaborLawProfileFactory $laborLawProfileFactory;
	private ?\OCP\IConfig $config;
	private ?\OCP\Lock\ILockingProvider $lockingProvider;
	private ?\OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService $vacationUnitMigrationService;
	private ?\OCA\ArbeitszeitCheck\Service\AdminBatchMutationService $adminBatchMutationService;

	private const AUDIT_LOG_PAGE_SIZE = 50;

	public function __construct(
		string $appName,
		IRequest $request,
		TimeEntryMapper $timeEntryMapper,
		ComplianceViolationMapper $violationMapper,
		UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		WorkingTimeModelMapper $workingTimeModelMapper,
		AuditLogMapper $auditLogMapper,
		IUserManager $userManager,
		IAppConfig $appConfig,
		UserSettingsMapper $userSettingsMapper,
		TeamMapper $teamMapper,
		TeamMemberMapper $teamMemberMapper,
		TeamManagerMapper $teamManagerMapper,
		IGroupManager $groupManager,
		IAppManager $appManager,
		IUserSession $userSession,
		CSPService $cspService,
		IL10N $l10n,
		IURLGenerator $urlGenerator,
		HolidayMapper $holidayMapper,
		HolidayService $holidayCalendarService,
		HolidayAdminService $holidayAdminService,
		VacationYearBalanceMapper $vacationYearBalanceMapper,
		VacationAllocationService $vacationAllocationService,
		TariffRuleSetMapper $tariffRuleSetMapper,
		TariffRuleModuleMapper $tariffRuleModuleMapper,
		UserVacationPolicyAssignmentMapper $userVacationPolicyAssignmentMapper,
		VacationEntitlementEngine $vacationEntitlementEngine,
		LayeredVacationDefaultsService $layeredVacationDefaultsService,
		UserOvertimeSettingsService $userOvertimeSettingsService,
		UserEmploymentSettingsService $userEmploymentSettingsService,
		VacationProrationService $vacationProrationService,
		TimeCaptureMethodService $timeCaptureMethodService,
		AdminUserProfileUpdateService $adminUserProfileUpdateService,
		AdminEmployeeDirectoryService $adminEmployeeDirectoryService,
		AuditLogPresenter $auditLogPresenter,
		PermissionService $permissionService,
		LocaleFormatService $localeFormat,
		IDBConnection $db,
		?LaborLawProfileFactory $laborLawProfileFactory = null,
		?\OCP\IConfig $config = null,
		?\OCP\Lock\ILockingProvider $lockingProvider = null,
		?\OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService $vacationUnitMigrationService = null,
		?\OCA\ArbeitszeitCheck\Service\AdminBatchMutationService $adminBatchMutationService = null,
	) {
		parent::__construct($appName, $request);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->violationMapper = $violationMapper;
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->workingTimeModelMapper = $workingTimeModelMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->userManager = $userManager;
		$this->appConfig = $appConfig;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->teamMapper = $teamMapper;
		$this->teamMemberMapper = $teamMemberMapper;
		$this->teamManagerMapper = $teamManagerMapper;
		$this->groupManager = $groupManager;
		$this->appManager = $appManager;
		$this->userSession = $userSession;
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->holidayMapper = $holidayMapper;
		$this->holidayCalendarService = $holidayCalendarService;
		$this->holidayAdminService = $holidayAdminService;
		$this->vacationYearBalanceMapper = $vacationYearBalanceMapper;
		$this->vacationAllocationService = $vacationAllocationService;
		$this->tariffRuleSetMapper = $tariffRuleSetMapper;
		$this->tariffRuleModuleMapper = $tariffRuleModuleMapper;
		$this->userVacationPolicyAssignmentMapper = $userVacationPolicyAssignmentMapper;
		$this->vacationEntitlementEngine = $vacationEntitlementEngine;
		$this->layeredVacationDefaultsService = $layeredVacationDefaultsService;
		$this->userOvertimeSettingsService = $userOvertimeSettingsService;
		$this->userEmploymentSettingsService = $userEmploymentSettingsService;
		$this->vacationProrationService = $vacationProrationService;
		$this->timeCaptureMethodService = $timeCaptureMethodService;
		$this->adminUserProfileUpdateService = $adminUserProfileUpdateService;
		$this->adminEmployeeDirectoryService = $adminEmployeeDirectoryService;
		$this->auditLogPresenter = $auditLogPresenter;
		$this->permissionService = $permissionService;
		$this->localeFormat = $localeFormat;
		$this->db = $db;
		$this->laborLawProfileFactory = $laborLawProfileFactory;
		$this->config = $config;
		$this->lockingProvider = $lockingProvider;
		$this->vacationUnitMigrationService = $vacationUnitMigrationService;
		$this->adminBatchMutationService = $adminBatchMutationService;
		$this->setCspService($cspService);
	}

	private function batchMutationService(): \OCA\ArbeitszeitCheck\Service\AdminBatchMutationService
	{
		if ($this->adminBatchMutationService === null) {
			$this->adminBatchMutationService = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\AdminBatchMutationService::class);
		}
		return $this->adminBatchMutationService;
	}

	/**
	 * @return array{showSubstitutionLink: bool, showManagerLink: bool, showReportsLink: bool, showAdminNav: bool}
	 */
	private function buildAdminNavFlags(): array
	{
		return [
			'showSubstitutionLink' => false,
			'showManagerLink' => true,
			'showReportsLink' => true,
			'showAdminNav' => true,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildAdminShellParams(string $pageId, string $title, string $help): array
	{
		return $this->buildShellParams($pageId, $title, $help, $this->buildAdminNavFlags(), $this->l10n->t('Administration')) + [
			'schema_health' => SchemaHealth::assess($this->db),
		];
	}

	/**
	 * Get the current admin's user ID for audit logging (performedBy).
	 * Falls back to 'system' if session is unavailable (e.g. CLI).
	 */
	private function getPerformedBy(): string
	{
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : 'system';
	}

	/**
	 * ProjectCheck enabled on this Nextcloud — not "enabled for the current admin".
	 */
	private function isProjectCheckInstalledOnInstance(): bool
	{
		return $this->appManager->isInstalled(Constants::APP_ID_PROJECTCHECK) === true;
	}

	/**
	 * Nextcloud Apps page so admins can enable ProjectCheck when it is missing.
	 */
	private function projectCheckAppsSettingsUrl(): string
	{
		try {
			return $this->urlGenerator->linkToRoute('settings.AppSettings.viewApps');
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * Build a JSON-serializable audit snapshot of a TariffRuleSet so that
	 * mutations (create/update/activate/retire/delete) leave a deterministic
	 * trail for compliance reviewers.
	 *
	 * @return array<string,mixed>
	 */
	private function tariffRuleSetToAuditValues(TariffRuleSet $ruleSet): array
	{
		$validFrom = $ruleSet->getValidFrom();
		$validTo = $ruleSet->getValidTo();
		$updatedAt = $ruleSet->getUpdatedAt();
		return [
			'id' => $ruleSet->getId(),
			'tariffCode' => $ruleSet->getTariffCode(),
			'version' => $ruleSet->getVersion(),
			'jurisdiction' => $ruleSet->getJurisdiction(),
			'status' => $ruleSet->getStatus(),
			'activationMode' => $ruleSet->getActivationMode(),
			'validFrom' => $validFrom ? $validFrom->format('Y-m-d') : null,
			'validTo' => $validTo ? $validTo->format('Y-m-d') : null,
			'updatedAt' => $updatedAt ? $updatedAt->format('c') : null,
		];
	}

	/**
	 * Convert UserWorkingTimeModel to JSON-serializable array for audit log.
	 */
	private function userWorkingTimeModelToAuditValues(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel $model): array
	{
		$start = $model->getStartDate();
		$end = $model->getEndDate();
		return [
			'id' => $model->getId(),
			'userId' => $model->getUserId(),
			'workingTimeModelId' => $model->getWorkingTimeModelId(),
			'vacationDaysPerYear' => $model->getVacationDaysPerYear(),
			'startDate' => $start ? $start->format('Y-m-d') : null,
			'endDate' => $end ? $end->format('Y-m-d') : null,
		];
	}

	/**
	 * Convert WorkingTimeModel to JSON-serializable array for audit log.
	 */
	private function workingTimeModelToAuditValues(\OCA\ArbeitszeitCheck\Db\WorkingTimeModel $model): array
	{
		$created = $model->getCreatedAt();
		$updated = $model->getUpdatedAt();
		return [
			'id' => $model->getId(),
			'name' => $model->getName(),
			'description' => $model->getDescription(),
			'type' => $model->getType(),
			'weeklyHours' => $model->getWeeklyHours(),
			'dailyHours' => $model->getDailyHours(),
			'workDaysPerWeek' => $model->getWorkDaysPerWeek(),
			'breakRules' => $model->getBreakRulesArray(),
			'overtimeRules' => $model->getOvertimeRulesArray(),
			'isDefault' => $model->getIsDefault(),
			'createdAt' => $created ? $created->format('c') : null,
			'updatedAt' => $updated ? $updated->format('c') : null,
		];
	}

	/**
	 * Convert Holiday entity to JSON-serializable array for audit log.
	 */
	private function holidayToAuditValues(Holiday $holiday): array
	{
		$date = $holiday->getDate();

		return [
			'id' => $holiday->getId(),
			'state' => $holiday->getState(),
			'date' => $date ? $date->format('Y-m-d') : null,
			'name' => $holiday->getName(),
			'kind' => $holiday->getKind(),
			'scope' => $holiday->getScope(),
			'source' => $holiday->getSource(),
		];
	}

	/**
	 * Normalize working time model type from API (accepts full-time/part-time for backward compat).
	 */
	private function normalizeWorkingTimeModelType(string $type): string
	{
		$type = trim($type);
		if ($type === '') {
			return \OCA\ArbeitszeitCheck\Db\WorkingTimeModel::TYPE_FULL_TIME;
		}
		$hyphenToUnderscore = ['full-time' => 'full_time', 'part-time' => 'part_time'];
		return $hyphenToUnderscore[$type] ?? $type;
	}

	private function parseDecimalInput(mixed $value, float $default): float
	{
		if ($value === null || $value === '') {
			return $default;
		}
		if (is_int($value) || is_float($value)) {
			return (float)$value;
		}
		$normalized = str_replace(',', '.', trim((string)$value));
		$normalized = preg_replace('/\s+/', '', $normalized ?? '');
		if ($normalized === null || $normalized === '' || !is_numeric($normalized)) {
			return $default;
		}
		return (float)$normalized;
	}

	/**
	 * Apply break_rules; validate weekday_schedule and sync weekly/daily hours when present.
	 *
	 * @param array<string, mixed> $breakRules
	 * @return JSONResponse|null error response
	 */
	private function applyBreakRulesToWorkingTimeModel(
		\OCA\ArbeitszeitCheck\Db\WorkingTimeModel $model,
		array $breakRules,
	): ?JSONResponse {
		// List-encoded payloads (e.g. JS `[]` fallback) corrupt weekday_schedule
		// storage — reject so clients must send a map or omit the field.
		if ($breakRules !== [] && array_is_list($breakRules)) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Validation failed'),
				'errors' => ['breakRules' => 'SCHEDULE_INVALID'],
				'code' => 'SCHEDULE_INVALID',
			], Http::STATUS_BAD_REQUEST);
		}

		$scheduleRaw = $breakRules[WeekdaySchedule::KEY] ?? null;
		if ($scheduleRaw !== null) {
			if (!is_array($scheduleRaw)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Validation failed'),
					'errors' => ['breakRules' => 'SCHEDULE_INVALID'],
					'code' => 'SCHEDULE_INVALID',
				], Http::STATUS_BAD_REQUEST);
			}
			$errors = WeekdaySchedule::validate($scheduleRaw);
			if ($errors !== []) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Validation failed'),
					'errors' => ['breakRules' => $errors[0]],
					'codes' => $errors,
					'code' => $errors[0],
				], Http::STATUS_BAD_REQUEST);
			}
			$schedule = WeekdaySchedule::fromValidated($scheduleRaw);
			$breakRules[WeekdaySchedule::KEY] = $schedule->toArray();
			$model->setBreakRulesArray($breakRules);
			$model->setWeeklyHours($schedule->weeklyNetHours());
			$model->setDailyHours($schedule->averageDailyNetHours());
			$model->setWorkDaysPerWeek($schedule->workDaysPerWeek());
			return null;
		}

		$model->setBreakRulesArray($breakRules);
		return null;
	}

	/**
	 * Strict calendar day for admin vacation APIs (`YYYY-MM-DD` only — rejects
	 * timestamps and locale-formatted dates so parsing cannot throw or drift).
	 *
	 * @return array{\DateTime, null}|array{null, JSONResponse} Tuple of `[date, null]` or `[null, error]`.
	 */
	private function parseStrictYmdDateParam(string $raw): array
	{
		$parsed = StrictYmdDates::parseRequired($raw);
		if ($parsed === null) {
			return [null, new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Invalid date; use YYYY-MM-DD.'),
			], Http::STATUS_BAD_REQUEST)];
		}

		return [$parsed, null];
	}

	/**
	 * Optional end-of-range date: empty input yields `null` without error.
	 *
	 * @return array{\DateTime|null, null}|array{null, JSONResponse}
	 */
	private function parseOptionalEffectiveToParam(mixed $raw): array
	{
		if ($raw === null || $raw === '') {
			return [null, null];
		}
		if (!is_scalar($raw)) {
			return [null, new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Invalid date; use YYYY-MM-DD.'),
			], Http::STATUS_BAD_REQUEST)];
		}
		$trimmed = trim((string)$raw);
		if ($trimmed === '') {
			return [null, null];
		}

		return $this->parseStrictYmdDateParam($trimmed);
	}

	/**
	 * Optional calendar day as {@see \DateTimeImmutable} at midnight.
	 *
	 * @return array{\DateTimeImmutable|null, null}|array{null, JSONResponse}
	 */
	private function parseOptionalImmutableYmdParam(mixed $raw): array
	{
		[$dt, $err] = $this->parseOptionalEffectiveToParam($raw);
		if ($err !== null) {
			return [null, $err];
		}
		if ($dt === null) {
			return [null, null];
		}

		return [\DateTimeImmutable::createFromMutable($dt)->setTime(0, 0, 0), null];
	}

	/**
	 * Employment period for entitlement simulation: draft values from the
	 * request override stored settings when an `employment` object is present
	 * (admin user-edit preview). Absent keys inside that object fall back to
	 * the persisted value so partial payloads remain safe.
	 *
	 * @param array<string, mixed> $params
	 * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?JSONResponse}
	 */
	private function resolveSimulateEmploymentDates(string $userId, array $params): array
	{
		$employment = $params['employment'] ?? null;
		if (!is_array($employment)) {
			return [
				$this->userEmploymentSettingsService->getEmploymentStart($userId),
				$this->userEmploymentSettingsService->getEmploymentEnd($userId),
				null,
			];
		}

		if (array_key_exists('start', $employment)) {
			[$start, $err] = $this->parseOptionalImmutableYmdParam($employment['start']);
			if ($err !== null) {
				return [null, null, $err];
			}
		} else {
			$start = $this->userEmploymentSettingsService->getEmploymentStart($userId);
		}

		if (array_key_exists('end', $employment)) {
			[$end, $err] = $this->parseOptionalImmutableYmdParam($employment['end']);
			if ($err !== null) {
				return [null, null, $err];
			}
		} else {
			$end = $this->userEmploymentSettingsService->getEmploymentEnd($userId);
		}

		if ($start !== null && $end !== null && $start > $end) {
			return [null, null, new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Employment start date must be on or before the employment end date.'),
				'errors' => ['employment' => [
					'end' => $this->l10n->t('The employment end date must be on or after the employment start date.'),
				]],
			], Http::STATUS_BAD_REQUEST)];
		}

		return [$start, $end, null];
	}

	private function profileUpdateExceptionResponse(AdminUserProfileUpdateException $e): JSONResponse
	{
		$payload = [
			'success' => false,
			'error' => $e->userMessage,
		];
		if ($e->fieldErrors !== []) {
			$payload['errors'] = $e->fieldErrors;
		}

		return new JSONResponse($payload, $e->httpStatus);
	}

	/**
	 * Vacation policy row shown in the employee edit dialog. For assignments that
	 * start in the future, lookup must use that start date — not only "today" —
	 * or the dialog mis-defaults to "inherit" and the next save fails validation
	 * or overwrites the intended individual rule.
	 */
	private function findVacationPolicyForAdminEdit(string $userId, ?\DateTime $assignmentStart): ?UserVacationPolicyAssignment
	{
		$asOf = new \DateTimeImmutable('today');
		if ($assignmentStart !== null) {
			$start = \DateTimeImmutable::createFromMutable($assignmentStart)->setTime(0, 0, 0);
			if ($start > $asOf) {
				$asOf = $start;
			}
		}

		return $this->userVacationPolicyAssignmentMapper->findCurrentByUser($userId, $asOf);
	}

	/**
	 * Atomically update all employee profile sections (work schedule, vacation
	 * policy, time capture, overtime) in a single DB transaction.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function updateUserProfile(string $userId): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$payload = [
				'workingTimeModel' => is_array($params['workingTimeModel'] ?? null) ? $params['workingTimeModel'] : [],
				'vacationPolicy' => is_array($params['vacationPolicy'] ?? null) ? $params['vacationPolicy'] : [],
				'timeCapture' => is_array($params['timeCapture'] ?? null) ? $params['timeCapture'] : [],
				'overtime' => is_array($params['overtime'] ?? null) ? $params['overtime'] : [],
				'employment' => is_array($params['employment'] ?? null) ? $params['employment'] : [],
			];
			$result = $this->adminUserProfileUpdateService->updateProfile(
				$userId,
				$payload,
				$this->getPerformedBy()
			);

			return new JSONResponse($result);
		} catch (AdminUserProfileUpdateException $e) {
			return $this->profileUpdateExceptionResponse($e);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('updateUserProfile failed', ['exception' => $e]);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function resolveActivationStartDate(string $activationMode, \DateTimeImmutable $today): \DateTimeImmutable
	{
		return match ($activationMode) {
			'next_month' => new \DateTimeImmutable($today->format('Y-m-01') . ' +1 month'),
			'next_year' => new \DateTimeImmutable(((int)$today->format('Y') + 1) . '-01-01'),
			default => $today,
		};
	}

	/**
	 * Admin dashboard page (gated by AppAdminMiddleware / PermissionService::isAdmin)
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		$this->registerFrontEndAssets('admin-dashboard', 'admin-dashboard');

		try {
			// Get statistics
			$totalUsers = $this->userManager->countUsersTotal(0, false);
			if ($totalUsers === false) {
				$totalUsers = 0;
			}

			$today = new \DateTime();
			$activeUsersToday = $this->timeEntryMapper->countDistinctUsersByDate($today);
			$unresolvedCount = $this->violationMapper->count(['resolved' => false]);

			// Get recent violations
			$recentViolations = $this->violationMapper->findUnresolved(10);
			$violationsData = [];
			foreach ($recentViolations as $violation) {
				$user = $this->userManager->get($violation->getUserId());
				$violationsData[] = [
					'id' => $violation->getId(),
					'userId' => $violation->getUserId(),
					'userDisplayName' => $user ? $user->getDisplayName() : $violation->getUserId(),
					'type' => $violation->getViolationType(),
					'severity' => $violation->getSeverity(),
					'date' => $violation->getDate() ? $violation->getDate()->format('Y-m-d') : null,
					'resolved' => $violation->getResolved()
				];
			}

			$withOvertimeTracking = $this->userOvertimeSettingsService->countUsersWithTrackingFrom();
			$withoutOvertimeTracking = max(0, $totalUsers - $withOvertimeTracking);

			$overtimePolicy = [
				'traffic_light_enabled' => $this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_TRAFFIC_LIGHT_ENABLED, '0') === '1',
				'bank_enabled' => $this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_BANK_ENABLED, '0') === '1',
				'bank_max_hours' => (float)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_BANK_MAX_HOURS, '100'),
				'bank_yellow_percent' => (int)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT, '80'),
				'bank_red_percent' => (int)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_BANK_RED_PERCENT, '95'),
				'block_month_closure_pending_payout' => $this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_BLOCK_MONTH_CLOSURE_PENDING_PAYOUT, '0') === '1',
				'threshold_yellow_over' => (float)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_YELLOW_OVER, '20'),
				'threshold_red_over' => (float)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_RED_OVER, '40'),
				'threshold_yellow_under' => (float)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_YELLOW_UNDER, '-20'),
				'threshold_red_under' => (float)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_RED_UNDER, '-40'),
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'admin-dashboard', $this->buildAdminShellParams(
				'admin-dashboard',
				$this->l10n->t('Administration - Status'),
				$this->l10n->t('Current key metrics and open working-time compliance issues. Detailed settings are available in the left navigation.'),
			) + [
				'statistics' => [
					'total_users' => $totalUsers,
					'active_users_today' => $activeUsersToday,
					'unresolved_violations' => $unresolvedCount
				],
				'overtime_onboarding' => [
					'show_banner' => $totalUsers > 0 && $withoutOvertimeTracking > 0,
					'without_tracking' => $withoutOvertimeTracking,
					'with_tracking' => $withOvertimeTracking,
					'total_users' => $totalUsers,
				],
				'overtime_policy' => $overtimePolicy,
				'recent_violations' => $violationsData,
			]);
			return $this->configureCSP($response, 'admin');
		} catch (\Throwable $e) {
			$response = new TemplateResponse('arbeitszeitcheck', 'admin-dashboard', $this->buildAdminShellParams(
				'admin-dashboard',
				$this->l10n->t('Administration - Status'),
				$this->l10n->t('Current key metrics and open working-time compliance issues. Detailed settings are available in the left navigation.'),
			) + [
				'statistics' => [
					'total_users' => 0,
					'active_users_today' => 0,
					'unresolved_violations' => 0
				],
				'recent_violations' => [],
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			]);
			return $this->configureCSP($response, 'admin');
		}
	}

	/**
	 * Admin users management page (admin-only by default)
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function users(): TemplateResponse
	{
		$this->registerFrontEndAssets('admin-users', 'admin-users', [], ['common/datepicker']);

		$pageSize = 50;
		$listResult = $this->adminEmployeeDirectoryService->listUsers(
			null,
			'',
			$pageSize,
			0,
			false,
		);

		$usersData = [];
		foreach ($listResult['users'] as $user) {
			$usersData[] = $this->buildAdminUserSsrPayload($user);
		}

		$totalCount = $listResult['total'];
		$accessFilter = $listResult['filter'];
		$defaultFilter = $listResult['defaultFilter'];
		$hiddenCount = $listResult['hiddenCount'] ?? 0;
		$isAccessRestricted = $this->permissionService->isAccessRestrictionEnabled();

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-users', array_merge(
			$this->buildAdminShellParams(
				'admin-users',
				$this->l10n->t('Employees'),
				$this->l10n->t('Manage employees and working time models'),
			),
			[
				'users' => $usersData,
				'total' => $totalCount,
				'accessFilter' => $accessFilter,
				'defaultFilter' => $defaultFilter,
				'hiddenCount' => $hiddenCount,
				'isAccessRestricted' => $isAccessRestricted,
				'truncated' => $listResult['truncated'],
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
				'organizationTimeCapture' => $this->timeCaptureMethodService->getOrganizationDefaults(),
				'holidayRegionContext' => [
					'country' => $this->getConfiguredCountry(),
					'defaultRegion' => $this->getConfiguredDefaultRegion(),
					'vacationDaysSuggestion' => LaborLawProfileFactory::profileForCountry($this->getConfiguredCountry())->vacationDaysSuggestion,
				],
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Dedicated employee profile page (replaces the former edit-user modal).
	 *
	 * Security: AppAdminMiddleware gates all AdminController methods. The
	 * userId path segment is resolved via the Nextcloud user manager; unknown
	 * accounts render a clear not-found state (no data leakage).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function userDetail(string $userId): TemplateResponse
	{
		$this->registerFrontEndAssets('admin-user-detail', 'admin-users', [], ['common/datepicker']);

		$userId = trim($userId);
		$user = $userId !== '' ? $this->userManager->get($userId) : null;
		$found = $user !== null;
		$displayName = $found ? (string)$user->getDisplayName() : $userId;
		$pageTitle = $found
			? $displayName
			: $this->l10n->t('Employee not found');
		$pageHelp = $found
			? $this->l10n->t('Review and update this employee’s work schedule, vacation, overtime, and time recording settings.')
			: $this->l10n->t('The requested employee account could not be found.');

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-user-detail', array_merge(
			$this->buildAdminShellParams(
				'admin-user-detail',
				$pageTitle,
				$pageHelp,
			),
			[
				'detailUserId' => $userId,
				'detailUserFound' => $found,
				'detailDisplayName' => $displayName,
				'detailEmail' => $found ? ($user->getEMailAddress() ?? '') : '',
				'detailEnabled' => $found ? $user->isEnabled() : false,
				'employeesListUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.users'),
				// Deep-link into Kiosk → Badges & PIN with this employee pre-selected.
				'kioskCredentialsUrl' => $found
					? $this->urlGenerator->linkToRoute(
						'arbeitszeitcheck.kiosk_admin.index',
						['user' => $userId],
					) . '#azc-kiosk-creds-heading'
					: $this->urlGenerator->linkToRoute('arbeitszeitcheck.kiosk_admin.index'),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
				'organizationTimeCapture' => $this->timeCaptureMethodService->getOrganizationDefaults(),
				'holidayRegionContext' => [
					'country' => $this->getConfiguredCountry(),
					'defaultRegion' => $this->getConfiguredDefaultRegion(),
					'vacationDaysSuggestion' => LaborLawProfileFactory::profileForCountry($this->getConfiguredCountry())->vacationDaysSuggestion,
				],
			],
		));
		if (!$found) {
			$response->setStatus(Http::STATUS_NOT_FOUND);
		}
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Admin settings page (admin-only by default)
	 *
	 */
	/**
	 * Global settings index → default section (SETTINGS-PAGES-STANDARD).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): RedirectResponse
	{
		$catalog = new \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog();
		return new RedirectResponse(
			$catalog->url($this->urlGenerator, \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::DEFAULT_SECTION)
		);
	}

	/**
	 * Global settings section page (`/admin/settings/{section}`).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsSection(string $section): Response
	{
		$catalog = new \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog();
		if (!$catalog->isSection($section)) {
			return new NotFoundResponse();
		}

		$this->registerFrontEndAssets(
			'admin-settings',
			'admin-settings',
			['common/projectcheck', 'common/country-region'],
			['common/admin-user-picker', 'admin-settings-legacy-redirect', 'admin-outlook-ical-subscription']
		);

		$requireSubstituteJson = $this->appConfig->getAppValueString('require_substitute_types', '[]');
		$requireSubstituteTypes = json_decode($requireSubstituteJson, true);
		if (!is_array($requireSubstituteTypes)) {
			$requireSubstituteTypes = [];
		}
		$settings = [
			'autoComplianceCheck' => $this->appConfig->getAppValueString('auto_compliance_check', '1') === '1',
			'realtimeComplianceCheck' => $this->appConfig->getAppValueString('realtime_compliance_check', '1') === '1',
			'complianceStrictMode' => $this->appConfig->getAppValueString('compliance_strict_mode', '0') === '1',
			'enableViolationNotifications' => $this->appConfig->getAppValueString('enable_violation_notifications', '1') === '1',
			'breakAutoFallbackEnabled' => $this->appConfig->getAppValueString('break_auto_fallback_enabled', '1') === '1',
			'breakAutoFallbackMinutes' => max(15, min(720, (int)$this->appConfig->getAppValueString('break_auto_fallback_minutes', '180'))),
			'breakAutoFallbackFlexWindowStart' => max(0, min(23, (int)$this->appConfig->getAppValueString('break_auto_fallback_flex_window_start', '11'))),
			'breakAutoFallbackFlexWindowEnd' => max(1, min(24, (int)$this->appConfig->getAppValueString('break_auto_fallback_flex_window_end', '16'))),
			'missingClockInRemindersEnabled' => $this->appConfig->getAppValueString('missing_clock_in_reminders_enabled', '1') === '1',
			'exportMidnightSplitEnabled' => $this->appConfig->getAppValueString('export_midnight_split_enabled', '1') === '1',
			'datevBeraternummer' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_BERATERNUMMER, ''),
			'datevMandantennummer' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_MANDANTENNUMMER, ''),
			'datevLohnartNormal' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_LOHNART_NORMAL, '1000'),
			'datevLohnartUeberstunden' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_LOHNART_UEBERSTUNDEN, '2000'),
			'monthClosureEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_MONTH_CLOSURE_ENABLED, '0') === '1',
			'monthClosureGraceDaysAfterEom' => max(0, min(90, (int)$this->appConfig->getAppValueString(Constants::CONFIG_MONTH_CLOSURE_GRACE_DAYS_AFTER_EOM, '0'))),
			'requireSubstituteTypes' => $requireSubstituteTypes,
			'sendIcalApprovedAbsences' => $this->appConfig->getAppValueString('send_ical_approved_absences', '1') === '1',
			'sendIcalToSubstitute' => $this->appConfig->getAppValueString('send_ical_to_substitute', '0') === '1',
			'sendIcalToManagers' => $this->appConfig->getAppValueString('send_ical_to_managers', '0') === '1',
			'sendEmailSubstitutionRequest' => $this->appConfig->getAppValueString('send_email_substitution_request', '1') === '1',
			'sendEmailSubstituteApprovedToEmployee' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_employee', '1') === '1',
			'sendEmailSubstituteApprovedToManager' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_manager', '1') === '1',
			'maxDailyHours' => (float)$this->appConfig->getAppValueString('max_daily_hours', $this->getProfileMaxDailyHoursDefault()),
			'minRestPeriod' => (float)$this->appConfig->getAppValueString('min_rest_period', $this->getProfileMinRestHoursDefault()),
			'timePickerMinuteStep' => \OCA\ArbeitszeitCheck\Support\TimePickerMinuteStep::normalize(
				(int)$this->appConfig->getAppValueString(
					Constants::CONFIG_TIME_PICKER_MINUTE_STEP,
					(string)\OCA\ArbeitszeitCheck\Constants::TIME_PICKER_MINUTE_STEP
				)
			),
			'country' => $this->getConfiguredCountry(),
			'germanState' => $this->getConfiguredDefaultRegion(),
			'weeklyAbsoluteMaxHours' => $this->getConfiguredSwissWeeklyAbsoluteMax(),
			'vacationDaysSuggestion' => LaborLawProfileFactory::profileForCountry($this->getConfiguredCountry())->vacationDaysSuggestion,
			'statutoryAutoReseed' => $this->appConfig->getAppValueString('statutory_auto_reseed', '1') === '1',
			'retentionPeriod' => (int)$this->appConfig->getAppValueString('retention_period', '2'),
			'defaultWorkingHours' => (float)$this->appConfig->getAppValueString('default_working_hours', '8'),
			'vacationCarryoverExpiryMonth' => max(1, min(12, (int)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3'))),
			'vacationCarryoverExpiryDay' => max(1, min(31, (int)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31'))),
			'vacationCarryoverMaxDays' => $this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS, ''),
			'vacationRolloverEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_VACATION_ROLLOVER_ENABLED, '1') === '1',
			'vacationRolloverIncludeUnusedAnnual' => $this->appConfig->getAppValueString(Constants::CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL, '0') === '1',
			'timeEntryChangesRequireApproval' => $this->appConfig->getAppValueString(Constants::CONFIG_TIME_ENTRY_CHANGES_REQUIRE_APPROVAL, '0') === '1',
			'manualTimeEntriesRequireApproval' => $this->appConfig->getAppValueString(Constants::CONFIG_MANUAL_TIME_ENTRIES_REQUIRE_APPROVAL, '0') === '1',
			'clockStampingEnabled' => $this->timeCaptureMethodService->isOrganizationClockStampingEnabled(),
			'manualTimeEntryEnabled' => $this->timeCaptureMethodService->isOrganizationManualTimeEntryEnabled(),
			'offlineStampMaxPastHours' => \OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy::fromAppConfigString(
				$this->appConfig->getAppValueString(
					Constants::CONFIG_OFFLINE_STAMP_MAX_PAST_HOURS,
					(string)\OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy::DEFAULT_PAST_HOURS
				)
			),
			'accessRestrictionEnabled' => $this->isAccessRestrictionEnabledFromConfig(),
			'accessAllowedGroups' => $this->getAllowedAccessGroupsFromConfig(),
			'accessAllowedUserIds' => $this->getConfiguredAccessAllowedUserIds(),
			'appAdminUserIds' => $this->getConfiguredAppAdminUserIds(),
			'projectCheckIntegrationEnabled' => $this->isProjectCheckInstalledOnInstance()
				&& $this->appConfig->getAppValueString(Constants::CONFIG_PROJECTCHECK_INTEGRATION_ENABLED, Constants::CONFIG_PROJECTCHECK_INTEGRATION_DEFAULT) === '1',
		];

		$projectCheckAvailable = $this->isProjectCheckInstalledOnInstance();

		$shell = $this->buildAdminShellParams(
			'admin-settings',
			$catalog->label($this->l10n, $section),
			$catalog->help($this->l10n, $section),
		);
		// SETTINGS-PAGES-STANDARD §7: App → Global settings (link) → section
		$shell['breadcrumbSection'] = '';
		$shell['breadcrumbParent'] = [
			'label' => $this->l10n->t('Global settings'),
			'url' => $catalog->url(
				$this->urlGenerator,
				\OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::DEFAULT_SECTION
			),
		];

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-settings', array_merge(
			$shell,
			[
				'settings' => $settings,
				'settingsSection' => $section,
				'settingsPages' => $catalog->chipBarPayload($this->l10n, $this->urlGenerator, $section),
				'availableGroups' => $this->getAvailableGroupsForAccessControl(),
				'availableAppAdmins' => $this->getAvailableAppAdminsForAccessControl(),
				'availableAccessUsers' => $this->getAvailableAccessUsersForAccessControl(),
				'urlGenerator' => $this->urlGenerator,
				'settingsShell' => 'app',
				'inAppAdminSettingsUrl' => $catalog->url(
					$this->urlGenerator,
					\OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::DEFAULT_SECTION
				),
				'supportUsUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.supportUs'),
				'projectCheckAvailable' => $projectCheckAvailable,
				'projectCheckEnabledForCurrentUser' => $this->appManager->isEnabledForUser(Constants::APP_ID_PROJECTCHECK) === true,
				'projectCheckAppsUrl' => $this->projectCheckAppsSettingsUrl(),
				'requesttoken' => Util::callRegister(),
				'useAppTeams' => $this->appConfig->getAppValueString('use_app_teams', '0') === '1',
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Support & us — partner care / invoiceable options (admin-only; informational CTAs).
	 *
	 * Security: AppAdminMiddleware gates all AdminController methods. This page
	 * never gates AGPL use; it only surfaces mailto/https CTAs with noopener on
	 * external targets.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function supportUs(): TemplateResponse
	{
		$this->registerFrontEndAssets('', 'admin-support-us');

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-support-us', array_merge(
			$this->buildAdminShellParams(
				'admin-support-us',
				$this->l10n->t('Support & us'),
				$this->l10n->t('Partner care, workshops, commissioned features, and voluntary sponsors — the app stays free under AGPL.'),
			),
			[
				'urlGenerator' => $this->urlGenerator,
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Admin notifications page (alerts, calendar email, HR mailbox).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function notifications(): TemplateResponse
	{
		$this->registerFrontEndAssets(
			'admin-notifications',
			'admin-notifications',
			['admin-settings'],
			['admin-policy-legacy-redirect']
		);

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-notifications', array_merge(
			$this->buildAdminShellParams(
				'admin-notifications',
				$this->l10n->t('Notifications'),
				$this->l10n->t('Reminders, calendar emails, overtime alerts, and HR mailbox.'),
			),
			[
				'settings' => $this->buildNotificationSettingsPayload(),
				'absenceTypes' => $this->getNotificationAbsenceTypes(),
				'eventTypes' => $this->getNotificationEventTypes(),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
				'requesttoken' => Util::callRegister(),
			],
		));

		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Admin overtime settings (bank cap + hour premiums).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function overtimeSettings(): TemplateResponse
	{
		$this->registerFrontEndAssets('admin-notifications', 'admin-notifications', ['admin-settings']);

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-overtime-settings', array_merge(
			$this->buildAdminShellParams(
				'admin-overtime-settings',
				$this->l10n->t('Overtime & premiums'),
				$this->l10n->t('Overtime bank and hour premiums for reports.'),
			),
			[
				'settings' => $this->buildNotificationSettingsPayload(),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
				'requesttoken' => Util::callRegister(),
			],
		));

		return $this->configureCSP($response, 'admin');
	}

	private function getConfiguredSwissWeeklyAbsoluteMax(): int
	{
		if ($this->laborLawProfileFactory !== null) {
			return (int)$this->laborLawProfileFactory->resolveSwissWeeklyAbsoluteMax();
		}
		$raw = (int)$this->appConfig->getAppValueString(
			LaborLawProfileFactory::CONFIG_KEY_WEEKLY_ABSOLUTE_MAX,
			'45'
		);

		return $raw === 50 ? 50 : 45;
	}

	private function clearLaborLawProfileCache(): void
	{
		if ($this->laborLawProfileFactory !== null) {
			$this->laborLawProfileFactory->clearCache();
			return;
		}
		try {
			\OCP\Server::get(LaborLawProfileFactory::class)->clearCache();
		} catch (\Throwable) {
			// Unit tests may not have a DI container.
		}
	}

	/**
	 * Instance country ('DE'/'AT'/'CH'), validated with a safe German fallback.
	 */
	private function getConfiguredCountry(): string
	{
		$country = strtoupper(trim($this->appConfig->getAppValueString('country', RegionRegistry::COUNTRY_DE)));

		return RegionRegistry::isSupportedCountry($country) ? $country : RegionRegistry::COUNTRY_DE;
	}

	/**
	 * Bachus: admin APIs always speak calendar days; storage may be hours.
	 */
	private function vacationUnitService(): \OCA\ArbeitszeitCheck\Service\VacationUnitService
	{
		$config = $this->config;
		if ($config === null) {
			$config = \OCP\Server::get(\OCP\IConfig::class);
			$this->config = $config;
		}

		return new \OCA\ArbeitszeitCheck\Service\VacationUnitService($config);
	}

	private function presentAdminVacationDays(?float $stored): ?float
	{
		if ($stored === null) {
			return null;
		}

		return $this->vacationUnitService()->storedAmountToAdminDays($stored);
	}

	/**
	 * Optional YYYY query param for admin balance fields (carryover / overtime).
	 * Empty → current calendar year. Invalid → null (caller returns 400).
	 */
	private function resolveAdminBalanceYearParam(string $paramName): ?int
	{
		$raw = $this->request->getParam($paramName);
		if ($raw === null || $raw === '') {
			return (int)date('Y');
		}
		if (!is_scalar($raw)) {
			return null;
		}
		$trimmed = trim((string)$raw);
		if (!preg_match('/^\d{4}$/', $trimmed)) {
			return null;
		}
		$year = (int)$trimmed;
		if ($year < 2000 || $year > 2100) {
			return null;
		}

		return $year;
	}

	/**
	 * @param array<string, mixed> $summary
	 * @return array<string, mixed>
	 */
	private function presentUserWorkingTimeModelSummary(array $summary): array
	{
		if (isset($summary['vacationDaysPerYear']) && $summary['vacationDaysPerYear'] !== null) {
			$summary['vacationDaysPerYear'] = $this->presentAdminVacationDays((float)$summary['vacationDaysPerYear']);
		}

		return $summary;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildAdminUserSsrPayload(\OCP\IUser $user): array
	{
		$userId = $user->getUID();
		$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
		$workingTimeModel = null;
		if ($currentModel) {
			try {
				$workingTimeModel = $this->workingTimeModelMapper->find($currentModel->getWorkingTimeModelId());
			} catch (\Throwable $e) {
				// Model might have been deleted
			}
		}

		$startDate = $currentModel ? $currentModel->getStartDate() : null;
		$endDate = $currentModel ? $currentModel->getEndDate() : null;

		return [
			'userId' => $userId,
			'displayName' => $user->getDisplayName(),
			'email' => $user->getEMailAddress(),
			'enabled' => $user->isEnabled(),
			'workingTimeModel' => $workingTimeModel ? [
				'id' => $workingTimeModel->getId(),
				'name' => $workingTimeModel->getName(),
			] : null,
			'vacationDaysPerYear' => $currentModel ? $currentModel->getVacationDaysPerYear() : null,
			'workingTimeModelStartDate' => $startDate ? $startDate->format('Y-m-d') : null,
			'workingTimeModelEndDate' => $endDate ? $endDate->format('Y-m-d') : null,
			'overtimeTrackingFrom' => $this->userOvertimeSettingsService->getTrackingFrom($userId)?->format('Y-m-d'),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildAdminUserApiPayload(\OCP\IUser $user): array
	{
		$userId = (string)$user->getUID();
		$currentYear = (int)date('Y');
		$policy = $this->userVacationPolicyAssignmentMapper->findCurrentByUser($userId);
		$entitlementPreview = $this->vacationEntitlementEngine->computeForDate($userId, new \DateTimeImmutable('today'));
		$entitlementFullDays = round((float)$entitlementPreview['days'], 2);
		$entitlementProration = $this->vacationProrationService->prorateForYear($userId, $currentYear, $entitlementFullDays);

		$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
		$workingTimeModel = null;
		if ($currentModel) {
			try {
				$workingTimeModel = $this->workingTimeModelMapper->find($currentModel->getWorkingTimeModelId());
			} catch (\Throwable $e) {
				// Model might have been deleted
			}
		}

		$today = new \DateTime();
		$today->setTime(0, 0, 0);
		$hasTimeEntriesToday = $this->timeEntryMapper->hasEntriesOnDate($userId, $today);

		return [
			'userId' => $userId,
			'displayName' => (string)$user->getDisplayName(),
			'email' => $user->getEMailAddress(),
			'enabled' => $user->isEnabled(),
			'workingTimeModel' => $workingTimeModel ? [
				'id' => $workingTimeModel->getId(),
				'name' => $workingTimeModel->getName(),
				'type' => $workingTimeModel->getType(),
				'weeklyHours' => $workingTimeModel->getWeeklyHours(),
				'dailyHours' => $workingTimeModel->getDailyHours(),
				'workDaysPerWeek' => $workingTimeModel->getWorkDaysPerWeek(),
			] : null,
			'vacationDaysPerYear' => $currentModel
				? $this->presentAdminVacationDays((float)$currentModel->getVacationDaysPerYear())
				: null,
			'workingTimeModelStartDate' => $currentModel && ($startDate = $currentModel->getStartDate()) ? $startDate->format('Y-m-d') : null,
			'workingTimeModelEndDate' => $currentModel && ($endDate = $currentModel->getEndDate()) ? $endDate->format('Y-m-d') : null,
			'hasTimeEntriesToday' => $hasTimeEntriesToday,
			'vacationCarryoverDays' => $this->presentAdminVacationDays(
				(float)$this->vacationYearBalanceMapper->getCarryoverDays($userId, $currentYear)
			),
			'vacationCarryoverYear' => $currentYear,
			'vacationPolicy' => $policy ? [
				'id' => $policy->getId(),
				'vacationMode' => $policy->getVacationMode(),
				'manualDays' => $this->presentAdminVacationDays(
					$policy->getManualDays() !== null ? (float)$policy->getManualDays() : null
				),
				'tariffRuleSetId' => $policy->getTariffRuleSetId(),
				'overrideReason' => $policy->getOverrideReason(),
				'effectiveFrom' => $policy->getEffectiveFrom()?->format('Y-m-d'),
				'effectiveTo' => $policy->getEffectiveTo()?->format('Y-m-d'),
				'inheritLowerLayers' => $policy->isInherit(),
			] : null,
			'entitlementPreview' => [
				'days' => (float)$entitlementProration['days'],
				'fullYearDays' => $entitlementFullDays,
				'prorated' => (bool)$entitlementProration['prorated'],
				'prorationMethod' => $entitlementProration['method'],
				'monthsCovered' => $entitlementProration['months_covered'],
				'source' => $entitlementPreview['source'],
				'ruleSetId' => $entitlementPreview['ruleSetId'],
			],
			'overtimeTrackingFrom' => $this->userOvertimeSettingsService->getTrackingFrom($userId)?->format('Y-m-d'),
			'employmentStart' => $this->userEmploymentSettingsService->getEmploymentStart($userId)?->format('Y-m-d'),
			'employmentEnd' => $this->userEmploymentSettingsService->getEmploymentEnd($userId)?->format('Y-m-d'),
		];
	}

	/**
	 * Profile default for max_daily_hours when the key is unset (DE 10 / AT 12).
	 * Explicit admin values always win — this is only the getAppValue fallback.
	 */
	private function getProfileMaxDailyHoursDefault(): string
	{
		return (string)LaborLawProfileFactory::profileForCountry($this->getConfiguredCountry())->dailyMaxHoursDefault;
	}

	/**
	 * Profile default for min_rest_period when the key is unset.
	 */
	private function getProfileMinRestHoursDefault(): string
	{
		return (string)LaborLawProfileFactory::profileForCountry($this->getConfiguredCountry())->minRestHoursDefault;
	}

	/**
	 * Instance default holiday region, validated against the registry with a
	 * country-aware fallback (DE => NW, AT => AT-W).
	 */
	private function getConfiguredDefaultRegion(): string
	{
		$country = $this->getConfiguredCountry();
		$stored = $this->appConfig->getAppValueString(
			'german_state',
			RegionRegistry::defaultRegionForCountry($country)
		);

		return RegionRegistry::resolveDefaultRegionForCountry($country, $stored);
	}

	/**
	 * Admin holidays / calendars page (admin-only by default)
	 *
	 * Dedicated UI to explain and manage holiday calendars per region.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function holidays(): TemplateResponse
	{
		// One-time legacy migration: import old company_holidays JSON into at_holidays
		$this->migrateLegacyCompanyHolidaysIfNeeded();

		$this->registerFrontEndAssets('admin-holidays', 'admin-holidays', ['common/country-region'], ['common/datepicker']);

		$defaultState = $this->getConfiguredDefaultRegion();
		$statutoryAutoReseed = $this->appConfig->getAppValueString('statutory_auto_reseed', '1') === '1';
		$settingsCatalog = new \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog();

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-holidays', array_merge(
			$this->buildAdminShellParams(
				'admin-holidays',
				$this->l10n->t('Holidays and calendars'),
				$this->l10n->t('Manage regional holiday calendars and the organisation default calendar.'),
			),
			[
				'defaultState' => $defaultState,
				'country' => $this->getConfiguredCountry(),
				'statutoryAutoReseed' => $statutoryAutoReseed,
				'settingsUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.settings'),
				'calendarSubscriptionUrl' => $settingsCatalog->url(
					$this->urlGenerator,
					\OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::SECTION_OUTLOOK_SUBSCRIPTION,
				),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			],
		));

		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Get additional company holidays configuration (legacy, app-wide list).
	 *
	 * New code should use getStateHolidays() which is backed by at_holidays.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getCompanyHolidays(): JSONResponse
	{
		try {
			$json = $this->appConfig->getAppValueString('company_holidays', '[]');
			$items = json_decode($json, true);
			if (!is_array($items)) {
				$items = [];
			}

			// Normalize items
			$holidays = [];
			foreach ($items as $item) {
				if (!is_array($item)) {
					continue;
				}
				$date = isset($item['date']) ? (string)$item['date'] : '';
				$name = isset($item['name']) ? (string)$item['name'] : '';
				if ($date === '' || $name === '') {
					continue;
				}
				$holidays[] = [
					'date' => $date,
					'name' => $name,
					'scope' => isset($item['scope']) ? (string)$item['scope'] : '',
					'kind' => isset($item['kind']) && $item['kind'] === 'half' ? 'half' : 'full',
				];
			}

			return new JSONResponse([
				'success' => true,
				'holidays' => $holidays,
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create or update a single company holiday entry (identified by date).
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function saveCompanyHoliday(): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$date = isset($params['date']) ? trim((string)$params['date']) : '';
			$name = isset($params['name']) ? trim((string)$params['name']) : '';
			$scope = isset($params['scope']) ? trim((string)$params['scope']) : '';
			$kind = isset($params['kind']) && (string)$params['kind'] === 'half' ? 'half' : 'full';

			if ($date === '' || $name === '') {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Date and name are required for a holiday'),
				], Http::STATUS_BAD_REQUEST);
			}

			// Basic date validation (ISO yyyy-mm-dd)
			try {
				$d = new \DateTime($date);
				// Normalize format
				$date = $d->format('Y-m-d');
			} catch (\Throwable $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date format. Expected yyyy-mm-dd.'),
				], Http::STATUS_BAD_REQUEST);
			}

			// Load existing entries
			$json = $this->appConfig->getAppValueString('company_holidays', '[]');
			$items = json_decode($json, true);
			if (!is_array($items)) {
				$items = [];
			}

			// Upsert by date
			$found = false;
			foreach ($items as &$item) {
				if (isset($item['date']) && (string)$item['date'] === $date) {
					$item['name'] = $name;
					$item['scope'] = $scope;
					$item['kind'] = $kind;
					$found = true;
					break;
				}
			}
			unset($item);

			if (!$found) {
				$items[] = [
					'date' => $date,
					'name' => $name,
					'scope' => $scope,
					'kind' => $kind,
				];
			}

			$this->appConfig->setAppValueString('company_holidays', json_encode($items));

			return new JSONResponse([
				'success' => true,
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete a company holiday identified by date.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function deleteCompanyHoliday(): JSONResponse
	{
		try {
			$date = isset($this->request->getParams()['date']) ? trim((string)$this->request->getParams()['date']) : '';
			if ($date === '') {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Date is required to delete a holiday'),
				], Http::STATUS_BAD_REQUEST);
			}

			$json = $this->appConfig->getAppValueString('company_holidays', '[]');
			$items = json_decode($json, true);
			if (!is_array($items)) {
				$items = [];
			}

			$newItems = [];
			foreach ($items as $item) {
				if (!is_array($item) || !isset($item['date']) || (string)$item['date'] !== $date) {
					$newItems[] = $item;
				}
			}

			$this->appConfig->setAppValueString('company_holidays', json_encode($newItems));

			return new JSONResponse([
				'success' => true,
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get holidays for a given state and year (backed by at_holidays).
	 *
	 * @param string $state
	 * @param int $year
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getStateHolidays(string $state, int $year): JSONResponse
	{
		try {
			$this->migrateLegacyCompanyHolidaysIfNeeded();

			$state = strtoupper(trim($state));
			if (!RegionRegistry::isValidRegion($state)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid region code'),
				], Http::STATUS_BAD_REQUEST);
			}
			if ($year < 1970 || $year > 2100) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid year'),
				], Http::STATUS_BAD_REQUEST);
			}

			$start = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
			$end = new \DateTimeImmutable(sprintf('%04d-12-31', $year));

			// Use HolidayService as primary source (DB-backed, incl. any
			// manually erfasste Firmen-/Custom-Feiertage).
			$dtoList = $this->holidayCalendarService->getHolidaysForRange(
				$state,
				new \DateTime($start->format('Y-m-d')),
				new \DateTime($end->format('Y-m-d'))
			);

			return new JSONResponse([
				'success' => true,
				'state' => $state,
				'year' => $year,
				'statutoryAutoReseed' => $this->appConfig->getAppValueString('statutory_auto_reseed', '1') === '1',
				'holidays' => $dtoList,
				'period' => [
					'start' => $start->format('Y-m-d'),
					'end' => $end->format('Y-m-d'),
				],
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Suggested (non-statutory) company holidays for a region and year.
	 *
	 * Returns the catalog suggestions (e.g. Good Friday, patron saint days,
	 * 24/31 December for Austria) together with an "exists" flag telling the
	 * UI whether a holiday is already present on that date. Suggestions are
	 * never seeded automatically — the admin adds them explicitly (plan §5.3).
	 *
	 * @param string $state Region code (e.g. 'NW', 'AT-W')
	 * @param int $year Four-digit year
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getHolidaySuggestions(string $state, int $year): JSONResponse
	{
		try {
			$state = strtoupper(trim($state));
			if (!RegionRegistry::isValidRegion($state)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid region code'),
				], Http::STATUS_BAD_REQUEST);
			}
			if ($year < 1970 || $year > 2100) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid year'),
				], Http::STATUS_BAD_REQUEST);
			}

			$suggested = HolidayCatalogResolver::suggestedCompanyHolidaysForRegionAndYear($state, $year);

			// Existing holidays (any scope) on the same dates, so the UI can
			// disable "Add" buttons for dates that are already covered.
			$existingByDate = [];
			foreach ($this->holidayCalendarService->getHolidaysForRange(
				$state,
				new \DateTime(sprintf('%04d-01-01', $year)),
				new \DateTime(sprintf('%04d-12-31', $year))
			) as $dto) {
				if (!empty($dto['date'])) {
					$existingByDate[(string)$dto['date']] = true;
				}
			}

			$suggestions = [];
			$country = RegionRegistry::countryOf($state);
			foreach ($suggested as $date => $msgid) {
				$suggestions[] = [
					'date' => $date,
					'name' => $this->l10n->t($msgid),
					'kind' => $this->suggestedCompanyHolidayKind((string)$date, $country),
					'exists' => isset($existingByDate[$date]),
				];
			}

			return new JSONResponse([
				'success' => true,
				'state' => $state,
				'year' => $year,
				'country' => $country,
				'suggestions' => $suggestions,
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Default kind when promoting a catalog suggestion to a company holiday.
	 * AT/CH 24 Dec and 31 Dec are almost always half-days in practice.
	 */
	private function suggestedCompanyHolidayKind(string $date, string $country): string
	{
		if (($country === RegionRegistry::COUNTRY_AT || $country === RegionRegistry::COUNTRY_CH)
			&& preg_match('/^\d{4}-12-(24|31)$/', $date) === 1) {
			return 'half';
		}

		return 'full';
	}

	/**
	 * Create or update a state holiday (backed by at_holidays).
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function saveStateHoliday(): JSONResponse
	{
		try {
			$this->migrateLegacyCompanyHolidaysIfNeeded();

			// Support both traditional form-encoded requests and modern JSON bodies
			$params = $this->request->getParams();
			if ($params === [] || $params === null) {
				$contentType = (string)$this->request->getHeader('Content-Type');
				if (str_contains($contentType, 'application/json')) {
					$raw = @file_get_contents('php://input');
					if (is_string($raw) && $raw !== '') {
						$decoded = json_decode($raw, true);
						if (is_array($decoded)) {
							$params = $decoded;
						}
					}
				}
				if (!is_array($params)) {
					$params = [];
				}
			}
			$id = isset($params['id']) ? (int)$params['id'] : 0;
			$state = isset($params['state']) ? strtoupper(trim((string)$params['state'])) : '';
			$date = isset($params['date']) ? trim((string)$params['date']) : '';
			$name = isset($params['name']) ? trim((string)$params['name']) : '';
			$kind = isset($params['kind']) && (string)$params['kind'] === Holiday::KIND_HALF ? Holiday::KIND_HALF : Holiday::KIND_FULL;
			$scope = isset($params['scope']) ? trim((string)$params['scope']) : Holiday::SCOPE_COMPANY;

			if ($state === '' || $date === '' || $name === '') {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('State, date, and name are required for a holiday'),
				], Http::STATUS_BAD_REQUEST);
			}
			if (!RegionRegistry::isValidRegion($state)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid region code'),
				], Http::STATUS_BAD_REQUEST);
			}

			try {
				$dateObj = new \DateTime($date);
				$dateObj->setTime(0, 0, 0);
			} catch (\Throwable $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date format. Expected yyyy-mm-dd.'),
				], Http::STATUS_BAD_REQUEST);
			}

			if (!in_array($scope, [Holiday::SCOPE_STATUTORY, Holiday::SCOPE_COMPANY, Holiday::SCOPE_CUSTOM], true)) {
				$scope = Holiday::SCOPE_COMPANY;
			}

			// Statutory holidays may be half-day in Switzerland (E-6). Do not
			// force full-day here — the catalog seeds the correct kind, and
			// admins may correct kind without being overwritten.
			if ($scope === Holiday::SCOPE_STATUTORY && $kind !== Holiday::KIND_HALF) {
				$kind = Holiday::KIND_FULL;
			}

			// Conflict pre-check against the unique (state, date, scope) index:
			// a friendly 409 instead of a DB-level constraint error.
			$conflictId = $this->holidayMapper->findIdForStateDateScope($state, $dateObj->format('Y-m-d'), $scope);
			if ($conflictId !== null && $conflictId !== $id) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('A holiday with this scope already exists on this date for this region. Edit the existing entry instead.'),
				], Http::STATUS_CONFLICT);
			}

			$holiday = new Holiday();
			$oldValues = null;
			if ($id > 0) {
				$holiday->setId($id);
				// Load existing holiday for audit log (best-effort).
				try {
					$existing = $this->holidayMapper->findById($id);
					$oldValues = $this->holidayToAuditValues($existing);
				} catch (\Throwable) {
					$oldValues = null;
				}
			}
			if ($holiday->getCreatedAt() === null) {
				$holiday->setCreatedAt(new \DateTime());
			}

			$holiday->setState($state);
			$holiday->setDate($dateObj);
			$holiday->setName($name);
			$holiday->setKind($kind);
			$holiday->setScope($scope);
			$holiday->setSource(Holiday::SOURCE_MANUAL);
			$holiday->setUpdatedAt(new \DateTime());

			if (!$holiday->isValid()) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Holiday definition is invalid'),
				], Http::STATUS_BAD_REQUEST);
			}

			if ($id > 0) {
				$holiday = $this->holidayMapper->update($holiday);
				$action = 'state_holiday_updated';
			} else {
				$holiday = $this->holidayMapper->insert($holiday);
				$action = 'state_holiday_created';
			}

			if ($scope === Holiday::SCOPE_STATUTORY) {
				$this->holidayAdminService->onStatutoryHolidaySaved($state, $dateObj->format('Y-m-d'));
			}

			// Ensure subsequent reads see the updated set of holidays
			$this->holidayCalendarService->clearCacheForStateYear($state, (int)$dateObj->format('Y'));

			// Audit log
			$newValues = $this->holidayToAuditValues($holiday);
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$performedBy,
				$action,
				'state_holiday',
				$holiday->getId(),
				$oldValues,
				$newValues,
				$performedBy
			);

			return new JSONResponse([
				'success' => true,
				'holiday' => [
					'id' => $holiday->getId(),
					'state' => $holiday->getState(),
					'date' => $holiday->getDate() ? $holiday->getDate()->format('Y-m-d') : null,
					'name' => $holiday->getName(),
					'kind' => $holiday->getKind(),
					'scope' => $holiday->getScope(),
					'source' => $holiday->getSource(),
				],
			]);
		} catch (\Throwable $e) {
			if ($this->isUniqueConstraintViolation($e)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('A holiday with this scope already exists on this date for this region. Edit the existing entry instead.'),
				], Http::STATUS_CONFLICT);
			}
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete a state holiday by ID (backed by at_holidays).
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function deleteStateHoliday(int $id): JSONResponse
	{
		try {
			if ($id <= 0) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid holiday ID'),
				], Http::STATUS_BAD_REQUEST);
			}

			$oldValues = null;
			try {
				$existing = $this->holidayMapper->findById($id);
				$oldValues = $this->holidayToAuditValues($existing);
			} catch (DoesNotExistException $e) {
				return new JSONResponse([
					'success' => true,
				]);
			}

			$performedBy = $this->getPerformedBy();
			$deleteResult = $this->holidayAdminService->deleteStateHolidayById($id, $performedBy);
			if (($deleteResult['success'] ?? false) !== true) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Holiday could not be removed.'),
				], Http::STATUS_BAD_REQUEST);
			}

			// Audit log: deletion
			$this->auditLogMapper->logAction(
				$performedBy,
				'state_holiday_deleted',
				'state_holiday',
				$id,
				$oldValues,
				null,
				$performedBy
			);

			return new JSONResponse([
				'success' => true,
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Holiday not found'),
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * One-time migration of legacy app config "company_holidays" into at_holidays.
	 * After successful migration a flag "company_holidays_migrated" is set so this
	 * method becomes a cheap no-op.
	 */
	private function migrateLegacyCompanyHolidaysIfNeeded(): void
	{
		try {
			$alreadyMigrated = $this->appConfig->getAppValueString('company_holidays_migrated', '0') === '1';
			if ($alreadyMigrated) {
				return;
			}

			$json = $this->appConfig->getAppValueString('company_holidays', '[]');
			$items = json_decode($json, true);
			if (!is_array($items) || $items === []) {
				$this->appConfig->setAppValueString('company_holidays_migrated', '1');
				return;
			}

			// Legacy company_holidays predate multi-country support and were
			// organisation-wide, i.e. applied to every German Bundesland.
			$states = array_keys(RegionRegistry::regionsForCountry(RegionRegistry::COUNTRY_DE));

			$now = new \DateTime();

			foreach ($items as $item) {
				if (!is_array($item)) {
					continue;
				}
				$dateStr = isset($item['date']) ? (string)$item['date'] : '';
				$name = isset($item['name']) ? trim((string)$item['name']) : '';
				if ($dateStr === '' || $name === '') {
					continue;
				}
				$kind = (isset($item['kind']) && (string)$item['kind'] === 'half') ? Holiday::KIND_HALF : Holiday::KIND_FULL;

				try {
					$date = new \DateTime($dateStr);
					$date->setTime(0, 0, 0);
				} catch (\Throwable) {
					continue;
				}

				foreach ($states as $state) {
					$existing = $this->holidayMapper->findByStateAndRange($state, $date, $date);
					$duplicate = false;
					foreach ($existing as $existingHoliday) {
						if ($existingHoliday->getScope() === Holiday::SCOPE_COMPANY
							&& $existingHoliday->getName() === $name) {
							$duplicate = true;
							break;
						}
					}
					if ($duplicate) {
						continue;
					}

					$holiday = new Holiday();
					$holiday->setState($state);
					$holiday->setDate(clone $date);
					$holiday->setName($name);
					$holiday->setKind($kind);
					$holiday->setScope(Holiday::SCOPE_COMPANY);
					$holiday->setSource(Holiday::SOURCE_MANUAL);
					$holiday->setCreatedAt(clone $now);
					$holiday->setUpdatedAt(clone $now);

					if (!$holiday->isValid()) {
						continue;
					}

					try {
						$this->holidayMapper->insert($holiday);
					} catch (\Throwable) {
						// ignore individual insert errors, continue with others
					}
				}
			}

			$this->appConfig->setAppValueString('company_holidays_migrated', '1');
		} catch (\Throwable) {
			// Never break admin UI because of a failed migration; it can be retried later.
		}
	}

	/**
	 * Admin working time models management page (admin-only by default)
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function workingTimeModels(): TemplateResponse
	{
		$this->registerFrontEndAssets('working-time-models', 'working-time-models');



		$models = $this->workingTimeModelMapper->findAll();
		$modelsData = [];
		foreach ($models as $model) {
			$modelsData[] = [
				'id' => $model->getId(),
				'name' => $model->getName(),
				'description' => $model->getDescription(),
				'type' => $model->getType(),
				'weeklyHours' => $model->getWeeklyHours(),
				'dailyHours' => $model->getDailyHours(),
				'workDaysPerWeek' => $model->getWorkDaysPerWeek(),
				'isDefault' => $model->getIsDefault()
			];
		}

		$response = new TemplateResponse('arbeitszeitcheck', 'working-time-models', array_merge(
			$this->buildAdminShellParams(
				'admin-working-time-models',
				$this->l10n->t('Working time models'),
				$this->l10n->t('Configure working time models'),
			),
			[
				'models' => $modelsData,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Tariff rule sets admin CRUD page.
	 *
	 * Provides an auditable UI for the existing
	 * GET/POST/PUT/DELETE /api/admin/tariff-rule-sets API surface so that
	 * payroll auditors can review and manage tariff rule sets without
	 * resorting to direct API calls or DB edits. Existing assignment
	 * dropdowns (admin-users → vacation policy) are unaffected.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tariffRuleSets(): TemplateResponse
	{
		$this->registerFrontEndAssets('admin-tariff-rules', 'admin-tariff-rules', [], ['common/datepicker']);

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-tariff-rules', array_merge(
			$this->buildAdminShellParams(
				'admin-tariff-rules',
				$this->l10n->t('Tariff rule sets'),
				$this->l10n->t('Manage tariff rule sets used for vacation entitlement calculations.'),
			),
			[
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Admin audit log viewer page (admin-only by default)
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function auditLog(): TemplateResponse
	{
		$this->registerFrontEndAssets('audit-log-viewer', 'audit-log', [], ['common/datepicker', 'common/admin-user-picker']);

		$endDate = new \DateTime();
		$endDate->setTime(23, 59, 59);
		$startDate = clone $endDate;
		$startDate->modify('-30 days');
		$startDate->setTime(0, 0, 0);

		$searchFilters = [];
		$total = $this->auditLogMapper->countByDateRange($startDate, $endDate, $searchFilters);
		$logs = $this->auditLogMapper->searchByDateRange($startDate, $endDate, array_merge($searchFilters, [
			'limit' => self::AUDIT_LOG_PAGE_SIZE,
			'offset' => 0,
		]));

		$logsData = array_map(fn (AuditLog $log): array => $this->formatAuditLogEntry($log), $logs);

		$response = new TemplateResponse('arbeitszeitcheck', 'audit-log', array_merge(
			$this->buildAdminShellParams(
				'admin-audit-log',
				$this->l10n->t('Audit log'),
				$this->l10n->t('Search, filter, and export administrative actions for compliance and accountability.'),
			),
			[
				'logs' => $logsData,
				'total' => $total,
				'limit' => self::AUDIT_LOG_PAGE_SIZE,
				'offset' => 0,
				'startDate' => $startDate->format('d.m.Y'),
				'endDate' => $endDate->format('d.m.Y'),
				'actionCategoryOptions' => $this->auditLogPresenter->getActionCategoryFilterOptions(),
				'entityTypeOptions' => $this->auditLogPresenter->getEntityTypeFilterOptions(),
				'maxDateRangeDays' => Constants::MAX_EXPORT_DATE_RANGE_DAYS,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * @return array<string, mixed>|JSONResponse
	 */
	private function parseAuditLogDateFilters(array $params): array|JSONResponse
	{
		$startDate = null;
		$endDate = null;
		if (isset($params['start_date']) && $params['start_date']) {
			try {
				$startDate = new \DateTime((string)$params['start_date']);
				$startDate->setTime(0, 0, 0);
			} catch (\Throwable) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid start date'),
				], Http::STATUS_BAD_REQUEST);
			}
		}
		if (isset($params['end_date']) && $params['end_date']) {
			try {
				$endDate = new \DateTime((string)$params['end_date']);
				$endDate->setTime(23, 59, 59);
			} catch (\Throwable) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid end date'),
				], Http::STATUS_BAD_REQUEST);
			}
		}

		if ($startDate === null && $endDate === null) {
			$endDate = new \DateTime();
			$endDate->setTime(23, 59, 59);
			$startDate = clone $endDate;
			$startDate->modify('-30 days');
			$startDate->setTime(0, 0, 0);
		} elseif ($startDate === null || $endDate === null) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Please provide both a start date and an end date.'),
			], Http::STATUS_BAD_REQUEST);
		}

		if ($startDate > $endDate) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Start date must be before or equal to end date'),
			], Http::STATUS_BAD_REQUEST);
		}

		$diff = $startDate->diff($endDate);
		$days = (int)$diff->format('%a');
		if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Date range must not exceed %d days. Please narrow the range.', [Constants::MAX_EXPORT_DATE_RANGE_DAYS]),
			], Http::STATUS_BAD_REQUEST);
		}

		return [
			'startDate' => $startDate,
			'endDate' => $endDate,
		];
	}

	/**
	 * @return array<string, mixed>|JSONResponse
	 */
	private function buildAuditLogSearchFilters(array $params): array|JSONResponse
	{
		$filters = [];

		$userQuery = isset($params['user_id']) ? trim((string)$params['user_id']) : '';
		if ($userQuery !== '') {
			if (strlen($userQuery) > 200) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User filter is too long.'),
				], Http::STATUS_BAD_REQUEST);
			}
			$filters['user_id_like'] = $userQuery;
		}

		$exactAction = isset($params['action']) ? trim((string)$params['action']) : '';
		if ($exactAction !== '') {
			$filters['action'] = $exactAction;
		} else {
			$actionCategory = isset($params['action_category']) ? trim((string)$params['action_category']) : '';
			if ($actionCategory === '' && isset($params['action_type'])) {
				$actionCategory = trim((string)$params['action_type']);
			}
			if ($actionCategory !== '') {
				if (!$this->auditLogPresenter->isValidActionCategory($actionCategory)) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Unknown action category.'),
					], Http::STATUS_BAD_REQUEST);
				}
				$categoryActions = $this->auditLogPresenter->resolveCategoryActions($actionCategory);
				if ($categoryActions === []) {
					$filters['actions_in'] = ['__no_matching_actions__'];
				} elseif ($categoryActions !== null) {
					$filters['actions_in'] = $categoryActions;
				}
			}
		}

		$entityType = isset($params['entity_type']) ? trim((string)$params['entity_type']) : '';
		if ($entityType !== '') {
			$filters['entity_type'] = $entityType;
		}

		$offlineSyncRaw = $params['offlineSync'] ?? $params['offline_sync'] ?? $params['captureSource'] ?? $params['capture_source'] ?? null;
		if ($offlineSyncRaw !== null && $offlineSyncRaw !== '') {
			$offlineFlag = is_bool($offlineSyncRaw)
				? $offlineSyncRaw
				: in_array(strtolower(trim((string)$offlineSyncRaw)), ['1', 'true', 'yes', 'offline_sync'], true);
			if ($offlineFlag || strtolower(trim((string)$offlineSyncRaw)) === 'offline_sync') {
				$filters['offline_sync'] = true;
			}
		}

		return $filters;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function formatAuditLogEntry(AuditLog $log): array
	{
		$user = $this->userManager->get($log->getUserId());
		$performedByUser = $log->getPerformedBy() ? $this->userManager->get($log->getPerformedBy()) : null;
		$offlineMeta = $this->resolveOfflineSyncAuditMeta($log);

		return [
			'id' => $log->getId(),
			'userId' => $log->getUserId(),
			'userDisplayName' => $this->auditLogPresenter->formatActor($log->getUserId(), $user ?: null),
			'action' => $this->auditLogPresenter->formatAction($log->getAction()),
			'actionKey' => $log->getAction(),
			'entityType' => $this->auditLogPresenter->formatEntityType($log->getEntityType()),
			'entityTypeKey' => $log->getEntityType(),
			'entityId' => $log->getEntityId(),
			'performedBy' => $log->getPerformedBy(),
			'performedByDisplayName' => $this->auditLogPresenter->formatActor($log->getPerformedBy() ?? $log->getUserId(), $performedByUser ?: null),
			'createdAt' => $this->auditLogPresenter->formatCreatedAt($log->getCreatedAt()),
			'createdAtIso' => ($createdAt = $log->getCreatedAt()) ? $createdAt->format('c') : null,
			'captureSource' => $offlineMeta['captureSource'],
			'clientOccurredAtIso' => $offlineMeta['clientOccurredAtIso'],
			'isOfflineSync' => $offlineMeta['isOfflineSync'],
		];
	}

	/**
	 * Prefer indexed capture_source column; fall back to newValues JSON for display of client_occurred_at.
	 *
	 * @return array{captureSource: ?string, clientOccurredAtIso: ?string, isOfflineSync: bool}
	 */
	private function resolveOfflineSyncAuditMeta(AuditLog $log): array
	{
		$fromJson = $this->parseOfflineSyncAuditMeta($log->getNewValues());
		$col = $log->getCaptureSource();
		if (is_string($col) && trim($col) === Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC) {
			return [
				'captureSource' => Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC,
				'clientOccurredAtIso' => $fromJson['clientOccurredAtIso'],
				'isOfflineSync' => true,
			];
		}

		return $fromJson;
	}

	/**
	 * Present existing offline_sync meta from audit newValues — do not invent action keys.
	 *
	 * @return array{captureSource: ?string, clientOccurredAtIso: ?string, isOfflineSync: bool}
	 */
	private function parseOfflineSyncAuditMeta(?string $newValuesJson): array
	{
		$empty = [
			'captureSource' => null,
			'clientOccurredAtIso' => null,
			'isOfflineSync' => false,
		];
		if ($newValuesJson === null || $newValuesJson === '') {
			return $empty;
		}
		$decoded = json_decode($newValuesJson, true);
		if (!is_array($decoded)) {
			return $empty;
		}
		$source = isset($decoded['capture_source']) ? trim((string)$decoded['capture_source']) : '';
		if ($source !== Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC) {
			return $empty;
		}
		$occurred = isset($decoded['client_occurred_at']) ? trim((string)$decoded['client_occurred_at']) : '';
		return [
			'captureSource' => Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC,
			'clientOccurredAtIso' => $occurred !== '' ? $occurred : null,
			'isOfflineSync' => true,
		];
	}

	/**
	 * Get admin settings (admin-only by default)
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAdminSettings(): JSONResponse
	{
		try {
			$requireSubstituteJson = $this->appConfig->getAppValueString('require_substitute_types', '[]');
			$requireSubstituteTypes = json_decode($requireSubstituteJson, true);
			if (!is_array($requireSubstituteTypes)) {
				$requireSubstituteTypes = [];
			}
			$settings = [
				'autoComplianceCheck' => $this->appConfig->getAppValueString('auto_compliance_check', '1') === '1',
				'enableViolationNotifications' => $this->appConfig->getAppValueString('enable_violation_notifications', '1') === '1',
				'breakAutoFallbackEnabled' => $this->appConfig->getAppValueString('break_auto_fallback_enabled', '1') === '1',
				'breakAutoFallbackMinutes' => max(15, min(720, (int)$this->appConfig->getAppValueString('break_auto_fallback_minutes', '180'))),
				'breakAutoFallbackFlexWindowStart' => max(0, min(23, (int)$this->appConfig->getAppValueString('break_auto_fallback_flex_window_start', '11'))),
				'breakAutoFallbackFlexWindowEnd' => max(1, min(24, (int)$this->appConfig->getAppValueString('break_auto_fallback_flex_window_end', '16'))),
				'missingClockInRemindersEnabled' => $this->appConfig->getAppValueString('missing_clock_in_reminders_enabled', '1') === '1',
				'exportMidnightSplitEnabled' => $this->appConfig->getAppValueString('export_midnight_split_enabled', '1') === '1',
				'datevBeraternummer' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_BERATERNUMMER, ''),
				'datevMandantennummer' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_MANDANTENNUMMER, ''),
				'datevLohnartNormal' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_LOHNART_NORMAL, '1000'),
				'datevLohnartUeberstunden' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_LOHNART_UEBERSTUNDEN, '2000'),
				'monthClosureEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_MONTH_CLOSURE_ENABLED, '0') === '1',
				'monthClosureGraceDaysAfterEom' => max(0, min(90, (int)$this->appConfig->getAppValueString(Constants::CONFIG_MONTH_CLOSURE_GRACE_DAYS_AFTER_EOM, '0'))),
				'requireSubstituteTypes' => $requireSubstituteTypes,
				'sendIcalApprovedAbsences' => $this->appConfig->getAppValueString('send_ical_approved_absences', '1') === '1',
				'sendIcalToSubstitute' => $this->appConfig->getAppValueString('send_ical_to_substitute', '0') === '1',
				'sendIcalToManagers' => $this->appConfig->getAppValueString('send_ical_to_managers', '0') === '1',
				'sendEmailSubstitutionRequest' => $this->appConfig->getAppValueString('send_email_substitution_request', '1') === '1',
				'sendEmailSubstituteApprovedToEmployee' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_employee', '1') === '1',
				'sendEmailSubstituteApprovedToManager' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_manager', '1') === '1',
				'maxDailyHours' => (float)$this->appConfig->getAppValueString('max_daily_hours', $this->getProfileMaxDailyHoursDefault()),
				'minRestPeriod' => (float)$this->appConfig->getAppValueString('min_rest_period', $this->getProfileMinRestHoursDefault()),
				'timePickerMinuteStep' => \OCA\ArbeitszeitCheck\Support\TimePickerMinuteStep::normalize(
					(int)$this->appConfig->getAppValueString(
						Constants::CONFIG_TIME_PICKER_MINUTE_STEP,
						(string)\OCA\ArbeitszeitCheck\Constants::TIME_PICKER_MINUTE_STEP
					)
				),
				'country' => $this->getConfiguredCountry(),
				'germanState' => $this->getConfiguredDefaultRegion(),
				'weeklyAbsoluteMaxHours' => $this->getConfiguredSwissWeeklyAbsoluteMax(),
				'vacationDaysSuggestion' => LaborLawProfileFactory::profileForCountry($this->getConfiguredCountry())->vacationDaysSuggestion,
				'statutoryAutoReseed' => $this->appConfig->getAppValueString('statutory_auto_reseed', '1') === '1',
				'retentionPeriod' => (int)$this->appConfig->getAppValueString('retention_period', '2'),
				'defaultWorkingHours' => (float)$this->appConfig->getAppValueString('default_working_hours', '8'),
				'vacationCarryoverExpiryMonth' => max(1, min(12, (int)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3'))),
				'vacationCarryoverExpiryDay' => max(1, min(31, (int)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31'))),
				'vacationCarryoverMaxDays' => $this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS, ''),
				'vacationRolloverEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_VACATION_ROLLOVER_ENABLED, '1') === '1',
				'vacationRolloverIncludeUnusedAnnual' => $this->appConfig->getAppValueString(Constants::CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL, '0') === '1',
				'timeEntryChangesRequireApproval' => $this->appConfig->getAppValueString(Constants::CONFIG_TIME_ENTRY_CHANGES_REQUIRE_APPROVAL, '0') === '1',
				'manualTimeEntriesRequireApproval' => $this->appConfig->getAppValueString(Constants::CONFIG_MANUAL_TIME_ENTRIES_REQUIRE_APPROVAL, '0') === '1',
				'clockStampingEnabled' => $this->timeCaptureMethodService->isOrganizationClockStampingEnabled(),
				'manualTimeEntryEnabled' => $this->timeCaptureMethodService->isOrganizationManualTimeEntryEnabled(),
				'offlineStampMaxPastHours' => \OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy::fromAppConfigString(
					$this->appConfig->getAppValueString(
						Constants::CONFIG_OFFLINE_STAMP_MAX_PAST_HOURS,
						(string)\OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy::DEFAULT_PAST_HOURS
					)
				),
				'accessRestrictionEnabled' => $this->isAccessRestrictionEnabledFromConfig(),
				'accessAllowedGroups' => $this->getAllowedAccessGroupsFromConfig(),
				'accessAllowedUserIds' => $this->getConfiguredAccessAllowedUserIds(),
				'appAdminUserIds' => $this->getConfiguredAppAdminUserIds(),
			];

			return new JSONResponse([
				'success' => true,
				'settings' => $settings,
				'availableGroups' => $this->getAvailableGroupsForAccessControl(),
				'availableAppAdmins' => $this->getAvailableAppAdminsForAccessControl(),
				'availableAccessUsers' => $this->getAvailableAccessUsersForAccessControl(),
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getNotificationSettings(): JSONResponse
	{
		try {
			return new JSONResponse([
				'success' => true,
				'settings' => $this->buildNotificationSettingsPayload(),
				'absenceTypes' => $this->getNotificationAbsenceTypes(),
				'eventTypes' => $this->getNotificationEventTypes(),
			]);
		} catch (\Throwable) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update admin settings (admin-only by default)
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function updateAdminSettings(): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$catalog = new \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog();
			$scopeRaw = (string)($params['settings_section'] ?? $params['settingsSection'] ?? '');
			$scope = $scopeRaw === ''
				? \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::SECTION_ALL
				: $scopeRaw;
			if (!$catalog->isWritableScope($scope)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid settings section.'),
				], Http::STATUS_BAD_REQUEST);
			}
			$allowedForScope = $catalog->allowedParamKeys($scope);
			$paramAllowed = static function (string $key) use ($allowedForScope): bool {
				return $allowedForScope === null || in_array($key, $allowedForScope, true);
			};
			$accessScopeOk = $scope === \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::SECTION_ALL
				|| $scope === \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::SECTION_ACCESS;
			$timeCaptureScopeOk = $scope === \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::SECTION_ALL
				|| $scope === \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::SECTION_TIME_RECORDING;

			// List of allowed admin settings keys
			$allowedKeys = [
				'autoComplianceCheck' => 'auto_compliance_check',
				'realtimeComplianceCheck' => 'realtime_compliance_check',
				'complianceStrictMode' => 'compliance_strict_mode',
				'enableViolationNotifications' => 'enable_violation_notifications',
				'breakAutoFallbackEnabled' => 'break_auto_fallback_enabled',
				'breakAutoFallbackMinutes' => 'break_auto_fallback_minutes',
				'breakAutoFallbackFlexWindowStart' => 'break_auto_fallback_flex_window_start',
				'breakAutoFallbackFlexWindowEnd' => 'break_auto_fallback_flex_window_end',
				'missingClockInRemindersEnabled' => 'missing_clock_in_reminders_enabled',
				'exportMidnightSplitEnabled' => 'export_midnight_split_enabled',
				'monthClosureEnabled' => Constants::CONFIG_MONTH_CLOSURE_ENABLED,
				'monthClosureGraceDaysAfterEom' => Constants::CONFIG_MONTH_CLOSURE_GRACE_DAYS_AFTER_EOM,
				'requireSubstituteTypes' => 'require_substitute_types',
				'sendIcalApprovedAbsences' => 'send_ical_approved_absences',
				'sendIcalToSubstitute' => 'send_ical_to_substitute',
				'sendIcalToManagers' => 'send_ical_to_managers',
				'sendEmailSubstitutionRequest' => 'send_email_substitution_request',
				'sendEmailSubstituteApprovedToEmployee' => 'send_email_substitute_approved_to_employee',
				'sendEmailSubstituteApprovedToManager' => 'send_email_substitute_approved_to_manager',
				'maxDailyHours' => 'max_daily_hours',
				'minRestPeriod' => 'min_rest_period',
				'timePickerMinuteStep' => Constants::CONFIG_TIME_PICKER_MINUTE_STEP,
				// 'country' must precede 'germanState' so a combined save
				// validates the region against the country from this request.
				'country' => 'country',
				'germanState' => 'german_state',
				'weeklyAbsoluteMaxHours' => 'weekly_absolute_max_hours',
				'statutoryAutoReseed' => 'statutory_auto_reseed',
				'retentionPeriod' => 'retention_period',
				'defaultWorkingHours' => 'default_working_hours',
				'vacationCarryoverExpiryMonth' => Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH,
				'vacationCarryoverExpiryDay' => Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY,
				'vacationCarryoverMaxDays' => Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS,
				'vacationRolloverEnabled' => Constants::CONFIG_VACATION_ROLLOVER_ENABLED,
				'vacationRolloverIncludeUnusedAnnual' => Constants::CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL,
				'timeEntryChangesRequireApproval' => Constants::CONFIG_TIME_ENTRY_CHANGES_REQUIRE_APPROVAL,
				'manualTimeEntriesRequireApproval' => Constants::CONFIG_MANUAL_TIME_ENTRIES_REQUIRE_APPROVAL,
				'projectCheckIntegrationEnabled' => Constants::CONFIG_PROJECTCHECK_INTEGRATION_ENABLED,
				'offlineStampMaxPastHours' => Constants::CONFIG_OFFLINE_STAMP_MAX_PAST_HOURS,
			];

			$updatedSettings = [];

			// Update each setting if provided
			foreach ($allowedKeys as $paramKey => $configKey) {
				if (!$paramAllowed($paramKey)) {
					continue;
				}
				if (isset($params[$paramKey])) {
					$value = $params[$paramKey];

					// Validate and convert value based on type
					if (in_array($paramKey, [
						'autoComplianceCheck', 'realtimeComplianceCheck', 'complianceStrictMode', 'enableViolationNotifications',
						'breakAutoFallbackEnabled',
						'missingClockInRemindersEnabled',
						'exportMidnightSplitEnabled', 'monthClosureEnabled',
						'sendIcalApprovedAbsences', 'sendIcalToSubstitute', 'sendIcalToManagers',
						'sendEmailSubstitutionRequest', 'sendEmailSubstituteApprovedToEmployee', 'sendEmailSubstituteApprovedToManager',
						'statutoryAutoReseed',
						'vacationRolloverEnabled', 'vacationRolloverIncludeUnusedAnnual',
						'timeEntryChangesRequireApproval', 'manualTimeEntriesRequireApproval',
						'projectCheckIntegrationEnabled',
					], true)) {
						$value = ($value === true || $value === 'true' || $value === '1') ? '1' : '0';
						if ($paramKey === 'projectCheckIntegrationEnabled' && $value === '1' && !$this->isProjectCheckInstalledOnInstance()) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Enable the ProjectCheck app before turning on this connection.'),
							], Http::STATUS_BAD_REQUEST);
						}
					} elseif ($paramKey === 'maxDailyHours' || $paramKey === 'minRestPeriod' || $paramKey === 'defaultWorkingHours') {
						$value = (string)max(0, (float)$value);
						// Validate ranges
						if ($paramKey === 'maxDailyHours' && ((float)$value < 1 || (float)$value > 24)) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Maximum daily hours must be between 1 and 24')
							], Http::STATUS_BAD_REQUEST);
						}
						if ($paramKey === 'minRestPeriod' && ((float)$value < 1 || (float)$value > 24)) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Minimum rest period must be between 1 and 24 hours')
							], Http::STATUS_BAD_REQUEST);
						}
					} elseif ($paramKey === 'retentionPeriod') {
						$value = (string)max(1, min(10, (int)$value));
					} elseif ($paramKey === 'country') {
						$value = strtoupper(trim((string)$value));
						if (!RegionRegistry::isSupportedCountry($value)) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Invalid country code')
							], Http::STATUS_BAD_REQUEST);
						}
					} elseif ($paramKey === 'weeklyAbsoluteMaxHours') {
						$value = ((int)$value === 50) ? '50' : '45';
					} elseif ($paramKey === 'germanState') {
						$value = strtoupper(trim((string)$value));
						if (!RegionRegistry::isValidRegion($value)) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Invalid region code')
							], Http::STATUS_BAD_REQUEST);
						}
						// Re-read country at write time (not only from request params) so a
						// concurrent country-change cannot validate NW against stale DE.
						$targetCountry = isset($params['country'])
							? strtoupper(trim((string)$params['country']))
							: $this->getConfiguredCountry();
						if (!RegionRegistry::isSupportedCountry($targetCountry)) {
							$targetCountry = $this->getConfiguredCountry();
						}
						if (RegionRegistry::countryOf($value) !== $targetCountry) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('The selected region does not belong to the selected country')
							], Http::STATUS_BAD_REQUEST);
						}
					} elseif ($paramKey === 'vacationCarryoverExpiryMonth') {
						$m = max(1, min(12, (int)$value));
						$value = (string)$m;
					} elseif ($paramKey === 'vacationCarryoverExpiryDay') {
						$d = max(1, min(31, (int)$value));
						$value = (string)$d;
					} elseif ($paramKey === 'vacationCarryoverMaxDays') {
						$s = trim((string)$value);
						if ($s === '') {
							$value = '';
						} else {
							$max = (float)str_replace(',', '.', $s);
							$hoursMode = (new \OCA\ArbeitszeitCheck\Service\VacationUnitService($this->config))->isHoursMode();
							$ceiling = $hoursMode ? 4000.0 : 366.0;
							if (!is_finite($max) || $max < 0 || $max > $ceiling) {
								return new JSONResponse([
									'success' => false,
									'error' => $hoursMode
										? $this->l10n->t('Maximum carryover hours must be empty (unlimited) or between 0 and 4000')
										: $this->l10n->t('Maximum carryover days must be empty (unlimited) or between 0 and 366')
								], Http::STATUS_BAD_REQUEST);
							}
							$value = (string)$max;
						}
					} elseif ($paramKey === 'monthClosureGraceDaysAfterEom') {
						$g = max(0, min(90, (int)$value));
						$value = (string)$g;
					} elseif ($paramKey === 'breakAutoFallbackMinutes') {
						$m = max(15, min(720, (int)$value));
						$value = (string)$m;
					} elseif ($paramKey === 'offlineStampMaxPastHours') {
						$hours = (int)$value;
						if (!\OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy::isAllowedPastHours($hours)) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Offline stamp retention must be between 24 and 72 hours'),
							], Http::STATUS_BAD_REQUEST);
						}
						$value = (string)\OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy::normalizePastHours($hours);
					} elseif ($paramKey === 'timePickerMinuteStep') {
						$value = (string)\OCA\ArbeitszeitCheck\Support\TimePickerMinuteStep::normalize((int)$value);
					} elseif ($paramKey === 'breakAutoFallbackFlexWindowStart') {
						$value = (string)max(0, min(23, (int)$value));
					} elseif ($paramKey === 'breakAutoFallbackFlexWindowEnd') {
						$value = (string)max(1, min(24, (int)$value));
					} elseif ($paramKey === 'requireSubstituteTypes') {
						$validTypes = ['vacation', 'sick_leave', 'personal_leave', 'parental_leave', 'special_leave', 'unpaid_leave', 'home_office', 'business_trip'];
						$arr = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : []);
						if (!is_array($arr)) {
							$arr = [];
						}
						$arr = array_values(array_unique(array_filter($arr, function ($t) use ($validTypes) {
							return in_array((string)$t, $validTypes, true);
						})));
						$value = json_encode($arr);
					} else {
						$value = (string)$value;
					}

					$this->appConfig->setAppValueString($configKey, $value);
					$updatedSettings[$paramKey] = $value;
				}
			}

			if ($paramAllowed('datevBeraternummer') && (
				array_key_exists('datevBeraternummer', $params)
				|| array_key_exists('datevMandantennummer', $params)
				|| array_key_exists('datevLohnartNormal', $params)
				|| array_key_exists('datevLohnartUeberstunden', $params)
			)) {
				$berater = array_key_exists('datevBeraternummer', $params)
					? \OCA\ArbeitszeitCheck\Support\DatevOrgCredentials::normalizeDigits($params['datevBeraternummer'])
					: $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_BERATERNUMMER, '');
				$mandant = array_key_exists('datevMandantennummer', $params)
					? \OCA\ArbeitszeitCheck\Support\DatevOrgCredentials::normalizeDigits($params['datevMandantennummer'])
					: $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_MANDANTENNUMMER, '');
				$pairErrors = \OCA\ArbeitszeitCheck\Support\DatevOrgCredentials::validatePair($berater, $mandant);
				if ($pairErrors !== []) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Validation failed'),
						'code' => $pairErrors[0],
						'codes' => $pairErrors,
					], Http::STATUS_BAD_REQUEST);
				}
				$lohnNormal = array_key_exists('datevLohnartNormal', $params)
					? \OCA\ArbeitszeitCheck\Support\DatevOrgCredentials::normalizeDigits($params['datevLohnartNormal'])
					: $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_LOHNART_NORMAL, '1000');
				if ($lohnNormal === '') {
					$lohnNormal = '1000';
				}
				$lohnOt = array_key_exists('datevLohnartUeberstunden', $params)
					? \OCA\ArbeitszeitCheck\Support\DatevOrgCredentials::normalizeDigits($params['datevLohnartUeberstunden'])
					: $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_LOHNART_UEBERSTUNDEN, '2000');
				if ($lohnOt === '') {
					$lohnOt = '2000';
				}
				$lohnErrors = array_merge(
					\OCA\ArbeitszeitCheck\Support\DatevOrgCredentials::validateLohnart($lohnNormal, false),
					\OCA\ArbeitszeitCheck\Support\DatevOrgCredentials::validateLohnart($lohnOt, false)
				);
				if ($lohnErrors !== []) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Validation failed'),
						'code' => $lohnErrors[0],
						'codes' => $lohnErrors,
					], Http::STATUS_BAD_REQUEST);
				}
				$this->appConfig->setAppValueString(Constants::CONFIG_DATEV_BERATERNUMMER, $berater);
				$this->appConfig->setAppValueString(Constants::CONFIG_DATEV_MANDANTENNUMMER, $mandant);
				$this->appConfig->setAppValueString(Constants::CONFIG_DATEV_LOHNART_NORMAL, $lohnNormal);
				$this->appConfig->setAppValueString(Constants::CONFIG_DATEV_LOHNART_UEBERSTUNDEN, $lohnOt);
				$updatedSettings['datevBeraternummer'] = $berater;
				$updatedSettings['datevMandantennummer'] = $mandant;
				$updatedSettings['datevLohnartNormal'] = $lohnNormal;
				$updatedSettings['datevLohnartUeberstunden'] = $lohnOt;
			}

			// Consistency: default region must always belong to the configured
			// country. Runs after a non-empty settings write (not on no-op POSTs)
			// so interleaved region-only + country POSTs cannot leave orphan pairs
			// like country=AT + german_state=NW. Prefer values written in this
			// request — AppConfig mocks (and same-request readers) may not see
			// a just-set key via getAppValueString yet.
			if (!empty($updatedSettings)) {
				$effectiveCountry = isset($updatedSettings['country'])
					? (string)$updatedSettings['country']
					: $this->getConfiguredCountry();
				$currentRegion = isset($updatedSettings['germanState'])
					? strtoupper((string)$updatedSettings['germanState'])
					: strtoupper($this->appConfig->getAppValueString('german_state', ''));
				$resolvedRegion = RegionRegistry::resolveDefaultRegionForCountry($effectiveCountry, $currentRegion);
				if ($resolvedRegion !== $currentRegion) {
					$this->appConfig->setAppValueString('german_state', $resolvedRegion);
					$updatedSettings['germanState'] = $resolvedRegion;
				}
			}
			if (isset($updatedSettings['country']) || isset($updatedSettings['weeklyAbsoluteMaxHours']) || isset($updatedSettings['germanState'])) {
				// Drop request-cached labour-law profile so same-request readers
				// (compliance, capabilities) see the new country / weekly cap immediately.
				$this->clearLaborLawProfileCache();
			}

			if ($accessScopeOk && (
				array_key_exists('accessRestrictionEnabled', $params)
				|| array_key_exists('accessAllowedGroups', $params)
				|| array_key_exists('accessAllowedUserIds', $params)
			)) {
				$restrictionEnabled = array_key_exists('accessRestrictionEnabled', $params)
					? $this->toBool($params['accessRestrictionEnabled'])
					: $this->isAccessRestrictionEnabledFromConfig();

				$groups = $this->getAllowedAccessGroupsFromConfig();
				if (array_key_exists('accessAllowedGroups', $params)) {
					$groupsRaw = $params['accessAllowedGroups'];
					$groupsList = is_array($groupsRaw) ? $groupsRaw : (is_string($groupsRaw) ? json_decode($groupsRaw, true) : []);
					if (!is_array($groupsList)) {
						$groupsList = [];
					}
					$groups = $this->normalizeExistingGroupIds($groupsList);
				}

				$users = $this->getConfiguredAccessAllowedUserIds();
				if (array_key_exists('accessAllowedUserIds', $params)) {
					$userIdsRaw = $params['accessAllowedUserIds'];
					$userIds = is_array($userIdsRaw) ? $userIdsRaw : (is_string($userIdsRaw) ? json_decode($userIdsRaw, true) : []);
					if (!is_array($userIds)) {
						$userIds = [];
					}
					$users = $this->normalizeAppAdminUserIds($userIds);
				}

				$this->appConfig->setAppValueString(Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, $restrictionEnabled ? '1' : '0');
				$this->appConfig->setAppValueString(Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS, json_encode($groups));
				$this->appConfig->setAppValueString(Constants::CONFIG_ACCESS_ALLOWED_USER_IDS, json_encode($users));
				// Open ⇒ clear NC app restriction so the menu shows for everyone.
				// Restricted ⇒ sync group allowlist into NC restriction (users enforced in-app).
				$this->applyAppRestrictionGroups($restrictionEnabled ? $groups : []);

				$updatedSettings['accessRestrictionEnabled'] = $restrictionEnabled;
				$updatedSettings['accessAllowedGroups'] = $groups;
				$updatedSettings['accessAllowedUserIds'] = $users;
			}

			if ($accessScopeOk && array_key_exists('appAdminUserIds', $params)) {
				$userIdsRaw = $params['appAdminUserIds'];
				$userIds = is_array($userIdsRaw) ? $userIdsRaw : (is_string($userIdsRaw) ? json_decode($userIdsRaw, true) : []);
				if (!is_array($userIds)) {
					$userIds = [];
				}
				$normalizedAdminUserIds = $this->normalizeAppAdminUserIds($userIds);
				$actor = $this->userSession->getUser();
				$actorId = $actor !== null ? $actor->getUID() : '';
				// Delegated app admins must not wipe the last seat (incl. themselves)
				// without leaving another delegated admin — Nextcloud system admins
				// always retain break-glass access and may clear the list.
				if (
					$actorId !== ''
					&& !$this->groupManager->isAdmin($actorId)
					&& $this->permissionService->isAdmin($actorId)
				) {
					$removingSelf = !in_array($actorId, $normalizedAdminUserIds, true);
					if ($removingSelf && $normalizedAdminUserIds === []) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('You cannot remove your own app administrator access without assigning another administrator first.'),
						], Http::STATUS_BAD_REQUEST);
					}
				}
				$this->appConfig->setAppValueString(Constants::CONFIG_APP_ADMIN_USER_IDS, json_encode($normalizedAdminUserIds));
				$updatedSettings['appAdminUserIds'] = $normalizedAdminUserIds;
			}

			if ($timeCaptureScopeOk && (array_key_exists('clockStampingEnabled', $params) || array_key_exists('manualTimeEntryEnabled', $params))) {
				try {
					$orgCapture = $this->timeCaptureMethodService->setOrganizationDefaults([
						'clockStampingEnabled' => array_key_exists('clockStampingEnabled', $params)
							? filter_var($params['clockStampingEnabled'], FILTER_VALIDATE_BOOLEAN)
							: $this->timeCaptureMethodService->isOrganizationClockStampingEnabled(),
						'manualTimeEntryEnabled' => array_key_exists('manualTimeEntryEnabled', $params)
							? filter_var($params['manualTimeEntryEnabled'], FILTER_VALIDATE_BOOLEAN)
							: $this->timeCaptureMethodService->isOrganizationManualTimeEntryEnabled(),
					], $this->getPerformedBy());
					$updatedSettings['clockStampingEnabled'] = $orgCapture['clockStampingEnabled'];
					$updatedSettings['manualTimeEntryEnabled'] = $orgCapture['manualTimeEntryEnabled'];
				} catch (BusinessRuleException $e) {
					return new JSONResponse([
						'success' => false,
						'error' => $e->getMessage(),
					], Http::STATUS_BAD_REQUEST);
				}
			}

			if (empty($updatedSettings)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('No valid settings provided')
				], Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse([
				'success' => true,
				'message' => $this->l10n->t('Settings updated successfully'),
				'settings' => $updatedSettings
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function updateNotificationSettings(): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			// Never gate HR on bare `enabled` alone — query/body pollution could wipe HR.
			$hasHrSection = array_key_exists('hrNotificationsEnabled', $params)
				|| array_key_exists('recipients', $params)
				|| array_key_exists('matrix', $params);
			$hasTrafficSection = array_key_exists('overtimeTrafficLightEnabled', $params);
			$hasBankSection = array_key_exists('overtimeBankEnabled', $params);

			// ── Validate all section payloads before any IConfig writes (atomic UX) ──
			$hrWrite = null;
			if ($hasHrSection) {
				$writeHrEnabled = array_key_exists('hrNotificationsEnabled', $params)
					|| array_key_exists('enabled', $params);
				$writeHrRecipients = array_key_exists('recipients', $params);
				$writeHrMatrix = array_key_exists('matrix', $params);
				$enabled = $writeHrEnabled
					? $this->toBool($params['hrNotificationsEnabled'] ?? $params['enabled'] ?? false)
					: null;

				$recipients = [];
				$matrix = [];
				if ($writeHrRecipients) {
					$rawRecipients = $params['recipients'] ?? '';
					$rawRecipientsString = is_array($rawRecipients)
						? implode(',', array_map(static fn (mixed $v): string => (string)$v, $rawRecipients))
						: (string)$rawRecipients;
					if (mb_strlen($rawRecipientsString) > self::MAX_NOTIFICATION_RECIPIENTS_RAW_LENGTH) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Recipient input is too long. Please reduce the number of addresses.'),
						], Http::STATUS_BAD_REQUEST);
					}

					$recipients = $this->normalizeNotificationRecipients($rawRecipients);
					$invalidRecipients = $this->collectInvalidNotificationRecipients($rawRecipients);
					if ($invalidRecipients !== []) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Invalid recipient email: %s', [implode(', ', $invalidRecipients)]),
						], Http::STATUS_BAD_REQUEST);
					}
					if (count($recipients) > self::MAX_NOTIFICATION_RECIPIENTS) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('A maximum of 20 HR recipients is allowed.'),
						], Http::STATUS_BAD_REQUEST);
					}
				}
				if ($writeHrMatrix) {
					$matrix = $this->normalizeNotificationMatrix($params['matrix'] ?? []);
				}

				$effectiveEnabled = $writeHrEnabled
					? (bool)$enabled
					: $this->toBool($this->appConfig->getAppValueString(Constants::CONFIG_HR_NOTIFICATIONS_ENABLED, '0'));
				if ($effectiveEnabled) {
					if ($writeHrRecipients && $recipients === []) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Please enter at least one valid recipient email address.'),
						], Http::STATUS_BAD_REQUEST);
					}
					if ($writeHrEnabled && $enabled === true && !$writeHrRecipients) {
						$existingRecipients = $this->normalizeNotificationRecipients(
							$this->appConfig->getAppValueString(Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS, '')
						);
						if ($existingRecipients === []) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Please enter at least one valid recipient email address.'),
							], Http::STATUS_BAD_REQUEST);
						}
					}
				}

				$hrWrite = [
					'writeEnabled' => $writeHrEnabled,
					'enabled' => $enabled,
					'recipients' => $recipients,
					'matrix' => $matrix,
					'writeRecipients' => $writeHrRecipients,
					'writeMatrix' => $writeHrMatrix,
				];
			}

			$trafficWrite = null;
			if ($hasTrafficSection) {
				$trafficEnabled = $this->toBool($params['overtimeTrafficLightEnabled'] ?? false);
				$writeRecipients = array_key_exists('overtimeRecipients', $params);
				$writeMatrix = array_key_exists('overtimeMatrix', $params);
				$writeThresholds = array_key_exists('overtimeYellowOver', $params)
					|| array_key_exists('overtimeRedOver', $params)
					|| array_key_exists('overtimeYellowUnder', $params)
					|| array_key_exists('overtimeRedUnder', $params);

				$overtimeRecipients = [];
				$overtimeMatrix = [];
				$yellowOver = $redOver = $yellowUnder = $redUnder = null;
				if ($writeRecipients || $trafficEnabled) {
					$overtimeRecipientsRaw = $params['overtimeRecipients'] ?? '';
					$overtimeRecipients = $this->normalizeNotificationRecipients($overtimeRecipientsRaw);
					$overtimeInvalidRecipients = $this->collectInvalidNotificationRecipients($overtimeRecipientsRaw);
					if ($overtimeInvalidRecipients !== []) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Invalid recipient email: %s', [implode(', ', $overtimeInvalidRecipients)]),
						], Http::STATUS_BAD_REQUEST);
					}
					if (count($overtimeRecipients) > self::MAX_NOTIFICATION_RECIPIENTS) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('A maximum of 20 balance traffic light recipients is allowed.'),
						], Http::STATUS_BAD_REQUEST);
					}
					if ($trafficEnabled && $writeRecipients && $overtimeRecipients === []) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Please enter at least one valid balance traffic light recipient email address (overtime/undertime).'),
						], Http::STATUS_BAD_REQUEST);
					}
				}
				if ($writeMatrix) {
					$overtimeMatrix = $this->normalizeOvertimeNotificationMatrix($params['overtimeMatrix'] ?? []);
				}
				if ($writeThresholds) {
					$yellowOver = max(0.0, min(500.0, (float)($params['overtimeYellowOver'] ?? 5)));
					$redOver = max(0.0, min(500.0, (float)($params['overtimeRedOver'] ?? 15)));
					$yellowUnder = max(0.0, min(500.0, (float)($params['overtimeYellowUnder'] ?? 5)));
					$redUnder = max(0.0, min(500.0, (float)($params['overtimeRedUnder'] ?? 15)));
					if ($yellowOver > $redOver || $yellowUnder > $redUnder) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Yellow thresholds must be less than or equal to red thresholds.'),
						], Http::STATUS_BAD_REQUEST);
					}
				}
				$trafficWrite = [
					'enabled' => $trafficEnabled,
					'writeRecipients' => $writeRecipients,
					'writeMatrix' => $writeMatrix,
					'writeThresholds' => $writeThresholds,
					'recipients' => $overtimeRecipients,
					'matrix' => $overtimeMatrix,
					'yellowOver' => $yellowOver,
					'redOver' => $redOver,
					'yellowUnder' => $yellowUnder,
					'redUnder' => $redUnder,
				];
			}

			$bankWrite = null;
			if ($hasBankSection) {
				$bankEnabled = $this->toBool($params['overtimeBankEnabled'] ?? false);
				$writeMax = array_key_exists('overtimeBankMaxHours', $params);
				$writeYellow = array_key_exists('overtimeBankYellowPercent', $params);
				$writeRed = array_key_exists('overtimeBankRedPercent', $params);
				$bankMax = null;
				$bankYellowPct = null;
				$bankRedPct = null;
				if ($writeMax) {
					$bankMaxRaw = (float)str_replace(',', '.', trim((string)($params['overtimeBankMaxHours'] ?? '100')));
					$bankMax = is_finite($bankMaxRaw)
						? max(1.0, min(500.0, round($bankMaxRaw, 2)))
						: 100.0;
				}
				if ($writeYellow) {
					$bankYellowPct = max(0, min(100, (int)$params['overtimeBankYellowPercent']));
				}
				if ($writeRed) {
					$bankRedPct = max(0, min(100, (int)$params['overtimeBankRedPercent']));
				}
				if ($writeYellow && $writeRed && $bankYellowPct > $bankRedPct) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Bank fill yellow percent must be less than or equal to red percent.'),
					], Http::STATUS_BAD_REQUEST);
				}
				$bankWrite = [
					'enabled' => $bankEnabled,
					'writeMax' => $writeMax,
					'writeYellow' => $writeYellow,
					'writeRed' => $writeRed,
					'max' => $bankMax,
					'yellow' => $bankYellowPct,
					'red' => $bankRedPct,
				];
			}

			// Premium / DATEV: validate before any section writes so bank/HR are not half-committed.
			$premiumWrite = null;
			if (array_key_exists('premiumPolicy', $params)) {
				$policyRaw = $params['premiumPolicy'];
				if (!is_array($policyRaw)) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Validation failed'),
						'code' => 'PREMIUM_POLICY_INVALID',
					], Http::STATUS_BAD_REQUEST);
				}
				$errors = \OCA\ArbeitszeitCheck\Support\PremiumPolicy::validate($policyRaw);
				if ($errors !== []) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Validation failed'),
						'code' => $errors[0],
						'codes' => $errors,
					], Http::STATUS_BAD_REQUEST);
				}
				$premiumWrite = \OCA\ArbeitszeitCheck\Support\PremiumPolicy::fromValidated($policyRaw);
			}
			$datevMapWrite = null;
			if (array_key_exists('datevLohnartPremiumMap', $params)) {
				$mapRaw = $params['datevLohnartPremiumMap'];
				$mapErrors = \OCA\ArbeitszeitCheck\Support\DatevPremiumLohnartMap::validate($mapRaw);
				if ($mapErrors !== []) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Validation failed'),
						'code' => $mapErrors[0],
						'codes' => $mapErrors,
					], Http::STATUS_BAD_REQUEST);
				}
				$datevMapWrite = \OCA\ArbeitszeitCheck\Support\DatevPremiumLohnartMap::normalize($mapRaw);
			}

			// Vacation year-mode ack must fail closed before sibling vacation keys write.
			if (isset($params['vacationYearMode'])) {
				$previous = VacationYearWindowResolver::normalizeMode(
					$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_YEAR_MODE, Constants::DEFAULT_VACATION_YEAR_MODE)
				);
				$nextMode = VacationYearWindowResolver::normalizeMode((string)$params['vacationYearMode']);
				if ($nextMode !== $previous && $nextMode === Constants::VACATION_YEAR_MODE_ANNIVERSARY) {
					$missingHire = $this->countUsersMissingEmploymentStart();
					$ack = $params['vacationYearMissingHireAcknowledged'] ?? $params['vacation_year_missing_hire_acknowledged'] ?? false;
					$ackOk = $ack === true || $ack === 'true' || $ack === '1' || $ack === 1;
					if ($missingHire > 0 && !$ackOk) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t(
								'%s people are missing a hire date. Set their employment start under Employees, or confirm you understand they will have no vacation entitlement until then.',
								[(string)$missingHire]
							),
							'code' => Constants::VAC_YEAR_MISSING_HIRE_ACK_REQUIRED,
							'missingHireCount' => $missingHire,
						], Http::STATUS_CONFLICT);
					}
				}
			}

			// Pre-validate carryover max so invalid values cannot half-commit earlier vacation keys.
			if (isset($params['vacationCarryoverMaxDays'])) {
				$s = trim((string)$params['vacationCarryoverMaxDays']);
				if ($s !== '') {
					$max = (float)str_replace(',', '.', $s);
					$hoursMode = (new \OCA\ArbeitszeitCheck\Service\VacationUnitService($this->config))->isHoursMode();
					$ceiling = $hoursMode ? 4000.0 : 366.0;
					if (!is_finite($max) || $max < 0 || $max > $ceiling) {
						return new JSONResponse([
							'success' => false,
							'error' => $hoursMode
								? $this->l10n->t('Maximum carryover hours must be empty (unlimited) or between 0 and 4000')
								: $this->l10n->t('Maximum carryover days must be empty (unlimited) or between 0 and 366')
						], Http::STATUS_BAD_REQUEST);
					}
				}
			}

			// Precompute year-mode flip before any IConfig writes (fail closed on lock busy).
			$yearModeFlip = null;
			if (isset($params['vacationYearMode'])) {
				$previousYearMode = VacationYearWindowResolver::normalizeMode(
					$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_YEAR_MODE, Constants::DEFAULT_VACATION_YEAR_MODE)
				);
				$nextYearMode = VacationYearWindowResolver::normalizeMode((string)$params['vacationYearMode']);
				if ($nextYearMode !== $previousYearMode) {
					$missingHireForFlip = 0;
					if ($nextYearMode === Constants::VACATION_YEAR_MODE_ANNIVERSARY) {
						$missingHireForFlip = $this->countUsersMissingEmploymentStart();
					}
					$yearModeFlip = [
						'previous' => $previousYearMode,
						'next' => $nextYearMode,
						'missing_hire_count' => $missingHireForFlip,
						'missing_hire_acknowledged' => $missingHireForFlip > 0,
					];
				}
			}

			$locking = $this->lockingProvider ?? \OCP\Server::get(\OCP\Lock\ILockingProvider::class);
			$policyLock = \OCA\ArbeitszeitCheck\Service\DbLockKeys::premiumPolicy();
			$yearLock = \OCA\ArbeitszeitCheck\Service\DbLockKeys::vacationYearMode();
			$heldPremiumExclusive = false;
			$heldYearExclusive = false;

			try {
				// Acquire contested locks BEFORE any writes — otherwise a 409 leaves half-saved siblings.
				if ($premiumWrite !== null) {
					try {
						$locking->acquireLock($policyLock, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE, 'Premium policy save');
						$heldPremiumExclusive = true;
					} catch (\OCP\Lock\LockedException $e) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Premium policy is being updated or sealed. Please try again.'),
							'code' => 'PREMIUM_POLICY_BUSY',
						], Http::STATUS_CONFLICT);
					}
				}
				if ($yearModeFlip !== null) {
					try {
						$locking->acquireLock($yearLock, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE, 'Vacation year mode flip');
						$heldYearExclusive = true;
					} catch (\OCP\Lock\LockedException $e) {
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Vacation year mode cannot be changed right now. Wait a moment and try again.'),
							'code' => 'VAC_YEAR_MODE_BUSY',
						], Http::STATUS_CONFLICT);
					}
				}

				$allocationsRefreshed = 0;
				$allocationsFailed = [];

				// Year-mode flip first (still under exclusive + migrate idle shared) so a migrate
				// conflict cannot leave carryover/HR/premium half-committed.
				if ($yearModeFlip !== null) {
					$migration = $this->vacationUnitMigrationService
						?? \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService::class);
					try {
						$refreshResult = $migration->withIdleShared(function () use ($yearModeFlip) {
							$this->appConfig->setAppValueString(
								Constants::CONFIG_VACATION_YEAR_MODE,
								$yearModeFlip['next']
							);
							$userIds = [];
							$this->userManager->callForAllUsers(function (IUser $user) use (&$userIds): void {
								if ($user->isEnabled() !== true) {
									return;
								}
								$uid = $user->getUID();
								if (!$this->permissionService->isUserAllowedByAccessGroups($uid)) {
									return;
								}
								$userIds[] = $uid;
							});
							return $this->vacationAllocationService->refreshOpenAllocationsForUsers($userIds);
						});
					} catch (\RuntimeException $e) {
						if ($e->getMessage() === Constants::VAC_UNIT_MIGRATE_IN_PROGRESS) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Vacation unit migration is in progress. Please try again in a moment.'),
								'code' => Constants::VAC_UNIT_MIGRATE_IN_PROGRESS,
							], Http::STATUS_CONFLICT);
						}
						throw $e;
					}
					$allocationsRefreshed = (int)($refreshResult['refreshed'] ?? 0);
					$allocationsFailed = array_values(array_filter(
						(array)($refreshResult['failed'] ?? []),
						static fn ($id) => is_string($id) && $id !== ''
					));
					$performedBy = $this->getPerformedBy();
					$this->auditLogMapper->logAction(
						$performedBy,
						'vacation_year_mode_changed',
						'app_config',
						0,
						['vacation_year_mode' => $yearModeFlip['previous']],
						[
							'vacation_year_mode' => $yearModeFlip['next'],
							'missing_hire_count' => $yearModeFlip['missing_hire_count'],
							'missing_hire_acknowledged' => $yearModeFlip['missing_hire_acknowledged'],
							'allocations_refreshed' => $allocationsRefreshed,
							'allocations_failed_count' => count($allocationsFailed),
							'allocations_failed' => array_slice($allocationsFailed, 0, 50),
						],
						$performedBy
					);
				}

			// ── Commit phase (sibling settings) ──
			if ($hrWrite !== null) {
				if ($hrWrite['writeEnabled']) {
					$this->appConfig->setAppValueString(
						Constants::CONFIG_HR_NOTIFICATIONS_ENABLED,
						$hrWrite['enabled'] ? '1' : '0'
					);
				}
				if ($hrWrite['writeRecipients']) {
					$this->appConfig->setAppValueString(Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS, implode(',', $hrWrite['recipients']));
				}
				if ($hrWrite['writeMatrix']) {
					$this->appConfig->setAppValueString(Constants::CONFIG_HR_NOTIFICATION_MATRIX_V1, (string)json_encode($hrWrite['matrix']));
				}
			}

			if ($trafficWrite !== null) {
				$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_TRAFFIC_LIGHT_ENABLED, $trafficWrite['enabled'] ? '1' : '0');
				if ($trafficWrite['writeRecipients']) {
					$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_NOTIFICATION_RECIPIENTS, implode(',', $trafficWrite['recipients']));
				}
				if ($trafficWrite['writeMatrix']) {
					$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_NOTIFICATION_MATRIX_V1, (string)json_encode($trafficWrite['matrix']));
				}
				if ($trafficWrite['writeThresholds']) {
					$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_YELLOW_OVER, (string)$trafficWrite['yellowOver']);
					$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_RED_OVER, (string)$trafficWrite['redOver']);
					$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_YELLOW_UNDER, (string)$trafficWrite['yellowUnder']);
					$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_RED_UNDER, (string)$trafficWrite['redUnder']);
				}
			}

			if ($bankWrite !== null) {
				$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_BANK_ENABLED, $bankWrite['enabled'] ? '1' : '0');
				if ($bankWrite['writeMax']) {
					$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_BANK_MAX_HOURS, (string)$bankWrite['max']);
				}
				if ($bankWrite['writeYellow']) {
					$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT, (string)$bankWrite['yellow']);
				}
				if ($bankWrite['writeRed']) {
					$this->appConfig->setAppValueString(Constants::CONFIG_OVERTIME_BANK_RED_PERCENT, (string)$bankWrite['red']);
				}
			}

			if (isset($params['overtimePayoutNotifyInApp'])) {
				$this->appConfig->setAppValueString(
					Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_IN_APP,
					$this->toBool($params['overtimePayoutNotifyInApp']) ? '1' : '0'
				);
			}
			if (isset($params['overtimePayoutNotifyEmail'])) {
				$this->appConfig->setAppValueString(
					Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_EMAIL,
					$this->toBool($params['overtimePayoutNotifyEmail']) ? '1' : '0'
				);
			}
			if (isset($params['overtimeBlockMonthClosurePendingPayout'])) {
				$this->appConfig->setAppValueString(
					Constants::CONFIG_OVERTIME_BLOCK_MONTH_CLOSURE_PENDING_PAYOUT,
					$this->toBool($params['overtimeBlockMonthClosurePendingPayout']) ? '1' : '0'
				);
			}
			if (array_key_exists('paidAbsencePlannedHoursCreditEnabled', $params)) {
				$this->appConfig->setAppValueString(
					Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT,
					$this->toBool($params['paidAbsencePlannedHoursCreditEnabled']) ? '1' : '0'
				);
			}

			$allowedKeys = [
				'missingClockInRemindersEnabled' => 'missing_clock_in_reminders_enabled',
				'sendIcalApprovedAbsences' => 'send_ical_approved_absences',
				'sendIcalToSubstitute' => 'send_ical_to_substitute',
				'sendIcalToManagers' => 'send_ical_to_managers',
				'sendEmailSubstitutionRequest' => 'send_email_substitution_request',
				'sendEmailSubstituteApprovedToEmployee' => 'send_email_substitute_approved_to_employee',
				'sendEmailSubstituteApprovedToManager' => 'send_email_substitute_approved_to_manager',
				'vacationCarryoverExpiryMonth' => Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH,
				'vacationCarryoverExpiryDay' => Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY,
				'vacationCarryoverMaxDays' => Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS,
				'vacationRolloverEnabled' => Constants::CONFIG_VACATION_ROLLOVER_ENABLED,
				'vacationRolloverIncludeUnusedAnnual' => Constants::CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL,
				'vacationProrationMethod' => Constants::CONFIG_VACATION_PRORATION_METHOD,
				'vacationYearMode' => Constants::CONFIG_VACATION_YEAR_MODE,
				'premiumSurchargesEnabled' => Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED,
			];

			// Premium policy JSON is validated separately (not a simple string map).
			// Exclusive lock already held from preflight when $premiumWrite !== null.
			if ($premiumWrite !== null) {
				$this->appConfig->setAppValueString(
					Constants::CONFIG_PREMIUM_POLICY_JSON,
					json_encode($premiumWrite->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
				);
				$ver = (int)$this->appConfig->getAppValueString(Constants::CONFIG_PREMIUM_POLICY_VERSION, '0');
				$this->appConfig->setAppValueString(Constants::CONFIG_PREMIUM_POLICY_VERSION, (string)($ver + 1));
			}

			if ($datevMapWrite !== null) {
				$this->appConfig->setAppValueString(
					Constants::CONFIG_DATEV_LOHNART_PREMIUM_MAP,
					\OCA\ArbeitszeitCheck\Support\DatevPremiumLohnartMap::toJson($datevMapWrite)
				);
			}

			foreach ($allowedKeys as $paramKey => $configKey) {
				if (!isset($params[$paramKey])) {
					continue;
				}

				$value = $params[$paramKey];
				if (in_array($paramKey, [
					'missingClockInRemindersEnabled',
					'sendIcalApprovedAbsences',
					'sendIcalToSubstitute',
					'sendIcalToManagers',
					'sendEmailSubstitutionRequest',
					'sendEmailSubstituteApprovedToEmployee',
					'sendEmailSubstituteApprovedToManager',
					'vacationRolloverEnabled',
					'vacationRolloverIncludeUnusedAnnual',
					'premiumSurchargesEnabled',
				], true)) {
					$value = ($value === true || $value === 'true' || $value === '1') ? '1' : '0';
				} elseif ($paramKey === 'vacationProrationMethod') {
					// Whitelist to the two known methods; anything else falls
					// back to the safe default (full-month Zwölftelung).
					$value = VacationProrationService::normalizeMethod((string)$value);
				} elseif ($paramKey === 'vacationYearMode') {
					// Flip already applied under exclusive lock above; unchanged mode is a no-op write.
					if ($yearModeFlip !== null) {
						continue;
					}
					$value = VacationYearWindowResolver::normalizeMode((string)$value);
				} elseif ($paramKey === 'vacationCarryoverExpiryMonth') {
					$value = (string)max(1, min(12, (int)$value));
				} elseif ($paramKey === 'vacationCarryoverExpiryDay') {
					$value = (string)max(1, min(31, (int)$value));
				} elseif ($paramKey === 'vacationCarryoverMaxDays') {
					$s = trim((string)$value);
					if ($s === '') {
						$value = '';
					} else {
						$max = (float)str_replace(',', '.', $s);
						$hoursMode = (new \OCA\ArbeitszeitCheck\Service\VacationUnitService($this->config))->isHoursMode();
						$ceiling = $hoursMode ? 4000.0 : 366.0;
						if (!is_finite($max) || $max < 0 || $max > $ceiling) {
							return new JSONResponse([
								'success' => false,
								'error' => $hoursMode
									? $this->l10n->t('Maximum carryover hours must be empty (unlimited) or between 0 and 4000')
									: $this->l10n->t('Maximum carryover days must be empty (unlimited) or between 0 and 366')
							], Http::STATUS_BAD_REQUEST);
						}
						$value = (string)$max;
					}
				} else {
					$value = (string)$value;
				}

				$this->appConfig->setAppValueString($configKey, $value);
			}

			$policyScope = (string)($params['policyScope'] ?? '');
			$message = match ($policyScope) {
				'overtime' => $this->l10n->t('Overtime settings updated successfully'),
				'vacation' => $this->l10n->t('Vacation settings updated successfully'),
				default => $this->l10n->t('Notification settings updated successfully'),
			};
			if ($yearModeFlip !== null) {
				if ($allocationsFailed !== []) {
					$message = $this->l10n->t(
						'Vacation year mode updated. Open vacation balances were refreshed for %s people (%s could not be refreshed — check employment data).',
						[(string)$allocationsRefreshed, (string)count($allocationsFailed)]
					);
				} else {
					$message = $this->l10n->t(
						'Vacation year mode updated. Open vacation balances were refreshed for %s people.',
						[(string)$allocationsRefreshed]
					);
				}
			}

			return new JSONResponse([
				'success' => true,
				'message' => $message,
				'settings' => $this->buildNotificationSettingsPayload(),
				'vacationYearModeFlip' => $yearModeFlip === null ? null : [
					'from' => $yearModeFlip['previous'],
					'to' => $yearModeFlip['next'],
					'allocationsRefreshed' => $allocationsRefreshed,
					'allocationsFailedCount' => count($allocationsFailed),
					'missingHireCount' => $yearModeFlip['missing_hire_count'],
				],
			]);
			} finally {
				if ($heldYearExclusive) {
					try {
						$locking->releaseLock($yearLock, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
					} catch (\Throwable) {
						// best-effort
					}
				}
				if ($heldPremiumExclusive) {
					try {
						$locking->releaseLock($policyLock, \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE);
					} catch (\Throwable) {
						// best-effort
					}
				}
			}
		} catch (\Throwable) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Migrate org vacation unit (days ↔ hours) with balance conversion (US-102 / Q3=A / Q8).
	 */
	#[NoAdminRequired]
	public function migrateVacationUnit(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not authenticated')], Http::STATUS_UNAUTHORIZED);
			}
			$userId = $user->getUID();
			if (!$this->permissionService->isAdmin($userId)) {
				$this->permissionService->logPermissionDenied($userId, 'migrate_vacation_unit', 'app_config');
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Access denied')], Http::STATUS_FORBIDDEN);
			}

			$params = $this->request->getParams();
			$target = strtolower(trim((string)($params['targetUnit'] ?? '')));
			$hoursPerDay = (float)str_replace(',', '.', (string)($params['hoursPerDay'] ?? Constants::DEFAULT_VACATION_HOURS_PER_DAY));
			$clientConfirmed = ($params['clientConfirmed'] === true || $params['clientConfirmed'] === 'true' || $params['clientConfirmed'] === '1');

			if ($target !== Constants::VACATION_UNIT_HOURS && $target !== Constants::VACATION_UNIT_DAYS) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Validation failed'),
					'code' => 'VAC_UNIT_INVALID',
				], Http::STATUS_BAD_REQUEST);
			}

			/** @var \OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService $migration */
			$migration = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService::class);
			try {
				$result = $migration->migrate($target, $hoursPerDay, $clientConfirmed, $userId);
			} catch (\RuntimeException $e) {
				if ($e->getMessage() === Constants::VAC_UNIT_CLIENT_GATE) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Confirm that Employee apps are updated before enabling vacation in hours.'),
						'code' => Constants::VAC_UNIT_CLIENT_GATE,
					], Http::STATUS_CONFLICT);
				}
				if ($e->getMessage() === 'VAC_UNIT_MIGRATE_IN_PROGRESS') {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('A vacation unit conversion is already running. Wait a moment and try again.'),
						'code' => 'VAC_UNIT_MIGRATE_IN_PROGRESS',
					], Http::STATUS_CONFLICT);
				}
				throw $e;
			} catch (\InvalidArgumentException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Hours per day must be between 0.25 and 24.'),
					'code' => 'VAC_UNIT_HOURS_PER_DAY_INVALID',
				], Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse([
				'success' => true,
				'message' => $this->l10n->t('Vacation unit converted successfully.'),
				'result' => $result,
				'settings' => $this->buildNotificationSettingsPayload(),
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('migrateVacationUnit failed: ' . $e->getMessage(), ['exception' => $e]);
			if ($this->isVacationUnitSchemaOutdatedError($e)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('The database schema is outdated. Run Nextcloud upgrade (occ upgrade) or update ArbeitszeitCheck, then try again.'),
					'code' => Constants::VAC_UNIT_SCHEMA_OUTDATED,
				], Http::STATUS_CONFLICT);
			}
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function isVacationUnitSchemaOutdatedError(\Throwable $e): bool
	{
		if ($e instanceof \RuntimeException && $e->getMessage() === Constants::VAC_UNIT_SCHEMA_OUTDATED) {
			return true;
		}
		$prev = $e->getPrevious();
		if ($prev instanceof \Throwable && $this->isVacationUnitSchemaOutdatedError($prev)) {
			return true;
		}
		$haystack = strtolower($e->getMessage());
		if (!str_contains($haystack, 'unknown column') && !str_contains($haystack, 'no such column')) {
			return false;
		}

		return str_contains($haystack, 'duration_hours') || str_contains($haystack, 'carryover_hours');
	}

	/**
	 * @return list<string>
	 */
	private function getAllowedAccessGroupsFromConfig(): array
	{
		$raw = trim($this->appConfig->getAppValueString(Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS, ''));
		if ($raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				return $this->normalizeExistingGroupIds($decoded);
			}
		}

		return $this->normalizeExistingGroupIds($this->appManager->getAppRestriction('arbeitszeitcheck'));
	}

	/**
	 * @return list<string>
	 */
	private function getConfiguredAccessAllowedUserIds(): array
	{
		$raw = $this->appConfig->getAppValueString(Constants::CONFIG_ACCESS_ALLOWED_USER_IDS, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		return $this->normalizeAppAdminUserIds($decoded);
	}

	private function isAccessRestrictionEnabledFromConfig(): bool
	{
		$raw = trim($this->appConfig->getAppValueString(Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, ''));
		if ($raw === '0') {
			return false;
		}
		if ($raw === '1') {
			return true;
		}

		return $this->getAllowedAccessGroupsFromConfig() !== []
			|| $this->getConfiguredAccessAllowedUserIds() !== [];
	}

	private function toBool(mixed $value): bool
	{
		return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	private function normalizeNotificationRecipients(mixed $raw): array
	{
		$source = [];
		if (is_array($raw)) {
			$source = $raw;
		} elseif (is_string($raw)) {
			$source = explode(',', $raw);
		}

		$unique = [];
		foreach ($source as $entry) {
			$email = strtolower(trim((string)$entry));
			if ($email === '') {
				continue;
			}
			if (mb_strlen($email) > self::MAX_NOTIFICATION_RECIPIENT_LENGTH) {
				continue;
			}
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				continue;
			}
			$unique[$email] = true;
		}

		return array_keys($unique);
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	private function collectInvalidNotificationRecipients(mixed $raw): array
	{
		$source = [];
		if (is_array($raw)) {
			$source = $raw;
		} elseif (is_string($raw)) {
			$source = explode(',', $raw);
		}

		$invalid = [];
		foreach ($source as $entry) {
			$email = trim((string)$entry);
			if ($email === '') {
				continue;
			}
			if (mb_strlen($email) > self::MAX_NOTIFICATION_RECIPIENT_LENGTH) {
				$invalid[$email] = true;
				continue;
			}
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$invalid[$email] = true;
			}
		}

		return array_keys($invalid);
	}

	/**
	 * @param mixed $rawMatrix
	 * @return array<string, array<string, bool>>
	 */
	private function normalizeNotificationMatrix(mixed $rawMatrix): array
	{
		$matrix = [];
		$input = is_array($rawMatrix) ? $rawMatrix : (is_string($rawMatrix) ? json_decode($rawMatrix, true) : []);
		if (!is_array($input)) {
			$input = [];
		}
		foreach (Constants::ABSENCE_TYPES as $absenceType) {
			$matrix[$absenceType] = [];
			$typeInput = (isset($input[$absenceType]) && is_array($input[$absenceType])) ? $input[$absenceType] : [];
			foreach (Constants::HR_NOTIFICATION_EVENTS as $eventKey) {
				$matrix[$absenceType][$eventKey] = $this->toBool($typeInput[$eventKey] ?? false);
			}
		}

		return $matrix;
	}

	/**
	 * @param mixed $rawMatrix
	 * @return array<string, array<string, bool>>
	 */
	private function normalizeOvertimeNotificationMatrix(mixed $rawMatrix): array
	{
		$matrix = [];
		$input = is_array($rawMatrix) ? $rawMatrix : (is_string($rawMatrix) ? json_decode($rawMatrix, true) : []);
		if (!is_array($input)) {
			$input = [];
		}
		foreach (Constants::OVERTIME_DIRECTIONS as $direction) {
			$matrix[$direction] = [];
			$typeInput = (isset($input[$direction]) && is_array($input[$direction])) ? $input[$direction] : [];
			foreach (Constants::OVERTIME_LEVELS as $level) {
				$matrix[$direction][$level] = $this->toBool($typeInput[$level] ?? false);
			}
		}

		return $matrix;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildNotificationSettingsPayload(): array
	{
		$enabled = $this->appConfig->getAppValueString(Constants::CONFIG_HR_NOTIFICATIONS_ENABLED, '0') === '1';
		$recipients = $this->normalizeNotificationRecipients(
			$this->appConfig->getAppValueString(Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS, '')
		);
		$requireSubstituteJson = $this->appConfig->getAppValueString('require_substitute_types', '[]');
		$requireSubstituteTypes = json_decode($requireSubstituteJson, true);
		if (!is_array($requireSubstituteTypes)) {
			$requireSubstituteTypes = [];
		}
		$decoded = json_decode(
			$this->appConfig->getAppValueString(Constants::CONFIG_HR_NOTIFICATION_MATRIX_V1, '[]'),
			true
		);
		$overtimeDecoded = json_decode(
			$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_NOTIFICATION_MATRIX_V1, '[]'),
			true
		);
		$overtimeRecipients = $this->normalizeNotificationRecipients(
			$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_NOTIFICATION_RECIPIENTS, '')
		);

		return [
			'enabled' => $enabled,
			'recipients' => implode(', ', $recipients),
			'matrix' => $this->normalizeNotificationMatrix($decoded),
			'missingClockInRemindersEnabled' => $this->appConfig->getAppValueString('missing_clock_in_reminders_enabled', '1') === '1',
			'vacationCarryoverExpiryMonth' => max(1, min(12, (int)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3'))),
			'vacationCarryoverExpiryDay' => max(1, min(31, (int)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31'))),
			'vacationCarryoverMaxDays' => $this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS, ''),
			'vacationRolloverEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_VACATION_ROLLOVER_ENABLED, '1') === '1',
			'vacationRolloverIncludeUnusedAnnual' => $this->appConfig->getAppValueString(Constants::CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL, '0') === '1',
			'vacationProrationMethod' => VacationProrationService::normalizeMethod(
				$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_PRORATION_METHOD, Constants::DEFAULT_VACATION_PRORATION_METHOD)
			),
			'vacationYearMode' => VacationYearWindowResolver::normalizeMode(
				$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_YEAR_MODE, Constants::DEFAULT_VACATION_YEAR_MODE)
			),
			'vacationYearMissingHireCount' => $this->countUsersMissingEmploymentStart(),
			'employeesAdminUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.users'),
			'vacationUnit' => (string)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_UNIT, Constants::DEFAULT_VACATION_UNIT) === Constants::VACATION_UNIT_HOURS
				? Constants::VACATION_UNIT_HOURS
				: Constants::VACATION_UNIT_DAYS,
			'vacationHoursPerDay' => (string)$this->appConfig->getAppValueString(
				Constants::CONFIG_VACATION_HOURS_PER_DAY,
				(string)Constants::DEFAULT_VACATION_HOURS_PER_DAY
			),
			'vacationUnitClientConfirmed' => $this->appConfig->getAppValueString(Constants::CONFIG_VACATION_UNIT_CLIENT_CONFIRMED, '0') === '1',
			'requireSubstituteTypes' => $requireSubstituteTypes,
			'sendIcalApprovedAbsences' => $this->appConfig->getAppValueString('send_ical_approved_absences', '1') === '1',
			'sendIcalToSubstitute' => $this->appConfig->getAppValueString('send_ical_to_substitute', '0') === '1',
			'sendIcalToManagers' => $this->appConfig->getAppValueString('send_ical_to_managers', '0') === '1',
			'sendEmailSubstitutionRequest' => $this->appConfig->getAppValueString('send_email_substitution_request', '1') === '1',
			'sendEmailSubstituteApprovedToEmployee' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_employee', '1') === '1',
			'sendEmailSubstituteApprovedToManager' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_manager', '1') === '1',
			'overtimeTrafficLightEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_TRAFFIC_LIGHT_ENABLED, '0') === '1',
			'overtimeRecipients' => implode(', ', $overtimeRecipients),
			'overtimeMatrix' => $this->normalizeOvertimeNotificationMatrix($overtimeDecoded),
			'overtimeYellowOver' => (float)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_YELLOW_OVER, '5'),
			'overtimeRedOver' => (float)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_RED_OVER, '15'),
			'overtimeYellowUnder' => (float)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_YELLOW_UNDER, '5'),
			'overtimeRedUnder' => (float)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_THRESHOLD_RED_UNDER, '15'),
			'overtimeBankEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_BANK_ENABLED, '0') === '1',
			'overtimeBankMaxHours' => (float)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_BANK_MAX_HOURS, '100'),
			'overtimeBankYellowPercent' => (int)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT, '80'),
			'overtimeBankRedPercent' => (int)$this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_BANK_RED_PERCENT, '95'),
			'overtimePayoutNotifyInApp' => $this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_IN_APP, '1') === '1',
			'overtimePayoutNotifyEmail' => $this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_EMAIL, '1') === '1',
			'overtimeBlockMonthClosurePendingPayout' => $this->appConfig->getAppValueString(Constants::CONFIG_OVERTIME_BLOCK_MONTH_CLOSURE_PENDING_PAYOUT, '0') === '1',
			'paidAbsencePlannedHoursCreditEnabled' => $this->appConfig->getAppValueString(
				Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT,
				Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT_DEFAULT
			) === '1',
			'premiumSurchargesEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED, '0') === '1',
			'premiumPolicy' => $this->decodePremiumPolicyForAdmin(),
			'premiumPolicyVersion' => (int)$this->appConfig->getAppValueString(Constants::CONFIG_PREMIUM_POLICY_VERSION, '0'),
			'datevLohnartPremiumMap' => \OCA\ArbeitszeitCheck\Support\DatevPremiumLohnartMap::fromJson(
				$this->appConfig->getAppValueString(Constants::CONFIG_DATEV_LOHNART_PREMIUM_MAP, '')
			),
		];
	}

	/**
	 * How many enabled, access-group-allowed users lack employment_start (Bachus A4).
	 */
	private function countUsersMissingEmploymentStart(): int
	{
		$count = 0;
		$this->userManager->callForAllUsers(function (IUser $user) use (&$count): void {
			if ($user->isEnabled() !== true) {
				return;
			}
			$uid = $user->getUID();
			if (!$this->permissionService->isUserAllowedByAccessGroups($uid)) {
				return;
			}
			if ($this->userEmploymentSettingsService->getEmploymentStart($uid) === null) {
				$count++;
			}
		});

		return $count;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodePremiumPolicyForAdmin(): array
	{
		$raw = $this->appConfig->getAppValueString(Constants::CONFIG_PREMIUM_POLICY_JSON, '');
		if ($raw === '') {
			return \OCA\ArbeitszeitCheck\Support\PremiumPolicy::atStarterPreset();
		}
		try {
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return \OCA\ArbeitszeitCheck\Support\PremiumPolicy::atStarterPreset();
		}
		if (!is_array($decoded)) {
			return \OCA\ArbeitszeitCheck\Support\PremiumPolicy::atStarterPreset();
		}
		$policy = \OCA\ArbeitszeitCheck\Support\PremiumPolicy::tryFromArray($decoded);

		return $policy !== null ? $policy->toArray() : \OCA\ArbeitszeitCheck\Support\PremiumPolicy::atStarterPreset();
	}

	/**
	 * @return list<array{key: string, label: string}>
	 */
	private function getNotificationAbsenceTypes(): array
	{
		return [
			['key' => 'vacation', 'label' => $this->l10n->t('Vacation')],
			['key' => 'sick_leave', 'label' => $this->l10n->t('Sick Leave')],
			['key' => 'personal_leave', 'label' => $this->l10n->t('Personal Leave')],
			['key' => 'parental_leave', 'label' => $this->l10n->t('Parental Leave')],
			['key' => 'special_leave', 'label' => $this->l10n->t('Special Leave')],
			['key' => 'unpaid_leave', 'label' => $this->l10n->t('Unpaid Leave')],
			['key' => 'home_office', 'label' => $this->l10n->t('Home Office')],
			['key' => 'business_trip', 'label' => $this->l10n->t('Business Trip')],
		];
	}

	/**
	 * @return list<array{key: string, label: string}>
	 */
	private function getNotificationEventTypes(): array
	{
		return [
			['key' => 'request_created', 'label' => $this->l10n->t('Request created')],
			['key' => 'substitute_approved', 'label' => $this->l10n->t('Substitute approved')],
			['key' => 'substitute_declined', 'label' => $this->l10n->t('Substitute declined')],
			['key' => 'manager_approved', 'label' => $this->l10n->t('Manager approved')],
			['key' => 'manager_rejected', 'label' => $this->l10n->t('Manager rejected')],
			['key' => 'employee_cancelled', 'label' => $this->l10n->t('Employee cancelled')],
			['key' => 'employee_shortened', 'label' => $this->l10n->t('Employee shortened')],
		];
	}

	/**
	 * @return list<string>
	 */
	private function getConfiguredAppAdminUserIds(): array
	{
		$raw = $this->appConfig->getAppValueString(Constants::CONFIG_APP_ADMIN_USER_IDS, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		return $this->normalizeAppAdminUserIds($decoded);
	}

	/**
	 * @param array<mixed> $userIds
	 * @return list<string>
	 */
	private function normalizeAppAdminUserIds(array $userIds): array
	{
		$unique = [];
		foreach ($userIds as $userId) {
			$candidate = trim((string)$userId);
			if ($candidate === '' || isset($unique[$candidate]) || strlen($candidate) > 64) {
				continue;
			}
			$user = $this->userManager->get($candidate);
			if ($user === null || !$user->isEnabled()) {
				continue;
			}
			$unique[$candidate] = true;
		}

		return array_keys($unique);
	}

	/**
	 * @param array<mixed> $groupIds
	 * @return list<string>
	 */
	private function normalizeExistingGroupIds(array $groupIds): array
	{
		$unique = [];
		foreach ($groupIds as $groupId) {
			$candidate = trim((string)$groupId);
			if ($candidate === '' || isset($unique[$candidate])) {
				continue;
			}
			if ($this->groupManager->get($candidate) === null) {
				continue;
			}
			$unique[$candidate] = true;
		}

		return array_keys($unique);
	}

	/**
	 * @param list<string> $groupIds
	 */
	private function applyAppRestrictionGroups(array $groupIds): void
	{
		if ($groupIds === []) {
			$this->appManager->enableApp('arbeitszeitcheck');
			return;
		}
		$groups = [];
		foreach ($groupIds as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group !== null) {
				$groups[] = $group;
			}
		}
		$this->appManager->enableAppForGroups('arbeitszeitcheck', $groups);
	}

	/**
	 * @return list<array{id: string, displayName: string}>
	 */
	private function getAvailableGroupsForAccessControl(): array
	{
		$out = [];
		try {
			$groups = $this->groupManager->search('');
			foreach ($groups as $group) {
				$gid = (string)$group->getGID();
				if ($gid === '') {
					continue;
				}
				$displayName = trim((string)$group->getDisplayName());
				$out[] = [
					'id' => $gid,
					'displayName' => $displayName !== '' ? $displayName : $gid,
				];
			}
		} catch (\Throwable) {
			return [];
		}

		usort($out, static function (array $a, array $b): int {
			return strcasecmp($a['displayName'], $b['displayName']);
		});
		return $out;
	}

	/**
	 * Dedicated + Nextcloud admins for the App Admin picker (portfolio §2.1).
	 *
	 * @return list<array{id: string, displayName: string}>
	 */
	private function getAvailableAppAdminsForAccessControl(): array
	{
		$byId = [];
		foreach ($this->getConfiguredAppAdminUserIds() as $userId) {
			$user = $this->userManager->get($userId);
			$displayName = $user !== null ? trim((string)$user->getDisplayName()) : '';
			$byId[$userId] = [
				'id' => $userId,
				'displayName' => $displayName !== '' ? $displayName : $userId,
			];
		}
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup !== null) {
			foreach ($adminGroup->getUsers() as $adminUser) {
				$userId = trim((string)$adminUser->getUID());
				if ($userId === '' || isset($byId[$userId])) {
					continue;
				}
				$displayName = trim((string)$adminUser->getDisplayName());
				$byId[$userId] = [
					'id' => $userId,
					'displayName' => $displayName !== '' ? $displayName : $userId,
				];
			}
		}

		$out = array_values($byId);
		usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}

	/**
	 * Currently allow-listed users for the access-door picker.
	 *
	 * @return list<array{id: string, displayName: string}>
	 */
	private function getAvailableAccessUsersForAccessControl(): array
	{
		$byId = [];
		foreach ($this->getConfiguredAccessAllowedUserIds() as $userId) {
			$user = $this->userManager->get($userId);
			$displayName = $user !== null ? trim((string)$user->getDisplayName()) : '';
			$byId[$userId] = [
				'id' => $userId,
				'displayName' => $displayName !== '' ? $displayName : $userId,
			];
		}
		$out = array_values($byId);
		usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}


	/**
	 * Get admin dashboard statistics (admin-only by default)
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getStatistics(): JSONResponse
	{
		try {
			// Get total users count
			$totalUsers = $this->userManager->countUsersTotal(0, false);
			if ($totalUsers === false) {
				$totalUsers = 0;
			}

			// Get active users today (users with time entries today)
			$today = new \DateTime();
			$activeUsersToday = $this->timeEntryMapper->countDistinctUsersByDate($today);

			// Get system compliance status (unresolved violations count)
			$unresolvedCount = $this->violationMapper->count(['resolved' => false]);

			// Calculate compliance percentage (users with no unresolved violations)
			// Use findUnresolved to get distinct users with violations (limited to avoid memory issues)
			// For large installations, we'll use a more efficient approach
			$unresolvedViolations = $this->violationMapper->findUnresolved(1000); // Limit to 1000 for performance
			$usersWithViolations = [];
			foreach ($unresolvedViolations as $violation) {
				$userId = $violation->getUserId();
				if (!isset($usersWithViolations[$userId])) {
					$usersWithViolations[$userId] = true;
				}
			}
			$usersWithViolationsCount = count($usersWithViolations);
			// If we hit the limit, estimate that there might be more users with violations
			// For accuracy, we'd need to query distinct user_ids, but this is a reasonable approximation
			$compliantUsers = max(0, $totalUsers - $usersWithViolationsCount);
			$compliancePercentage = $totalUsers > 0 ? round(($compliantUsers / $totalUsers) * 100, 1) : 100.0;

			$withOvertimeTracking = $this->userOvertimeSettingsService->countUsersWithTrackingFrom();
			$withoutOvertimeTracking = max(0, $totalUsers - $withOvertimeTracking);

			return new JSONResponse([
				'success' => true,
				'statistics' => [
					'total_users' => $totalUsers,
					'active_users_today' => $activeUsersToday,
					'unresolved_violations' => $unresolvedCount,
					'compliance_percentage' => $compliancePercentage,
					'compliant_users' => $compliantUsers,
					'overtime_tracking' => [
						'users_with_tracking_from' => $withOvertimeTracking,
						'users_without_tracking_from' => $withoutOvertimeTracking,
						'show_onboarding_hint' => $totalUsers > 0 && $withoutOvertimeTracking > 0,
					],
				]
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Lightweight employee list for admin dashboard drill-down modals.
	 *
	 * Performance considerations:
	 * - Time-entry and overtime-setting lookups are resolved through bulk-built
	 *   in-memory hash maps to avoid N+1 round-trips even on installs with
	 *   thousands of users.
	 * - The number of candidate users scanned is hard-capped at
	 *   {@see self::DASHBOARD_EMPLOYEES_MAX_SCAN} so this endpoint never
	 *   degrades into an unbounded query for very large directories. Clients
	 *   are expected to narrow further via the `search` parameter when the
	 *   `truncated` flag in the response is set.
	 *
	 * Security:
	 * - App-admin only (`#[NoAdminRequired]` + AppAdminMiddleware).
	 * - `#[NoCSRFRequired]` is set because this is a GET endpoint used both by
	 *   the modal (AJAX with auth cookie) and the CSV download (top-level
	 *   navigation), neither of which can attach a CSRF token portably.
	 * - The CSV output is hardened against formula injection
	 *   ({@see self::sanitizeCsvCellValue()}); display names and emails are
	 *   user-controlled in Nextcloud.
	 *
	 * @param string $filter `all` (default) or `active_today`
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getDashboardEmployees(
		string $filter = 'all',
		?string $search = null,
		?int $limit = 100,
		?int $offset = 0,
		?string $format = null,
	): JSONResponse|DataDownloadResponse {
		try {
			$filter = strtolower(trim($filter));
			if (!in_array($filter, ['all', 'active_today'], true)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid filter.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$format = $format !== null ? strtolower(trim($format)) : null;
			if ($format !== null && !in_array($format, ['', 'csv'], true)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid export format.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$normalizedLimit = max(1, min((int)($limit ?? 100), Constants::MAX_LIST_LIMIT));
			$normalizedOffset = max(0, (int)($offset ?? 0));
			$search = $search !== null ? trim($search) : '';
			if (mb_strlen($search) > self::DASHBOARD_EMPLOYEES_MAX_SEARCH_LENGTH) {
				$search = mb_substr($search, 0, self::DASHBOARD_EMPLOYEES_MAX_SEARCH_LENGTH);
			}

			$today = new \DateTime();
			$today->setTime(0, 0, 0);
			$activeTodayIds = $this->timeEntryMapper->findDistinctUserIdsByDate($today);
			$activeTodayLookup = array_fill_keys($activeTodayIds, true);
			$trackingFromLookup = array_fill_keys(
				$this->userOvertimeSettingsService->listUserIdsWithTrackingFrom(),
				true
			);

			$scanCap = self::DASHBOARD_EMPLOYEES_MAX_SCAN;
			if ($search !== '') {
				// Match by user id OR display name (issue #14) before the
				// in-PHP filter/sort/paginate below.
				$candidateUsers = UserDirectorySearch::mergeUnique(
					$this->userManager->search($search, $scanCap + 1, 0),
					$this->userManager->searchDisplayName($search, $scanCap + 1, 0),
				);
			} else {
				$candidateUsers = $this->userManager->search($search, $scanCap + 1, 0);
			}
			$truncated = count($candidateUsers) > $scanCap;
			if ($truncated) {
				$candidateUsers = array_slice($candidateUsers, 0, $scanCap);
			}

			$rows = [];
			foreach ($candidateUsers as $user) {
				$userId = (string)$user->getUID();
				$isActiveToday = isset($activeTodayLookup[$userId]);
				if ($filter === 'active_today' && !$isActiveToday) {
					continue;
				}
				$rows[] = [
					'userId' => $userId,
					'displayName' => (string)$user->getDisplayName(),
					'email' => $user->getEMailAddress() ?? '',
					'enabled' => $user->isEnabled(),
					'hasTimeEntriesToday' => $isActiveToday,
					'hasOvertimeTrackingFrom' => isset($trackingFromLookup[$userId]),
				];
			}

			usort($rows, static function (array $a, array $b): int {
				$displayNameComparison = strcasecmp((string)$a['displayName'], (string)$b['displayName']);
				if ($displayNameComparison !== 0) {
					return $displayNameComparison;
				}
				// Secondary key keeps pagination deterministic for duplicate names.
				return strcmp((string)$a['userId'], (string)$b['userId']);
			});

			$total = count($rows);
			$page = array_slice($rows, $normalizedOffset, $normalizedLimit);

			if ($format === 'csv') {
				$exportRows = array_map(static function (array $row): array {
					return [
						'user_id' => self::sanitizeCsvCellValue((string)$row['userId']),
						'display_name' => self::sanitizeCsvCellValue((string)$row['displayName']),
						'email' => self::sanitizeCsvCellValue((string)$row['email']),
						'enabled' => $row['enabled'] ? 'yes' : 'no',
						'active_today' => $row['hasTimeEntriesToday'] ? 'yes' : 'no',
						'overtime_tracking_from_set' => $row['hasOvertimeTrackingFrom'] ? 'yes' : 'no',
					];
				}, $rows);

				$filename = 'employees-' . $filter . '-' . date('Y-m-d') . '.csv';
				return $this->exportAsCsv($exportRows, $filename);
			}

			return new JSONResponse([
				'success' => true,
				'filter' => $filter,
				'employees' => $page,
				'total' => $total,
				'limit' => $normalizedLimit,
				'offset' => $normalizedOffset,
				'truncated' => $truncated,
				'scan_cap' => $scanCap,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in AdminController::getDashboardEmployees: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Mitigate CSV-formula injection for downstream spreadsheet consumers.
	 *
	 * Excel / LibreOffice interpret cells starting with `=`, `+`, `-`, `@`,
	 * or control characters as formulas. Some clients also ignore leading
	 * whitespace before formula markers. Prefixing such values with a single
	 * quote neutralises them without changing the visible text once imported.
	 */
	private static function sanitizeCsvCellValue(string $value): string
	{
		if ($value === '') {
			return $value;
		}
		if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
			return "'" . $value;
		}
		$first = $value[0];
		if ($first === "\t" || $first === "\r" || $first === "\n") {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Get list of all users with their working time models
	 *
	 * @param string|null $search Search query
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getUsers(?string $search = null, ?int $limit = 50, ?int $offset = 0): JSONResponse
	{
		try {
			$pickerMode = $this->request->getParam('picker') === '1'
				|| $this->request->getParam('picker') === 'true';
			if ($pickerMode) {
				return $this->getUsersForPicker($search, $limit);
			}

			$activeTodayOnly = $this->request->getParam('active_today') === '1'
				|| $this->request->getParam('active_today') === 'true';

			$normalizedLimit = max(1, min((int)($limit ?? 50), Constants::MAX_LIST_LIMIT));
			$normalizedOffset = max(0, (int)($offset ?? 0));
			$searchTerm = $search !== null ? trim($search) : '';
			if (mb_strlen($searchTerm) > self::DASHBOARD_EMPLOYEES_MAX_SEARCH_LENGTH) {
				$searchTerm = mb_substr($searchTerm, 0, self::DASHBOARD_EMPLOYEES_MAX_SEARCH_LENGTH);
			}

			$filterParam = $this->request->getParam('filter');
			$filterRaw = is_string($filterParam) && trim($filterParam) !== '' ? trim($filterParam) : null;

			$listResult = $this->adminEmployeeDirectoryService->listUsers(
				$filterRaw,
				$searchTerm,
				$normalizedLimit,
				$normalizedOffset,
				$activeTodayOnly,
			);

			$usersData = [];
			foreach ($listResult['users'] as $user) {
				try {
					$usersData[] = $this->buildAdminUserApiPayload($user);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error building admin user payload: ' . $e->getMessage(), [
						'exception' => $e,
					]);
				}
			}

			$response = [
				'success' => true,
				'users' => $usersData,
				'total' => $listResult['total'],
				'limit' => $listResult['limit'],
				'offset' => $listResult['offset'],
				'truncated' => $listResult['truncated'],
				'filter' => $listResult['filter'],
				'defaultFilter' => $listResult['defaultFilter'],
				'activeToday' => $listResult['activeToday'],
			];
			if ($listResult['directoryTotal'] !== null) {
				$response['directoryTotal'] = $listResult['directoryTotal'];
			}
			if ($listResult['hiddenCount'] !== null) {
				$response['hiddenCount'] = $listResult['hiddenCount'];
			}

			return new JSONResponse($response);
		} catch (InvalidEmployeeListFilterException $e) {
			return new JSONResponse([
				'success' => false,
				'code' => InvalidEmployeeListFilterException::CODE,
				'error' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::getUsers: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Lightweight user list for combobox pickers (teams, filters, etc.).
	 *
	 * Triggered via {@see getUsers()} when query param {@code picker=1} is set.
	 * Does not load vacation entitlements or working-time-model joins per user.
	 *
	 * @param string|null $search
	 * @param int|null $limit
	 */
	private function getUsersForPicker(?string $search, ?int $limit): JSONResponse
	{
		$searchTerm = $search !== null ? trim($search) : trim((string)($this->request->getParam('search') ?? ''));
		if (mb_strlen($searchTerm) > self::DASHBOARD_EMPLOYEES_MAX_SEARCH_LENGTH) {
			$searchTerm = mb_substr($searchTerm, 0, self::DASHBOARD_EMPLOYEES_MAX_SEARCH_LENGTH);
		}

		$requestLimit = $this->request->getParam('limit');
		$normalizedLimit = max(1, min((int)($requestLimit ?? $limit ?? 20), Constants::PICKER_MAX_RESULTS));

		if (mb_strlen($searchTerm) < Constants::PICKER_MIN_SEARCH_LENGTH) {
			return new JSONResponse([
				'success' => true,
				'users' => [],
				'picker' => true,
				'limit' => $normalizedLimit,
				'requiresMinSearch' => Constants::PICKER_MIN_SEARCH_LENGTH,
			]);
		}

		// People already assigned (members/managers of this team) are excluded
		// server-side so a heavily-staffed unit cannot fill the whole capped
		// page with already-assigned people and hide everyone still available.
		$excludeUserIds = $this->readExcludeUserIdsParam();

		// Match by user id OR display name (issue #14): admins search by name,
		// but many instances key accounts by email/UUID/employee number.
		$result = UserDirectorySearch::searchByIdOrName(
			$this->userManager,
			$searchTerm,
			$normalizedLimit,
			0,
			true,
			$excludeUserIds,
		);

		$usersData = array_map(static function (IUser $user): array {
			return [
				'userId' => (string)$user->getUID(),
				'displayName' => (string)$user->getDisplayName(),
			];
		}, $result['users']);

		return new JSONResponse([
			'success' => true,
			'users' => $usersData,
			'picker' => true,
			'limit' => $normalizedLimit,
			'truncated' => $result['truncated'],
		]);
	}

	/**
	 * Read the optional `exclude`/`exclude[]` query parameter for people
	 * pickers (already-assigned user ids). Bounded to keep the request safe.
	 *
	 * @return list<string>
	 */
	private function readExcludeUserIdsParam(): array
	{
		$raw = $this->request->getParam('exclude');
		if ($raw === null || $raw === '') {
			return [];
		}
		if (is_string($raw)) {
			$raw = [$raw];
		}
		if (!is_array($raw)) {
			return [];
		}
		$ids = [];
		foreach ($raw as $value) {
			$id = trim((string)$value);
			if ($id !== '') {
				$ids[$id] = true;
			}
			if (count($ids) >= Constants::MAX_LIST_LIMIT) {
				break;
			}
		}
		return array_keys($ids);
	}

	/**
	 * Get user details including working time model
	 *
	 * @param string $userId
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getUser(string $userId): JSONResponse
	{
		try {
			$user = $this->userManager->get($userId);
			if (!$user) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not found')
				], Http::STATUS_NOT_FOUND);
			}

			// Resolve the assignment the edit dialog will edit in place. This
			// matches AdminUserProfileUpdateService so a future-dated or ended
			// assignment is shown (and updated), instead of appearing empty and
			// then being duplicated on save.
			$currentModel = $this->userWorkingTimeModelMapper->findEditableByUser($userId);

			// Get working time model details if assigned
			$workingTimeModel = null;
			if ($currentModel) {
				try {
					$workingTimeModel = $this->workingTimeModelMapper->find($currentModel->getWorkingTimeModelId());
				} catch (\Throwable $e) {
					// Model might have been deleted
				}
			}

			// Get all available working time models
			$allModels = $this->workingTimeModelMapper->findAll();

			// Resolve holiday region for this user: per-user setting falls back
			// to the country-aware organisation default (never hardcode NW —
			// AT/CH instances must surface AT-W / CH-ZH when config is empty).
			$defaultState = $this->getConfiguredDefaultRegion();
			$userGermanState = $this->userSettingsMapper->getStringSetting($userId, 'german_state', $defaultState);
			$userLaborLawCountry = $this->userSettingsMapper->getStringSetting(
				$userId,
				LaborLawProfileFactory::USER_SETTING_LABOR_LAW_COUNTRY,
				''
			);

			$startDate = $currentModel ? $currentModel->getStartDate() : null;
			$endDate = $currentModel ? $currentModel->getEndDate() : null;
			$currentYear = (int)date('Y');
			$carryoverYear = $this->resolveAdminBalanceYearParam('carryoverYear');
			$overtimeOpeningYear = $this->resolveAdminBalanceYearParam('overtimeOpeningBalanceYear');
			if ($carryoverYear === null) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid year for vacation carryover'),
				], Http::STATUS_BAD_REQUEST);
			}
			if ($overtimeOpeningYear === null) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Opening balance year must be between 2000 and 2100'),
				], Http::STATUS_BAD_REQUEST);
			}
			$policy = $this->findVacationPolicyForAdminEdit($userId, $startDate);
			$entitlementPreview = $this->vacationEntitlementEngine->computeForDate($userId, new \DateTimeImmutable('today'));
			$entitlementFullDays = round((float)$entitlementPreview['days'], 2);
			$entitlementProration = $this->vacationProrationService->prorateForYear($userId, $currentYear, $entitlementFullDays);

			return new JSONResponse([
				'success' => true,
				'user' => [
					'userId' => $userId,
					'displayName' => $user->getDisplayName(),
					'email' => $user->getEMailAddress(),
					'enabled' => $user->isEnabled(),
					'vacationCarryoverDays' => $this->presentAdminVacationDays(
						(float)$this->vacationYearBalanceMapper->getCarryoverDays($userId, $carryoverYear)
					),
					'vacationCarryoverYear' => $carryoverYear,
					'overtimeTrackingFrom' => $this->userOvertimeSettingsService->getTrackingFrom($userId)?->format('Y-m-d'),
					'overtimeOpeningBalanceHours' => $this->userOvertimeSettingsService->getOpeningBalanceHours($userId, $overtimeOpeningYear),
					'overtimeOpeningBalanceYear' => $overtimeOpeningYear,
					'employmentStart' => $this->userEmploymentSettingsService->getEmploymentStart($userId)?->format('Y-m-d'),
					'employmentEnd' => $this->userEmploymentSettingsService->getEmploymentEnd($userId)?->format('Y-m-d'),
					'datevPersonalnummer' => $this->config !== null
						? (string)$this->config->getUserValue(
							$userId,
							'arbeitszeitcheck',
							Constants::USER_DATEV_PERSONALNUMMER,
							''
						)
						: '',
					'workingTimeModel' => $workingTimeModel ? [
						'id' => $workingTimeModel->getId(),
						'name' => $workingTimeModel->getName(),
						'type' => $workingTimeModel->getType(),
						'weeklyHours' => $workingTimeModel->getWeeklyHours(),
						'dailyHours' => $workingTimeModel->getDailyHours(),
						'workDaysPerWeek' => $workingTimeModel->getWorkDaysPerWeek(),
					] : null,
					'vacationDaysPerYear' => $currentModel
						? $this->presentAdminVacationDays((float)$currentModel->getVacationDaysPerYear())
						: null,
					'workingTimeModelStartDate' => $startDate ? $startDate->format('Y-m-d') : null,
					'workingTimeModelEndDate' => $endDate ? $endDate->format('Y-m-d') : null,
					'germanState' => $userGermanState,
					'laborLawCountry' => RegionRegistry::isSupportedCountry($userLaborLawCountry)
						? strtoupper($userLaborLawCountry)
						: '',
					'instanceCountry' => $this->getConfiguredCountry(),
					'userWorkingTimeModel' => $currentModel ? $this->presentUserWorkingTimeModelSummary($currentModel->getSummary()) : null,
					'vacationPolicy' => $policy ? [
						'id' => $policy->getId(),
						'vacationMode' => $policy->getVacationMode(),
						'manualDays' => $this->presentAdminVacationDays(
							$policy->getManualDays() !== null ? (float)$policy->getManualDays() : null
						),
						'tariffRuleSetId' => $policy->getTariffRuleSetId(),
						'overrideReason' => $policy->getOverrideReason(),
						'effectiveFrom' => $policy->getEffectiveFrom()?->format('Y-m-d'),
						'effectiveTo' => $policy->getEffectiveTo()?->format('Y-m-d'),
						'inheritLowerLayers' => $policy->isInherit(),
					] : null,
					'entitlementPreview' => [
						'days' => (float)$entitlementProration['days'],
						'fullYearDays' => $entitlementFullDays,
						'prorated' => (bool)$entitlementProration['prorated'],
						'prorationMethod' => $entitlementProration['method'],
						'monthsCovered' => $entitlementProration['months_covered'],
						'employedInYear' => (bool)($entitlementProration['employed_in_year'] ?? true),
						'source' => $entitlementPreview['source'],
						'ruleSetId' => $entitlementPreview['ruleSetId'],
						'calculationTrace' => $entitlementPreview['trace'],
						'prorationTrace' => $entitlementProration,
					],
					'timeCapture' => array_merge(
						$this->timeCaptureMethodService->getSettings($userId),
						['preferences' => $this->timeCaptureMethodService->getUserPreferences($userId)],
					),
					'organizationTimeCapture' => $this->timeCaptureMethodService->getOrganizationDefaults(),
					'availableWorkingTimeModels' => array_map(function ($model) {
						return [
							'id' => $model->getId(),
							'name' => $model->getName(),
							'type' => $model->getType(),
							'weeklyHours' => $model->getWeeklyHours(),
							'dailyHours' => $model->getDailyHours(),
							'workDaysPerWeek' => $model->getWorkDaysPerWeek(),
						];
					}, $allModels)
				]
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update user working time model assignment
	 *
	 * @param string $userId
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function updateUserWorkingTimeModel(string $userId): JSONResponse
	{
		try {
			$data = $this->adminUserProfileUpdateService->applyWorkingTimeModel(
				$userId,
				$this->request->getParams(),
				$this->getPerformedBy()
			);
			return new JSONResponse(array_merge(['success' => true], $data));
		} catch (AdminUserProfileUpdateException $e) {
			return $this->profileUpdateExceptionResponse($e);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('updateUserWorkingTimeModel failed', ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get assignment history for a user (all past and current work schedule assignments)
	 *
	 * @param string $userId
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getUserAssignmentHistory(string $userId): JSONResponse
	{
		try {
			$user = $this->userManager->get($userId);
			if (!$user) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not found')
				], Http::STATUS_NOT_FOUND);
			}

			$assignments = $this->userWorkingTimeModelMapper->findByUser($userId);
			$history = [];
			foreach ($assignments as $assignment) {
				$modelName = null;
				try {
					$model = $this->workingTimeModelMapper->find($assignment->getWorkingTimeModelId());
					$modelName = $model->getName();
				} catch (\Throwable $e) {
					$modelName = $this->l10n->t('Unknown (deleted)');
				}
				$startDate = $assignment->getStartDate();
				$endDate = $assignment->getEndDate();
				$history[] = [
					'id' => $assignment->getId(),
					'workingTimeModelId' => $assignment->getWorkingTimeModelId(),
					'modelName' => $modelName,
					'vacationDaysPerYear' => $assignment->getVacationDaysPerYear(),
					'startDate' => $startDate ? $startDate->format('Y-m-d') : null,
					'endDate' => $endDate ? $endDate->format('Y-m-d') : null,
					'isActive' => $assignment->isActive(),
				];
			}

			return new JSONResponse([
				'success' => true,
				'history' => $history
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get all available working time models (admin-only by default)
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getWorkingTimeModels(): JSONResponse
	{
		try {
			$models = $this->workingTimeModelMapper->findAll();

			return new JSONResponse([
				'success' => true,
				'models' => array_map(function ($model) {
					return [
						'id' => $model->getId(),
						'name' => $model->getName(),
						'description' => $model->getDescription(),
						'type' => $model->getType(),
						'weeklyHours' => $model->getWeeklyHours(),
						'dailyHours' => $model->getDailyHours(),
						'workDaysPerWeek' => $model->getWorkDaysPerWeek(),
						'isDefault' => $model->getIsDefault()
					];
				}, $models)
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get working time model by ID
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getWorkingTimeModel(int $id): JSONResponse
	{
		try {
			$model = $this->workingTimeModelMapper->find($id);

			return new JSONResponse([
				'success' => true,
				'model' => [
					'id' => $model->getId(),
					'name' => $model->getName(),
					'description' => $model->getDescription(),
					'type' => $model->getType(),
					'weeklyHours' => $model->getWeeklyHours(),
					'dailyHours' => $model->getDailyHours(),
					'workDaysPerWeek' => $model->getWorkDaysPerWeek(),
					'breakRules' => $model->getBreakRulesArray(),
					'overtimeRules' => $model->getOvertimeRulesArray(),
					'isDefault' => $model->getIsDefault()
				]
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Working time model not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create a new working time model
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function createWorkingTimeModel(): JSONResponse
	{
		try {
			$params = $this->request->getParams();

			$model = new \OCA\ArbeitszeitCheck\Db\WorkingTimeModel();
			$model->setName($params['name'] ?? '');
			$model->setDescription($params['description'] ?? null);
			$defaultDaily = max(0.5, min(24.0, (float)$this->appConfig->getAppValueString('default_working_hours', '8')));
			$model->setType($this->normalizeWorkingTimeModelType($params['type'] ?? ''));
			$model->setWeeklyHours($this->parseDecimalInput($params['weeklyHours'] ?? null, 40.0));
			$model->setDailyHours($this->parseDecimalInput($params['dailyHours'] ?? null, $defaultDaily));
			$model->setWorkDaysPerWeek($this->parseDecimalInput($params['workDaysPerWeek'] ?? null, 5.0));
			$model->setIsDefault(isset($params['isDefault']) ? (bool)$params['isDefault'] : false);
			$model->setCreatedAt(new \DateTime());
			$model->setUpdatedAt(new \DateTime());

			// Handle break rules and overtime rules if provided
			if (isset($params['breakRules']) && is_array($params['breakRules'])) {
				$scheduleError = $this->applyBreakRulesToWorkingTimeModel($model, $params['breakRules']);
				if ($scheduleError !== null) {
					return $scheduleError;
				}
			}
			if (isset($params['overtimeRules']) && is_array($params['overtimeRules'])) {
				$model->setOvertimeRulesArray($params['overtimeRules']);
			}

			// Validate
			$errors = $model->validate();
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

			// Atomic: clear other defaults + insert so concurrent creates cannot
			// leave two is_default=1 rows (TOCTOU on findDefault/update).
			$savedModel = $this->atomic(function () use ($model) {
				if ($model->getIsDefault()) {
					$this->workingTimeModelMapper->clearDefaults();
				}
				$saved = $this->workingTimeModelMapper->insert($model);
				$performedBy = $this->getPerformedBy();
				$this->auditLogMapper->logAction(
					$performedBy,
					'working_time_model_created',
					'working_time_model',
					$saved->getId(),
					null,
					$this->workingTimeModelToAuditValues($saved),
					$performedBy
				);
				return $saved;
			}, $this->db);

			return new JSONResponse([
				'success' => true,
				'model' => [
					'id' => $savedModel->getId(),
					'name' => $savedModel->getName(),
					'description' => $savedModel->getDescription(),
					'type' => $savedModel->getType(),
					'weeklyHours' => $savedModel->getWeeklyHours(),
					'dailyHours' => $savedModel->getDailyHours(),
					'workDaysPerWeek' => $savedModel->getWorkDaysPerWeek(),
					'isDefault' => $savedModel->getIsDefault()
				]
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update a working time model
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function updateWorkingTimeModel(int $id): JSONResponse
	{
		try {
			$model = $this->workingTimeModelMapper->find($id);
			$oldValues = $this->workingTimeModelToAuditValues($model);
			$params = $this->request->getParams();

			if (isset($params['name'])) {
				$model->setName($params['name']);
			}
			if (isset($params['description'])) {
				$model->setDescription($params['description']);
			}
			if (isset($params['type'])) {
				$model->setType($this->normalizeWorkingTimeModelType($params['type']));
			}
			if (isset($params['weeklyHours'])) {
				$model->setWeeklyHours($this->parseDecimalInput($params['weeklyHours'], $model->getWeeklyHours()));
			}
			if (isset($params['dailyHours'])) {
				$model->setDailyHours($this->parseDecimalInput($params['dailyHours'], $model->getDailyHours()));
			}
			if (isset($params['workDaysPerWeek'])) {
				$model->setWorkDaysPerWeek($this->parseDecimalInput($params['workDaysPerWeek'], $model->getWorkDaysPerWeek()));
			}
			if (isset($params['isDefault'])) {
				$model->setIsDefault((bool)$params['isDefault']);
			}
			if (isset($params['breakRules']) && is_array($params['breakRules'])) {
				$scheduleError = $this->applyBreakRulesToWorkingTimeModel($model, $params['breakRules']);
				if ($scheduleError !== null) {
					return $scheduleError;
				}
			}
			if (isset($params['overtimeRules']) && is_array($params['overtimeRules'])) {
				$model->setOvertimeRulesArray($params['overtimeRules']);
			}

			$model->setUpdatedAt(new \DateTime());

			// Validate
			$errors = $model->validate();
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

			// Atomic: clear other defaults + update (same TOCTOU fix as create).
			$updatedModel = $this->atomic(function () use ($model, $oldValues) {
				if ($model->getIsDefault()) {
					$this->workingTimeModelMapper->clearDefaults($model->getId());
				}
				$updated = $this->workingTimeModelMapper->update($model);
				$newValues = $this->workingTimeModelToAuditValues($updated);
				$performedBy = $this->getPerformedBy();
				$this->auditLogMapper->logAction(
					$performedBy,
					'working_time_model_updated',
					'working_time_model',
					$updated->getId(),
					$oldValues,
					$newValues,
					$performedBy
				);
				return $updated;
			}, $this->db);

			return new JSONResponse([
				'success' => true,
				'model' => [
					'id' => $updatedModel->getId(),
					'name' => $updatedModel->getName(),
					'description' => $updatedModel->getDescription(),
					'type' => $updatedModel->getType(),
					'weeklyHours' => $updatedModel->getWeeklyHours(),
					'dailyHours' => $updatedModel->getDailyHours(),
					'workDaysPerWeek' => $updatedModel->getWorkDaysPerWeek(),
					'isDefault' => $updatedModel->getIsDefault()
				]
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Working time model not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete a working time model
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function deleteWorkingTimeModel(int $id): JSONResponse
	{
		try {
			$model = $this->workingTimeModelMapper->find($id);

			// Fail closed: never leave the org without an explicit default schedule.
			if ($model->getIsDefault()) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Cannot delete the default working time model. Set another model as default first.'),
					'code' => 'DEFAULT_MODEL',
				], Http::STATUS_CONFLICT);
			}

			$oldValues = $this->workingTimeModelToAuditValues($model);
			$performedBy = $this->getPerformedBy();

			// Atomic re-check + vacation-default purge + delete closes the
			// assign-during-delete TOCTOU and avoids orphan L1 vacation rows.
			$this->atomic(function () use ($id, $model, $oldValues, $performedBy): void {
				$fresh = $this->workingTimeModelMapper->find($id);
				if ($fresh->getIsDefault()) {
					throw new BusinessRuleException(
						$this->l10n->t('Cannot delete the default working time model. Set another model as default first.'),
						'DEFAULT_MODEL'
					);
				}
				$userAssignments = $this->userWorkingTimeModelMapper->findByWorkingTimeModel($id, false);
				if (!empty($userAssignments)) {
					throw new BusinessRuleException(
						$this->l10n->t(
							'Cannot delete working time model: %d user(s) are assigned to this model. Please reassign users first.',
							[count($userAssignments)]
						),
						'MODEL_IN_USE'
					);
				}
				$this->layeredVacationDefaultsService->deleteDefaultsForWorkingTimeModel($id);
				$this->workingTimeModelMapper->delete($fresh);
				$this->auditLogMapper->logAction(
					$performedBy,
					'working_time_model_deleted',
					'working_time_model',
					$id,
					$oldValues,
					null,
					$performedBy
				);
			}, $this->db);

			return new JSONResponse([
				'success' => true,
				'message' => $this->l10n->t('Working time model deleted successfully'),
			]);
		} catch (BusinessRuleException $e) {
			$status = $e->getReasonCode() === 'DEFAULT_MODEL' ? Http::STATUS_CONFLICT : Http::STATUS_BAD_REQUEST;
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage(),
				'code' => $e->getReasonCode(),
			], $status);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Working time model not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getTariffRuleSets(): JSONResponse
	{
		try {
			$allRuleSets = $this->tariffRuleSetMapper->findAllOrdered();
			$all = [];
			foreach ($allRuleSets as $ruleSet) {
				$displayName = trim(sprintf(
					'%s %s%s',
					(string)$ruleSet->getTariffCode(),
					(string)$ruleSet->getVersion(),
					$ruleSet->getJurisdiction() ? ' - ' . (string)$ruleSet->getJurisdiction() : ''
				));
				$storedModules = [];
				foreach ($this->tariffRuleModuleMapper->findByRuleSetId($ruleSet->getId()) as $module) {
					$storedModules[] = [
						'moduleType' => $module->getModuleType(),
						'config' => $module->getConfig(),
					];
				}
				$isComplete = TariffRuleModuleValidator::validateList($storedModules) === [];
				$all[] = [
					'id' => $ruleSet->getId(),
					'tariffCode' => $ruleSet->getTariffCode(),
					'version' => $ruleSet->getVersion(),
					'jurisdiction' => $ruleSet->getJurisdiction(),
					'displayName' => $displayName,
					'validFrom' => $ruleSet->getValidFrom()?->format('Y-m-d'),
					'validTo' => $ruleSet->getValidTo()?->format('Y-m-d'),
					'status' => $ruleSet->getStatus(),
					'statusLabel' => $this->formatTariffRuleSetStatusLabel($ruleSet->getStatus()),
					'activationMode' => $ruleSet->getActivationMode(),
					'referenceModel' => $ruleSet->getReferenceModel(),
					'modulesCount' => count($storedModules),
					'isComplete' => $isComplete,
					'canActivate' => $ruleSet->getStatus() === Constants::TARIFF_RULE_SET_STATUS_DRAFT && $isComplete,
					'assignable' => $ruleSet->getStatus() === Constants::TARIFF_RULE_SET_STATUS_ACTIVE && $isComplete,
				];
			}
			return new JSONResponse(['success' => true, 'ruleSets' => $all]);
		} catch (\Throwable $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to load tariff rule sets')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Fetch a single tariff rule set with its modules for the edit modal.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getTariffRuleSet(int $id): JSONResponse
	{
		try {
			$ruleSet = $this->tariffRuleSetMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Tariff rule set not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to load tariff rule set')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$modules = [];
		try {
			foreach ($this->tariffRuleModuleMapper->findByRuleSetId($ruleSet->getId()) as $module) {
				$modules[] = [
					'id' => $module->getId(),
					'moduleType' => $module->getModuleType(),
					'config' => $module->getConfig(),
					'sortOrder' => $module->getSortOrder(),
				];
			}
		} catch (\Throwable $e) {
			// Modules are auxiliary; an empty list is a safe fallback so the modal can still open.
			$modules = [];
		}

		$displayName = trim(sprintf(
			'%s %s%s',
			(string)$ruleSet->getTariffCode(),
			(string)$ruleSet->getVersion(),
			$ruleSet->getJurisdiction() ? ' - ' . (string)$ruleSet->getJurisdiction() : ''
		));

		$storedForValidation = array_map(static fn ($m) => [
			'moduleType' => $m['moduleType'],
			'config' => $m['config'],
		], $modules);
		$isComplete = TariffRuleModuleValidator::validateList($storedForValidation) === [];

		return new JSONResponse(['success' => true, 'ruleSet' => [
			'id' => $ruleSet->getId(),
			'tariffCode' => $ruleSet->getTariffCode(),
			'version' => $ruleSet->getVersion(),
			'jurisdiction' => $ruleSet->getJurisdiction(),
			'displayName' => $displayName,
			'validFrom' => $ruleSet->getValidFrom()?->format('Y-m-d'),
			'validTo' => $ruleSet->getValidTo()?->format('Y-m-d'),
			'status' => $ruleSet->getStatus(),
			'statusLabel' => $this->formatTariffRuleSetStatusLabel($ruleSet->getStatus()),
			'activationMode' => $ruleSet->getActivationMode(),
			'modules' => $modules,
			'isComplete' => $isComplete,
			'canActivate' => $ruleSet->getStatus() === Constants::TARIFF_RULE_SET_STATUS_DRAFT && $isComplete,
			'assignable' => $ruleSet->getStatus() === Constants::TARIFF_RULE_SET_STATUS_ACTIVE && $isComplete,
		]]);
	}

	/**
	 * Delete a tariff rule set. Only DRAFT rule sets may be removed.
	 * Once a set has been activated the audit trail requires it to be retired,
	 * not deleted.
	 */
	#[NoAdminRequired]
	public function deleteTariffRuleSet(int $id): JSONResponse
	{
		try {
			$ruleSet = $this->tariffRuleSetMapper->find($id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Tariff rule set not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to delete tariff rule set')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($ruleSet->getStatus() !== Constants::TARIFF_RULE_SET_STATUS_DRAFT) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Only draft tariff rule sets can be deleted. Active or retired sets must remain for audit purposes.'),
			], Http::STATUS_CONFLICT);
		}

		try {
			$oldValues = $this->tariffRuleSetToAuditValues($ruleSet);
			$this->atomic(function () use ($ruleSet, $id, $oldValues): void {
				$this->tariffRuleModuleMapper->deleteByRuleSetId($ruleSet->getId());
				$this->tariffRuleSetMapper->delete($ruleSet);
				$performedBy = $this->getPerformedBy();
				$this->auditLogMapper->logAction(
					$performedBy,
					'tariff_rule_set_deleted',
					'tariff_rule_set',
					$id,
					$oldValues,
					null,
					$performedBy
				);
			}, $this->db);
			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::deleteTariffRuleSet: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to delete tariff rule set')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function createTariffRuleSet(): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$rejected = $this->rejectForbiddenTariffRuleSetRequestFields(
				$params,
				['status', 'createdAt', 'updatedAt'],
				$this->l10n->t('Some request fields are not allowed when creating a tariff rule set.'),
			);
			if ($rejected !== null) {
				return $rejected;
			}
			$ruleSet = new TariffRuleSet();
			$ruleSet->setTariffCode($this->normalizeTariffIdentity((string)($params['tariffCode'] ?? '')));
			$ruleSet->setVersion($this->normalizeTariffIdentity((string)($params['version'] ?? '')));
			$ruleSet->setJurisdiction(isset($params['jurisdiction']) ? $this->normalizeTariffIdentity((string)$params['jurisdiction']) : null);
			if ($ruleSet->getJurisdiction() === '') {
				$ruleSet->setJurisdiction(null);
			}
			$ruleSet->setValidFrom(new \DateTime((string)($params['validFrom'] ?? date('Y-01-01'))));
			$ruleSet->setValidTo(!empty($params['validTo']) ? new \DateTime((string)$params['validTo']) : null);
			$ruleSet->setActivationMode((string)($params['activationMode'] ?? 'immediate'));
			// New rule sets are always created as drafts. Status transitions are
			// only allowed through activate/retire so module completeness and
			// overlapping-active-set handling cannot be bypassed via POST.
			$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_DRAFT);
			$ruleSet->setReferenceModel(isset($params['referenceModel']) ? json_encode($params['referenceModel']) : null);
			$ruleSet->setCreatedAt(new \DateTime());
			$ruleSet->setUpdatedAt(new \DateTime());
			$errors = $ruleSet->validate();
			$modules = $this->normalizeTariffModulesPayload(is_array($params['modules'] ?? null) ? $params['modules'] : []);
			$moduleErrors = TariffRuleModuleValidator::validateList($modules);
			$errors = array_merge($errors, $moduleErrors);
			if (!empty($errors)) {
				$translatedErrors = $this->translateFieldErrors($errors);
				return new JSONResponse([
					'success' => false,
					'error' => $this->summarizeLayeredValidationUserMessage($translatedErrors, 'Validation failed'),
					'errors' => $translatedErrors,
				], Http::STATUS_BAD_REQUEST);
			}
			$duplicate = $this->tariffRuleSetMapper->findByCodeAndVersion($ruleSet->getTariffCode(), $ruleSet->getVersion());
			if ($duplicate !== null) {
				return $this->duplicateTariffRuleSetResponse($duplicate);
			}
			try {
				// Rule set + its modules + the audit entry are written as one
				// atomic unit: if module persistence fails, the rule set row is
				// rolled back instead of being left as an orphan draft that
				// blocks every later retry with a 409.
				return $this->atomic(function () use ($ruleSet, $modules): JSONResponse {
					$saved = $this->tariffRuleSetMapper->insert($ruleSet);
					$moduleSnapshot = $this->persistTariffRuleModules($saved->getId(), $modules);
					$performedBy = $this->getPerformedBy();
					$this->auditLogMapper->logAction(
						$performedBy,
						'tariff_rule_set_created',
						'tariff_rule_set',
						$saved->getId(),
						null,
						$this->tariffRuleSetToAuditValues($saved) + ['modules' => $moduleSnapshot],
						$performedBy
					);
					return new JSONResponse(['success' => true, 'ruleSetId' => $saved->getId()], Http::STATUS_CREATED);
				}, $this->db);
			} catch (\Throwable $insertException) {
				// Re-query only after the transaction has been rolled back, so
				// the lookup never runs inside an aborted transaction (Postgres).
				if ($this->isUniqueConstraintViolation($insertException)) {
					$raceDuplicate = $this->tariffRuleSetMapper->findByCodeAndVersion($ruleSet->getTariffCode(), $ruleSet->getVersion());
					if ($raceDuplicate !== null) {
						return $this->duplicateTariffRuleSetResponse($raceDuplicate);
					}
				}
				throw $insertException;
			}
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::createTariffRuleSet: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to create tariff rule set')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function updateTariffRuleSet(int $id): JSONResponse
	{
		try {
			$ruleSet = $this->tariffRuleSetMapper->find($id);
			if ($ruleSet->getStatus() !== Constants::TARIFF_RULE_SET_STATUS_DRAFT) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Only draft tariff rule sets can be edited. Active or retired sets are kept unchanged for audit purposes.'),
				], Http::STATUS_BAD_REQUEST);
			}
			$oldValues = $this->tariffRuleSetToAuditValues($ruleSet);
			$params = $this->request->getParams();
			$rejected = $this->rejectForbiddenTariffRuleSetRequestFields(
				$params,
				['status', 'tariffCode', 'version', 'createdAt', 'updatedAt'],
				$this->l10n->t('Some request fields cannot be changed when updating a tariff rule set.'),
			);
			if ($rejected !== null) {
				return $rejected;
			}
			if (isset($params['validFrom'])) {
				$ruleSet->setValidFrom(new \DateTime((string)$params['validFrom']));
			}
			if (array_key_exists('validTo', $params)) {
				$ruleSet->setValidTo(!empty($params['validTo']) ? new \DateTime((string)$params['validTo']) : null);
			}
			if (isset($params['activationMode'])) {
				$ruleSet->setActivationMode((string)$params['activationMode']);
			}
			// Status transitions are deliberately NOT editable here: a draft can
			// only become active/retired through the dedicated activate/retire
			// endpoints, which enforce module completeness and close the validity
			// window of any overlapping active set. Allowing a raw status change
			// via PUT would let an admin create two simultaneously-active sets
			// for the same tariff code (ambiguous, audit-breaking entitlement).
			if (isset($params['referenceModel'])) {
				$ruleSet->setReferenceModel(json_encode($params['referenceModel']));
			}
			if (isset($params['jurisdiction'])) {
				$jurisdiction = $this->normalizeTariffIdentity((string)$params['jurisdiction']);
				$ruleSet->setJurisdiction($jurisdiction !== '' ? $jurisdiction : null);
			}
			$ruleSet->setUpdatedAt(new \DateTime());
			$errors = $ruleSet->validate();
			$replaceModules = isset($params['modules']) && is_array($params['modules']);
			$modules = [];
			if ($replaceModules) {
				$modules = $this->normalizeTariffModulesPayload($params['modules']);
				$moduleErrors = TariffRuleModuleValidator::validateList($modules);
				$errors = array_merge($errors, $moduleErrors);
			}
			if (!empty($errors)) {
				$translatedErrors = $this->translateFieldErrors($errors);
				return new JSONResponse([
					'success' => false,
					'error' => $this->summarizeLayeredValidationUserMessage($translatedErrors, 'Validation failed'),
					'errors' => $translatedErrors,
				], Http::STATUS_BAD_REQUEST);
			}
			// Header changes and the full module replacement must commit together,
			// so a mid-write failure can never leave a draft without its modules.
			$this->atomic(function () use ($ruleSet, $replaceModules, $modules, $oldValues): void {
				$this->tariffRuleSetMapper->update($ruleSet);
				$moduleSnapshot = [];
				if ($replaceModules) {
					$this->tariffRuleModuleMapper->deleteByRuleSetId($ruleSet->getId());
					$moduleSnapshot = $this->persistTariffRuleModules($ruleSet->getId(), $modules);
				}
				$performedBy = $this->getPerformedBy();
				$this->auditLogMapper->logAction(
					$performedBy,
					'tariff_rule_set_updated',
					'tariff_rule_set',
					$ruleSet->getId(),
					$oldValues,
					$this->tariffRuleSetToAuditValues($ruleSet) + ($moduleSnapshot ? ['modules' => $moduleSnapshot] : []),
					$performedBy
				);
			}, $this->db);
			return new JSONResponse(['success' => true]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Tariff rule set not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::updateTariffRuleSet: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to update tariff rule set')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function activateTariffRuleSet(int $id): JSONResponse
	{
		try {
			$ruleSet = $this->tariffRuleSetMapper->find($id);
			if ($ruleSet->getStatus() !== Constants::TARIFF_RULE_SET_STATUS_DRAFT) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Only draft tariff rule sets can be activated.'),
				], Http::STATUS_CONFLICT);
			}
			$storedModules = [];
			foreach ($this->tariffRuleModuleMapper->findByRuleSetId($ruleSet->getId()) as $module) {
				$storedModules[] = [
					'moduleType' => $module->getModuleType(),
					'config' => $module->getConfig(),
				];
			}
			$moduleErrors = TariffRuleModuleValidator::validateList($storedModules);
			if ($moduleErrors !== []) {
				$translatedErrors = $this->translateFieldErrors($moduleErrors);
				return new JSONResponse([
					'success' => false,
					'error' => $this->summarizeLayeredValidationUserMessage($translatedErrors, 'Cannot activate an incomplete tariff rule set'),
					'errors' => $translatedErrors,
				], Http::STATUS_BAD_REQUEST);
			}
			$now = new \DateTimeImmutable('today');
			$activationStartDate = $this->resolveActivationStartDate($ruleSet->getActivationMode(), $now);
			$validFrom = $ruleSet->getValidFrom();
			if ($validFrom < new \DateTime($activationStartDate->format('Y-m-d'))) {
				$ruleSet->setValidFrom(new \DateTime($activationStartDate->format('Y-m-d')));
			}
			$validTo = $ruleSet->getValidTo();
			if ($validTo !== null && $validTo < $ruleSet->getValidFrom()) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Rule set validity window is invalid')
				], Http::STATUS_BAD_REQUEST);
			}

			// The handoff boundary is the new set's *effective* start, i.e. its
			// (possibly forward-adjusted) validFrom — never the bare activation
			// date. With a future-dated validFrom this keeps the outgoing set in
			// force right up to the day before the new one begins, leaving no
			// coverage gap and no overlap.
			$effectiveStart = new \DateTimeImmutable($ruleSet->getValidFrom()->format('Y-m-d'));

			// Closing the validity window of any overlapping active rule set and
			// promoting this draft to active must happen together: a partial
			// failure here would otherwise leave two active rule sets for the
			// same tariff code (an ambiguous, security-relevant state).
			$this->atomic(function () use ($ruleSet, $effectiveStart): void {
				$activeWithSameCode = $this->tariffRuleSetMapper->findActiveByTariffCode($ruleSet->getTariffCode());
				foreach ($activeWithSameCode as $activeRuleSet) {
					if ($activeRuleSet->getId() === $ruleSet->getId()) {
						continue;
					}
					$endBeforeActivation = $effectiveStart->modify('-1 day');
					if ($activeRuleSet->getValidTo() === null || $activeRuleSet->getValidTo() >= $effectiveStart) {
						if ($endBeforeActivation < new \DateTimeImmutable($activeRuleSet->getValidFrom()->format('Y-m-d'))) {
							$activeRuleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_RETIRED);
							$activeRuleSet->setValidTo(new \DateTime($activeRuleSet->getValidFrom()->format('Y-m-d')));
						} else {
							$activeRuleSet->setValidTo(new \DateTime($endBeforeActivation->format('Y-m-d')));
						}
						$activeRuleSet->setUpdatedAt(new \DateTime());
						$this->tariffRuleSetMapper->update($activeRuleSet);
					}
				}

				$oldValues = $this->tariffRuleSetToAuditValues($ruleSet);
				$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_ACTIVE);
				$ruleSet->setUpdatedAt(new \DateTime());
				$this->tariffRuleSetMapper->update($ruleSet);
				$performedBy = $this->getPerformedBy();
				$this->auditLogMapper->logAction(
					$performedBy,
					'tariff_rule_set_activated',
					'tariff_rule_set',
					$ruleSet->getId(),
					$oldValues,
					$this->tariffRuleSetToAuditValues($ruleSet),
					$performedBy
				);
			}, $this->db);
			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::activateTariffRuleSet: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to activate tariff rule set')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function retireTariffRuleSet(int $id): JSONResponse
	{
		try {
			$ruleSet = $this->tariffRuleSetMapper->find($id);
			if ($ruleSet->getStatus() !== Constants::TARIFF_RULE_SET_STATUS_ACTIVE) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Only active tariff rule sets can be retired.'),
				], Http::STATUS_CONFLICT);
			}
			$oldValues = $this->tariffRuleSetToAuditValues($ruleSet);
			$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_RETIRED);
			$ruleSet->setUpdatedAt(new \DateTime());
			$this->atomic(function () use ($ruleSet, $oldValues): void {
				$this->tariffRuleSetMapper->update($ruleSet);
				$performedBy = $this->getPerformedBy();
				$this->auditLogMapper->logAction(
					$performedBy,
					'tariff_rule_set_retired',
					'tariff_rule_set',
					$ruleSet->getId(),
					$oldValues,
					$this->tariffRuleSetToAuditValues($ruleSet),
					$performedBy
				);
			}, $this->db);
			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::retireTariffRuleSet: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to retire tariff rule set')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function assignVacationPolicy(string $userId): JSONResponse
	{
		try {
			$data = $this->adminUserProfileUpdateService->applyVacationPolicy(
				$userId,
				$this->request->getParams(),
				$this->getPerformedBy()
			);
			$status = !empty($data['created']) ? Http::STATUS_CREATED : Http::STATUS_OK;
			unset($data['created'], $data['updated']);
			return new JSONResponse(array_merge(['success' => true], $data), $status);
		} catch (AdminUserProfileUpdateException $e) {
			return $this->profileUpdateExceptionResponse($e);
		} catch (\Throwable $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to assign vacation policy')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update per-user overtime Stichtag and opening balance (Eröffnungssaldo).
	 */
	#[NoAdminRequired]
	public function updateUserOvertimeSettings(string $userId): JSONResponse
	{
		try {
			$data = $this->adminUserProfileUpdateService->applyOvertimeSettings(
				$userId,
				$this->request->getParams(),
				$this->getPerformedBy()
			);
			return new JSONResponse(array_merge(['success' => true], $data));
		} catch (AdminUserProfileUpdateException $e) {
			return $this->profileUpdateExceptionResponse($e);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('updateUserOvertimeSettings failed', ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Failed to update overtime settings'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * List audited overtime balance adjustments (Nullung / manual payout ledger).
	 */
	#[NoAdminRequired]
	public function listUserOvertimeAdjustments(string $userId): JSONResponse
	{
		try {
			if ($this->userManager->get($userId) === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not found')], Http::STATUS_NOT_FOUND);
			}
			$yearRaw = $this->request->getParam('year');
			$year = $yearRaw !== null && $yearRaw !== '' ? (int)$yearRaw : null;
			$limit = max(1, min(100, (int)($this->request->getParam('limit') ?? 50)));
			$offset = max(0, (int)($this->request->getParam('offset') ?? 0));
			$svc = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\OvertimeAdjustmentService::class);
			$data = $svc->listForUser($userId, $year, $limit, $offset);
			return new JSONResponse(array_merge(['success' => true], $data));
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('listUserOvertimeAdjustments failed', ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Failed to load overtime adjustments'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create an audited overtime balance adjustment. Does not change time entries.
	 */
	#[NoAdminRequired]
	public function createUserOvertimeAdjustment(string $userId): JSONResponse
	{
		try {
			if ($this->userManager->get($userId) === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not found')], Http::STATUS_NOT_FOUND);
			}
			$params = $this->request->getParams();
			$hoursRaw = str_replace(',', '.', trim((string)($params['hoursDelta'] ?? $params['hours_delta'] ?? '')));
			if ($hoursRaw === '' || !is_numeric($hoursRaw)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Hours delta must be a number (use negative to reduce the balance).'),
				], Http::STATUS_BAD_REQUEST);
			}
			$reason = trim((string)($params['reasonCode'] ?? $params['reason_code'] ?? \OCA\ArbeitszeitCheck\Db\OvertimeAdjustment::REASON_CUSTOM));
			$note = isset($params['note']) ? (string)$params['note'] : null;
			$svc = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\OvertimeAdjustmentService::class);
			$result = $svc->createAdjustment(
				$userId,
				(float)$hoursRaw,
				$reason,
				$note,
				$this->getPerformedBy()
			);
			return new JSONResponse(array_merge(['success' => true], $result), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('createUserOvertimeAdjustment failed', ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Failed to create overtime adjustment'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Zero the employee overtime Saldo via one compensating ledger row (Nullung).
	 */
	#[NoAdminRequired]
	public function resetUserOvertimeBalance(string $userId): JSONResponse
	{
		try {
			if ($this->userManager->get($userId) === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not found')], Http::STATUS_NOT_FOUND);
			}
			$note = $this->request->getParam('note');
			$svc = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\OvertimeAdjustmentService::class);
			$result = $svc->resetBalanceToZero(
				$userId,
				$this->getPerformedBy(),
				is_string($note) ? $note : null
			);
			return new JSONResponse(array_merge(['success' => true], $result));
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('resetUserOvertimeBalance failed', ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Failed to reset overtime balance'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update per-user time recording methods (clock stamping / manual entries).
	 */
	#[NoAdminRequired]
	public function updateUserTimeCaptureSettings(string $userId): JSONResponse
	{
		try {
			$data = $this->adminUserProfileUpdateService->applyTimeCaptureSettings(
				$userId,
				$this->request->getParams(),
				$this->getPerformedBy()
			);
			return new JSONResponse(array_merge(['success' => true], $data));
		} catch (AdminUserProfileUpdateException $e) {
			return $this->profileUpdateExceptionResponse($e);
		} catch (BusinessRuleException $e) {
			return new JSONResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('updateUserTimeCaptureSettings failed', ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Failed to update time recording settings'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function simulateVacationPolicy(): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$userId = (string)($params['userId'] ?? '');
			if ($userId === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User ID is required')], Http::STATUS_BAD_REQUEST);
			}
			// REQ-EC-10 / IDOR guard: verify the simulated user actually exists.
			// Without this an admin probing a typo'd UID would get a synthetic
			// "legacy fallback 25 d." result and we would have no clean way to
			// distinguish "no policy configured" from "no such user".
			if ($this->userManager->get($userId) === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not found')], Http::STATUS_NOT_FOUND);
			}
			[$asOfDate, $asOfErr] = $this->parseStrictYmdDateParam((string)($params['asOfDate'] ?? date('Y-m-d')));
			if ($asOfErr !== null) {
				return $asOfErr;
			}
			[$employmentStart, $employmentEnd, $employmentErr] = $this->resolveSimulateEmploymentDates($userId, $params);
			if ($employmentErr !== null) {
				return $employmentErr;
			}
			$draftPolicy = $params['draftPolicy'] ?? null;
			// REQ-WF-05: optional hypothetical team membership for what-if
			// scenarios ("what would this user's entitlement be if we moved
			// them to team X?"). Accepts a list of team IDs; engine treats
			// them as L2 membership candidates for *this call only* (no
			// persistence). Invalid / unknown IDs are silently filtered out
			// so admins can pass mixed UI state without an error 400.
			$hypotheticalTeamIds = null;
			if (isset($params['hypotheticalTeamIds']) && is_array($params['hypotheticalTeamIds'])) {
				$hypotheticalTeamIds = [];
				foreach ($params['hypotheticalTeamIds'] as $raw) {
					if ($raw === null || $raw === '') {
						continue;
					}
					$tid = (int)$raw;
					if ($tid > 0) {
						$hypotheticalTeamIds[] = $tid;
					}
				}
				$hypotheticalTeamIds = array_values(array_unique($hypotheticalTeamIds));
			}
			if ($hypotheticalTeamIds !== null) {
				$this->vacationEntitlementEngine->setHypotheticalTeams($userId, $hypotheticalTeamIds);
			}
			try {
				if (is_array($draftPolicy)) {
					$policy = new UserVacationPolicyAssignment();
					$policy->setUserId($userId);
					$draftMode = (string)($draftPolicy['vacationMode'] ?? Constants::VACATION_MODE_MANUAL_FIXED);
					$draftInherit = !empty($draftPolicy['inheritLowerLayers'])
						|| $draftMode === Constants::VACATION_MODE_INHERIT;
					if ($draftInherit) {
						$draftMode = Constants::VACATION_MODE_INHERIT;
					}
					$policy->setVacationMode($draftMode);
					$draftManual = null;
					if (!$draftInherit && isset($draftPolicy['manualDays'])) {
						$draftManual = $this->parseDecimalInput($draftPolicy['manualDays'], 0.0);
						// Bachus: simulator draft is always calendar days; store unit may be hours.
						$draftManual = $this->vacationUnitService()->adminDaysToStoredAmount($draftManual);
					}
					$policy->setManualDays($draftManual);
					$policy->setTariffRuleSetId(
						$draftInherit
							? null
							: (isset($draftPolicy['tariffRuleSetId']) && $draftPolicy['tariffRuleSetId'] !== '' ? (int)$draftPolicy['tariffRuleSetId'] : null)
					);
					$policy->setOverrideReason(isset($draftPolicy['overrideReason']) ? trim((string)$draftPolicy['overrideReason']) : null);
					$policy->setInheritLowerLayers($draftInherit);
					$policy->setEffectiveFrom($asOfDate);
					$policy->setEffectiveTo(null);
					$policy->setCreatedBy('simulation');
					$policy->setCreatedAt(new \DateTime());
					$policy->setUpdatedAt(new \DateTime());
					$errors = $policy->validate();
					if (!empty($errors)) {
						$translatedErrors = [];
						foreach ($errors as $field => $message) {
							$translatedErrors[$field] = $this->l10n->t($message);
						}
						return new JSONResponse([
							'success' => false,
							'error' => $this->l10n->t('Validation failed'),
							'errors' => $translatedErrors,
						], Http::STATUS_BAD_REQUEST);
					}
					$result = $this->vacationEntitlementEngine->computeForPolicy($userId, $policy, $asOfDate);
				} else {
					$result = $this->vacationEntitlementEngine->computeForDate($userId, $asOfDate);
				}
			} finally {
				if ($hypotheticalTeamIds !== null) {
					$this->vacationEntitlementEngine->clearHypotheticalTeams($userId);
				}
			}

			$fullYearDays = $this->vacationEntitlementEngine->roundDays((float)$result['days']);
			$simYear = (int)$asOfDate->format('Y');
			$proration = VacationProrationService::computeProration(
				$simYear,
				$fullYearDays,
				$employmentStart,
				$employmentEnd,
				$this->vacationProrationService->getConfiguredMethod()
			);

			return new JSONResponse([
				'success' => true,
				'userId' => $userId,
				'asOfDate' => $asOfDate->format('Y-m-d'),
				// Full calendar-year entitlement from the policy engine (layer sim).
				'effectiveEntitlementDays' => $fullYearDays,
				'fullYearEntitlementDays' => $fullYearDays,
				'proratedEntitlementDays' => (float)$proration['days'],
				'prorated' => (bool)$proration['prorated'],
				'prorationMethod' => (string)$proration['method'],
				'monthsCovered' => (int)$proration['months_covered'],
				'employedInYear' => (bool)$proration['employed_in_year'],
				'source' => $result['source'],
				'ruleSetId' => $result['ruleSetId'],
				'hypotheticalTeamIds' => $hypotheticalTeamIds,
				'calculationTrace' => $result['trace'],
				'prorationTrace' => $proration,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('simulateVacationPolicy failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to simulate vacation policy')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/* ====================================================================
	 * Layered vacation entitlement (L0 / L1 / L2) admin endpoints
	 *
	 * Authorisation: AdminController is server-admin / app-admin gated by
	 * AppAdminMiddleware (#[NoAdminRequired] + PermissionService::isAdmin).
	 * Delegated HR managers are NOT allowed to mutate organisation or
	 * model defaults — REQ-SEC-03. Future delegation work must align with
	 * `TeamManagerMapper` instead of weakening this gate.
	 * ==================================================================== */

	/**
	 * GET — Full overview of the layered configuration for the admin UI.
	 */
	#[NoAdminRequired]
	public function getVacationLayers(): JSONResponse
	{
		try {
			$asOfRaw = trim((string)($this->request->getParam('asOfDate') ?? date('Y-m-d')));
			[$asOfDate, $asOfErr] = $this->parseStrictYmdDateParam($asOfRaw);
			if ($asOfErr !== null) {
				return $asOfErr;
			}
			$orgDefaults = $this->layeredVacationDefaultsService->listOrgDefaults();
			$activeOrg = $this->layeredVacationDefaultsService->getActiveOrgDefault($asOfDate);
			$modelDefaults = $this->layeredVacationDefaultsService->listModelDefaults();
			$teamPolicies = $this->layeredVacationDefaultsService->listTeamPolicies();
			$models = $this->workingTimeModelMapper->findAll();
			$teams = $this->teamMapper->findAll();
			$rulesets = $this->tariffRuleSetMapper->findAllOrdered();

			$present = fn ($e) => $this->layeredVacationDefaultsService->presentLayerSummary($e->getSummary());

			return new JSONResponse([
				'success' => true,
				'feature' => [
					'layeredEnabled' => $this->vacationEntitlementEngine->isLayeredEnabled(),
				],
				'asOfDate' => $asOfDate->format('Y-m-d'),
				'org' => [
					'active' => $activeOrg ? $present($activeOrg) : null,
					'history' => array_map($present, $orgDefaults),
				],
				'model' => [
					'defaults' => array_map($present, $modelDefaults),
					'availableModels' => array_map(static fn ($m) => [
						'id' => $m->getId(),
						'name' => $m->getName(),
						'workDaysPerWeek' => $m->getWorkDaysPerWeek(),
					], $models),
				],
				'team' => [
					'policies' => array_map($present, $teamPolicies),
					'availableTeams' => array_map(static fn ($t) => [
						'id' => $t->getId(),
						'name' => $t->getName(),
						'parentId' => $t->getParentId(),
					], $teams),
				],
				'ruleSets' => array_map(function ($r) {
					return [
						'id' => $r->getId(),
						'tariffCode' => $r->getTariffCode(),
						'version' => $r->getVersion(),
						'status' => $r->getStatus(),
						'statusLabel' => $this->formatTariffRuleSetStatusLabel($r->getStatus()),
					];
				}, $rulesets),
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('getVacationLayers failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Could not load vacation entitlement layers')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function saveOrgVacationDefault(): JSONResponse
	{
		return $this->handleLayeredSave(function () {
			$payload = $this->request->getParams();
			$saved = $this->layeredVacationDefaultsService->upsertOrgDefault($payload, $this->getPerformedBy());
			return $saved->getSummary();
		});
	}

	#[NoAdminRequired]
	public function deleteOrgVacationDefault(int $id): JSONResponse
	{
		return $this->handleLayeredDelete(function () use ($id) {
			$this->layeredVacationDefaultsService->deleteOrgDefault($id, $this->getPerformedBy());
		});
	}

	#[NoAdminRequired]
	public function saveModelVacationDefault(): JSONResponse
	{
		return $this->handleLayeredSave(function () {
			$payload = $this->request->getParams();
			$saved = $this->layeredVacationDefaultsService->upsertModelDefault($payload, $this->getPerformedBy());
			return $saved->getSummary();
		});
	}

	#[NoAdminRequired]
	public function deleteModelVacationDefault(int $id): JSONResponse
	{
		return $this->handleLayeredDelete(function () use ($id) {
			$this->layeredVacationDefaultsService->deleteModelDefault($id, $this->getPerformedBy());
		});
	}

	#[NoAdminRequired]
	public function saveTeamVacationPolicy(): JSONResponse
	{
		return $this->handleLayeredSave(function () {
			$payload = $this->request->getParams();
			$saved = $this->layeredVacationDefaultsService->upsertTeamPolicy($payload, $this->getPerformedBy());
			return $saved->getSummary();
		});
	}

	#[NoAdminRequired]
	public function deleteTeamVacationPolicy(int $id): JSONResponse
	{
		return $this->handleLayeredDelete(function () use ($id) {
			$this->layeredVacationDefaultsService->deleteTeamPolicy($id, $this->getPerformedBy());
		});
	}

	/**
	 * Impact preview for vacation-layer writes (REQ-UX-03).
	 *
	 * Used by the admin UI to display "this change will affect ~N users"
	 * before the admin clicks Save. The endpoint is read-only and never
	 * acquires the write lock; the returned `affected_user_count` is an
	 * upper bound based on current assignments, not a full re-simulation.
	 *
	 * Query params:
	 *  - `scope`: one of `org`, `model`, `team`
	 *  - `targetId`: required for `model` / `team`
	 *
	 * Authorisation: app-admin only (`#[NoAdminRequired]` + AppAdminMiddleware).
	 */
	#[NoAdminRequired]
	public function previewVacationLayerImpact(): JSONResponse
	{
		try {
			$scope = trim((string)($this->request->getParam('scope') ?? ''));
			$targetIdRaw = $this->request->getParam('targetId');
			$targetId = ($targetIdRaw === null || $targetIdRaw === '') ? null : (int)$targetIdRaw;
			$preview = $this->layeredVacationDefaultsService->previewImpact($scope, $targetId);
			$scopeNorm = strtolower((string)($preview['scope'] ?? $scope));
			$preview['note'] = match ($scopeNorm) {
				'org' => $this->l10n->t('Upper bound: counts users without an L1/L2/L3 override at all. Some users may still be served by a higher layer.'),
				'model' => $this->l10n->t('Counts active assignments of this working time model. Users with an L2/L3 override will not be re-resolved by an L1 change.'),
				'team' => $this->l10n->t('Counts members of the team and its descendants. Users with an L3 override will not be re-resolved by an L2 change.'),
				default => (string)($preview['note'] ?? ''),
			};
			return new JSONResponse(['success' => true, 'data' => $preview]);
		} catch (LayeredVacationValidationException $e) {
			$translatedFieldErrors = $this->translateFieldErrors($e->fieldErrors);
			return new JSONResponse([
				'success' => false,
				'error' => $this->summarizeLayeredValidationUserMessage($translatedFieldErrors, $e->getMessage()),
				'errors' => $translatedFieldErrors,
			], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('previewVacationLayerImpact failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Impact preview failed')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Legacy user-search endpoint (vacation-layers simulator and older integrations).
	 *
	 * Delegates to {@see getUsersForPicker()} so behaviour matches
	 * {@code GET /api/admin/users?picker=1} (enabled users only, min search length).
	 *
	 * Authorisation: app-admin only (`#[NoAdminRequired]` + AppAdminMiddleware).
	 */
	#[NoAdminRequired]
	public function searchVacationLayersUsers(): JSONResponse
	{
		try {
			$search = trim((string)($this->request->getParam('search') ?? ''));
			$limit = (int)($this->request->getParam('limit') ?? 10);

			return $this->getUsersForPicker($search, $limit);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('searchVacationLayersUsers failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User search failed')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Translate the field-error map returned by the layered service into
	 * the same l10n-translated shape the existing assignVacationPolicy
	 * endpoint emits, so the admin JS form-error renderer can be reused.
	 *
	 * @param array<string, string> $errors
	 * @return array<string, string>
	 */
	private function translateFieldErrors(array $errors): array
	{
		$translated = [];
		foreach ($errors as $field => $message) {
			if ($field === 'effectiveFrom'
				&& preg_match(
					'/^Overlaps existing organisation default #(\d+) \((.+)\)\. Adjust the date range or delete the existing row\.$/',
					$message,
					$m
				)) {
				$translated[$field] = $this->l10n->t(
					'Overlaps existing organisation default %1$d (%2$s). Adjust the date range or delete the existing row.',
					[(int)$m[1], (string)$m[2]]
				);
				continue;
			}
			$translated[$field] = $this->l10n->t($message);
		}
		return $translated;
	}

	/**
	 * Human-readable tariff rule set lifecycle label for JSON APIs (admin UI).
	 */
	private function formatTariffRuleSetStatusLabel(string $status): string
	{
		return match ($status) {
			Constants::TARIFF_RULE_SET_STATUS_DRAFT => $this->l10n->t('Draft'),
			Constants::TARIFF_RULE_SET_STATUS_ACTIVE => $this->l10n->t('Active'),
			Constants::TARIFF_RULE_SET_STATUS_RETIRED => $this->l10n->t('Retired'),
			default => $this->l10n->t('Unknown tariff rule set status (%s)', [$status]),
		};
	}

	/**
	 * Reject payloads that attempt to set fields owned by other workflow steps
	 * (status transitions, immutable identity, system timestamps).
	 *
	 * @param list<string> $forbiddenFields
	 */
	private function rejectForbiddenTariffRuleSetRequestFields(
		array $params,
		array $forbiddenFields,
		string $summaryMessage,
	): ?JSONResponse {
		$fieldErrors = [];
		foreach ($forbiddenFields as $field) {
			if (!array_key_exists($field, $params)) {
				continue;
			}
			$fieldErrors[$field] = match ($field) {
				'status' => $this->l10n->t('Status can only be changed via Activate or Retire.'),
				'tariffCode', 'version' => $this->l10n->t('Tariff code and version are fixed after creation.'),
				'createdAt', 'updatedAt' => $this->l10n->t('Timestamps are managed by the system.'),
				default => $this->l10n->t('This field cannot be set through this endpoint.'),
			};
		}
		if ($fieldErrors === []) {
			return null;
		}

		return new JSONResponse([
			'success' => false,
			'error' => $summaryMessage,
			'errors' => $fieldErrors,
		], Http::STATUS_BAD_REQUEST);
	}

	private function normalizeTariffIdentity(string $value): string
	{
		$trimmed = trim($value);
		if ($trimmed === '') {
			return '';
		}
		return (string)preg_replace('/\s+/u', ' ', $trimmed);
	}

	/**
	 * @param list<array<string, mixed>> $modules
	 * @return list<array<string, mixed>>
	 */
	private function normalizeTariffModulesPayload(array $modules): array
	{
		$normalized = [];
		foreach ($modules as $module) {
			if (!is_array($module) || empty($module['moduleType'])) {
				continue;
			}
			$config = is_array($module['config'] ?? null) ? $module['config'] : [];
			$normalized[] = [
				'moduleType' => trim((string)$module['moduleType']),
				'config' => $config,
			];
		}
		return $normalized;
	}

	/**
	 * @param list<array<string, mixed>> $modules
	 * @return list<array{moduleType: string, sortOrder: int}>
	 */
	private function persistTariffRuleModules(int $ruleSetId, array $modules): array
	{
		$moduleSnapshot = [];
		$sort = 0;
		foreach ($modules as $module) {
			$entity = new TariffRuleModule();
			$entity->setRuleSetId($ruleSetId);
			$entity->setModuleType((string)$module['moduleType']);
			$entity->setConfig(is_array($module['config'] ?? null) ? $module['config'] : []);
			$entity->setSortOrder($sort++);
			$entity->setCreatedAt(new \DateTime());
			$entity->setUpdatedAt(new \DateTime());
			$this->tariffRuleModuleMapper->insert($entity);
			$moduleSnapshot[] = [
				'moduleType' => $entity->getModuleType(),
				'sortOrder' => $entity->getSortOrder(),
			];
		}
		return $moduleSnapshot;
	}

	/**
	 * Detect a unique-constraint violation regardless of which exception layer
	 * surfaced it (Doctrine DBAL directly, or Nextcloud's wrapping
	 * OCP\DB\Exception with REASON_UNIQUE_CONSTRAINT_VIOLATION).
	 */
	private function isUniqueConstraintViolation(\Throwable $e): bool
	{
		if ($e instanceof UniqueConstraintViolationException) {
			return true;
		}
		if ($e instanceof DBException && $e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
			return true;
		}
		$previous = $e->getPrevious();
		return $previous instanceof UniqueConstraintViolationException;
	}

	private function duplicateTariffRuleSetResponse(TariffRuleSet $duplicate): JSONResponse
	{
		return new JSONResponse([
			'success' => false,
			'code' => 'duplicate_code_version',
			'error' => $this->l10n->t(
				'A tariff rule set with tariff code "%1$s" and version "%2$s" already exists.',
				[
					$duplicate->getTariffCode(),
					$duplicate->getVersion(),
				],
			),
			'errors' => [
				'tariffCode' => $this->l10n->t('This tariff code is already used with this version.'),
				'version' => $this->l10n->t('Choose a different version label, or open the existing rule set.'),
			],
			'existing' => [
				'id' => $duplicate->getId(),
				'tariffCode' => $duplicate->getTariffCode(),
				'version' => $duplicate->getVersion(),
				'status' => $duplicate->getStatus(),
				'statusLabel' => $this->formatTariffRuleSetStatusLabel($duplicate->getStatus()),
			],
		], Http::STATUS_CONFLICT);
	}

	/**
	 * Top-level `error` string for validation responses: one concrete message
	 * when a single field failed, otherwise a short umbrella message (WCAG
	 * 3.3.1 — errors described in text, not only "Validation failed").
	 *
	 * @param array<string, string> $translatedFieldErrors
	 */
	private function summarizeLayeredValidationUserMessage(array $translatedFieldErrors, string $exceptionMessage): string
	{
		if ($translatedFieldErrors === []) {
			return $this->l10n->t($exceptionMessage);
		}
		if (count($translatedFieldErrors) === 1) {
			return (string)reset($translatedFieldErrors);
		}
		return $this->l10n->t('Several fields need attention. Review each highlighted field below.');
	}

	private function handleLayeredSave(callable $action): JSONResponse
	{
		try {
			$summary = $action();
			if (is_array($summary)) {
				$summary = $this->layeredVacationDefaultsService->presentLayerSummary($summary);
			}
			return new JSONResponse(['success' => true, 'data' => $summary], Http::STATUS_CREATED);
		} catch (LayeredVacationValidationException $e) {
			$translatedFieldErrors = $this->translateFieldErrors($e->fieldErrors);
			return new JSONResponse([
				'success' => false,
				'error' => $this->summarizeLayeredValidationUserMessage($translatedFieldErrors, $e->getMessage()),
				'errors' => $translatedFieldErrors,
			], Http::STATUS_BAD_REQUEST);
		} catch (LayeredVacationNotFoundException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_NOT_FOUND);
		} catch (LayeredVacationConflictException $e) {
			// REQ-SEC-04 / EC-07 — surface concurrent-edit conflicts as 409
			// so the admin JS can show "refresh and retry" instead of a 500.
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Layered vacation save failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to save vacation entitlement layer')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function handleLayeredDelete(callable $action): JSONResponse
	{
		try {
			$action();
			return new JSONResponse(['success' => true]);
		} catch (LayeredVacationNotFoundException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_NOT_FOUND);
		} catch (LayeredVacationConflictException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Layered vacation delete failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Failed to delete vacation entitlement layer')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Admin vacation rules (year, carryover, unit, pro-rata) — policy Leave → Rules.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function vacationRules(): TemplateResponse
	{
		$this->registerFrontEndAssets(
			'admin-notifications',
			'admin-notifications',
			['admin-settings'],
			['admin-policy-legacy-redirect']
		);

		$catalog = new \OCA\ArbeitszeitCheck\Service\AdminPolicyPagesCatalog();
		$response = new TemplateResponse('arbeitszeitcheck', 'admin-vacation-rules', array_merge(
			$this->buildAdminShellParams(
				'admin-vacation-rules',
				$catalog->label($this->l10n, \OCA\ArbeitszeitCheck\Service\AdminPolicyPagesCatalog::SECTION_VACATION),
				$this->l10n->t('Vacation year, carryover, day/hour unit, and pro-rata for the organisation.'),
			),
			[
				'l' => $this->l10n,
				'urlGenerator' => $this->urlGenerator,
				'requesttoken' => Util::callRegister(),
				'vacationPolicySettings' => $this->buildNotificationSettingsPayload(),
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Page route: vacation entitlement layers (L0/L1/L2 + simulator).
	 * Server-rendered shell + JS hydration mirroring the pattern used by
	 * `admin#teams`, `admin#workingTimeModels`, etc.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function vacationLayers(): TemplateResponse
	{
		$this->registerFrontEndAssets(
			'admin-vacation-layers',
			'admin-vacation-layers',
			['admin-settings', 'admin-notifications'],
			['admin-policy-legacy-redirect']
		);

		$catalog = new \OCA\ArbeitszeitCheck\Service\AdminPolicyPagesCatalog();
		$response = new TemplateResponse('arbeitszeitcheck', 'admin-vacation-layers', array_merge(
			$this->buildAdminShellParams(
				'admin-vacation-layers',
				$catalog->label($this->l10n, \OCA\ArbeitszeitCheck\Service\AdminPolicyPagesCatalog::SECTION_VACATION_ENTITLEMENT),
				$this->l10n->t('Organisation, model, and team vacation amounts — higher layer wins.'),
			),
			[
				'l' => $this->l10n,
				'urlGenerator' => $this->urlGenerator,
				'requesttoken' => Util::callRegister(),
				'layeredEnabled' => $this->vacationEntitlementEngine->isLayeredEnabled(),
				'vacationUnit' => (new \OCA\ArbeitszeitCheck\Service\VacationUnitService($this->config))->getUnit(),
				'vacationHoursPerDay' => (new \OCA\ArbeitszeitCheck\Service\VacationUnitService($this->config))->getHoursPerDay(),
			],
		));
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Export users data
	 *
	 * @param string $format Format: csv, json
	 * @return DataDownloadResponse|JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportUsers(string $format = 'csv'): DataDownloadResponse|JSONResponse
	{
		try {
			$filterParam = $this->request->getParam('filter');
			$filterRaw = is_string($filterParam) && trim($filterParam) !== '' ? trim($filterParam) : null;

			$listResult = $this->adminEmployeeDirectoryService->listUsers(
				$filterRaw,
				'',
				Constants::ADMIN_EMPLOYEE_FILTER_MAX_SCAN,
				0,
				false,
			);

			$data = [];
			foreach ($listResult['users'] as $user) {
				$userId = $user->getUID();
				$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
				$workingTimeModelName = null;
				if ($currentModel) {
					try {
						$workingTimeModel = $this->workingTimeModelMapper->find($currentModel->getWorkingTimeModelId());
						$workingTimeModelName = $workingTimeModel->getName();
					} catch (\Throwable $e) {
						// Model might have been deleted
					}
				}

				$data[] = [
					'user_id' => $userId,
					'display_name' => $user->getDisplayName(),
					'email' => $user->getEMailAddress() ?? '',
					'enabled' => $user->isEnabled() ? 'Yes' : 'No',
					'working_time_model' => $workingTimeModelName ?? '',
					'vacation_days_per_year' => $currentModel ? $currentModel->getVacationDaysPerYear() : '',
					'working_time_model_start_date' => $currentModel && ($startDate = $currentModel->getStartDate()) ? $startDate->format('Y-m-d') : '',
					'working_time_model_end_date' => $currentModel && ($endDate = $currentModel->getEndDate()) ? $endDate->format('Y-m-d') : '',
				];
			}

			$filterSuffix = $listResult['filter'] === AdminEmployeeDirectoryService::FILTER_APP_ACCESS ? 'app-access' : 'all';
			// AS-05 / NFR: surface scan-cap truncation in the download name so HR
			// never mistakes a capped export for a complete directory dump.
			$truncSuffix = !empty($listResult['truncated']) ? '-truncated' : '';
			$filename = 'users-export-' . $filterSuffix . $truncSuffix . '-' . date('Y-m-d') . '.' . $format;

			return match ($format) {
				'csv' => $this->exportAsCsv($data, $filename),
				'json' => $this->exportAsJson($data, $filename),
				default => $this->exportAsCsv($data, $filename)
			};
		} catch (InvalidEmployeeListFilterException $e) {
			// Same stable contract as getUsers — never dump a download or opaque 500 for a bad filter.
			return new JSONResponse([
				'success' => false,
				'code' => InvalidEmployeeListFilterException::CODE,
				'error' => $e->getMessage(),
			], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			throw new \Exception($this->l10n->t('Failed to export users.'));
		}
	}

	/**
	 * Export data as CSV
	 *
	 * @param array $data Data to export
	 * @param string $filename Filename for download
	 * @return DataDownloadResponse
	 */
	private function exportAsCsv(array $data, string $filename): DataDownloadResponse
	{
		if (empty($data)) {
			$csv = "No data available\n";
		} else {
			// Get headers from first row
			$headers = array_keys($data[0]);

			// Create CSV content
			$csv = '';

			// Add headers
			$csv .= implode(',', array_map(function ($header) {
				return '"' . str_replace('"', '""', $header) . '"';
			}, $headers)) . "\n";

			// Add data rows
			foreach ($data as $row) {
				$csv .= implode(',', array_map(function ($value) {
					$sanitized = self::sanitizeCsvCellValue((string)$value);
					return '"' . str_replace('"', '""', $sanitized) . '"';
				}, array_values($row))) . "\n";
			}
		}

		return new DataDownloadResponse($csv, $filename, 'text/csv; charset=utf-8');
	}

	/**
	 * Export data as JSON
	 *
	 * @param array $data Data to export
	 * @param string $filename Filename for download
	 * @return DataDownloadResponse
	 */
	private function exportAsJson(array $data, string $filename): DataDownloadResponse
	{
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		return new DataDownloadResponse($json, $filename, 'application/json');
	}

	/**
	 * Get audit logs with filters
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAuditLogs(): JSONResponse
	{
		try {
			$params = $this->request->getParams();

			$dates = $this->parseAuditLogDateFilters($params);
			if ($dates instanceof JSONResponse) {
				return $dates;
			}

			$searchFilters = $this->buildAuditLogSearchFilters($params);
			if ($searchFilters instanceof JSONResponse) {
				return $searchFilters;
			}

			$limit = isset($params['limit']) ? max(1, min(100, (int)$params['limit'])) : self::AUDIT_LOG_PAGE_SIZE;
			$offset = isset($params['offset']) ? max(0, (int)$params['offset']) : 0;

			$startDate = $dates['startDate'];
			$endDate = $dates['endDate'];

			$totalCount = $this->auditLogMapper->countByDateRange($startDate, $endDate, $searchFilters);
			$paginatedLogs = $this->auditLogMapper->searchByDateRange($startDate, $endDate, array_merge($searchFilters, [
				'limit' => $limit,
				'offset' => $offset,
			]));

			$formattedLogs = array_map(function (AuditLog $log): array {
				$entry = $this->formatAuditLogEntry($log);
				$user = $this->userManager->get($log->getUserId());
				$performedByUser = $log->getPerformedBy() ? $this->userManager->get($log->getPerformedBy()) : null;

				return [
					'id' => $entry['id'],
					'user_id' => $entry['userId'],
					'user_display_name' => $entry['userDisplayName'],
					'action' => $entry['action'],
					'action_key' => $entry['actionKey'],
					'entity_type' => $entry['entityType'],
					'entity_type_key' => $entry['entityTypeKey'],
					'entity_id' => $entry['entityId'],
					'old_values' => $log->getOldValues() ? json_decode($log->getOldValues(), true) : null,
					'new_values' => $log->getNewValues() ? json_decode($log->getNewValues(), true) : null,
					'performed_by' => $entry['performedBy'],
					'performed_by_display_name' => $entry['performedByDisplayName'],
					'ip_address' => $log->getIpAddress(),
					'user_agent' => $log->getUserAgent(),
					'created_at' => $entry['createdAtIso'],
					'created_at_display' => $entry['createdAt'],
					'capture_source' => $entry['captureSource'],
					'client_occurred_at' => $entry['clientOccurredAtIso'],
					'is_offline_sync' => $entry['isOfflineSync'],
					'captureSource' => $entry['captureSource'],
					'clientOccurredAtIso' => $entry['clientOccurredAtIso'],
					'isOfflineSync' => $entry['isOfflineSync'],
				];
			}, $paginatedLogs);

			return new JSONResponse([
				'success' => true,
				'logs' => $formattedLogs,
				'total' => $totalCount,
				'limit' => $limit,
				'offset' => $offset,
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get audit log statistics
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAuditLogStats(): JSONResponse
	{
		try {
			$params = $this->request->getParams();

			$filters = [];
			if (isset($params['start_date']) && $params['start_date']) {
				$filters['start_date'] = new \DateTime($params['start_date']);
			}
			if (isset($params['end_date']) && $params['end_date']) {
				$filters['end_date'] = new \DateTime($params['end_date']);
			}

			$stats = $this->auditLogMapper->getStatistics($filters);

			return new JSONResponse([
				'success' => true,
				'statistics' => $stats
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Export audit logs
	 *
	 * @param string $format Format: csv, json
	 * @return DataDownloadResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportAuditLogs(string $format = 'csv'): DataDownloadResponse
	{
		try {
			$params = $this->request->getParams();

			$dates = $this->parseAuditLogDateFilters($params);
			if ($dates instanceof JSONResponse) {
				throw new \Exception((string)$dates->getData()['error']);
			}

			$searchFilters = $this->buildAuditLogSearchFilters($params);
			if ($searchFilters instanceof JSONResponse) {
				throw new \Exception((string)$searchFilters->getData()['error']);
			}

			$startDate = $dates['startDate'];
			$endDate = $dates['endDate'];

			$logs = $this->auditLogMapper->searchByDateRange($startDate, $endDate, $searchFilters);

			$exportData = [];
			foreach ($logs as $log) {
				$entry = $this->formatAuditLogEntry($log);

				$exportData[] = [
					'id' => $log->getId(),
					'date_time' => $entry['createdAt'],
					'user_id' => $log->getUserId(),
					'user_display_name' => $entry['userDisplayName'],
					'action' => $entry['action'],
					'action_key' => $entry['actionKey'],
					'entity_type' => $entry['entityType'],
					'entity_type_key' => $entry['entityTypeKey'],
					'entity_id' => $log->getEntityId(),
					'performed_by' => $log->getPerformedBy() ?? $log->getUserId(),
					'performed_by_display_name' => $entry['performedByDisplayName'],
					'ip_address' => $log->getIpAddress() ?? '',
					'user_agent' => $log->getUserAgent() ?? '',
					'old_values' => $log->getOldValues() ?? '',
					'new_values' => $log->getNewValues() ?? '',
					'capture_source' => $entry['captureSource'] ?? '',
					'client_occurred_at' => $entry['clientOccurredAtIso'] ?? '',
					'is_offline_sync' => !empty($entry['isOfflineSync']) ? '1' : '0',
				];
			}

			$filename = 'audit-logs-export-' . date('Y-m-d') . '.' . $format;

			return match ($format) {
				'csv' => $this->exportAsCsv($exportData, $filename),
				'json' => $this->exportAsJson($exportData, $filename),
				default => $this->exportAsCsv($exportData, $filename),
			};
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::exportAuditLogs: ' . $e->getMessage(), ['exception' => $e]);
			throw new \Exception($this->l10n->t('Failed to export audit logs.'));
		}
	}

	// ---------- Admin Teams (app-owned teams/departments) ----------

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function teams(): TemplateResponse
	{
		$this->registerFrontEndAssets('admin-teams', 'admin-teams', [], ['common/admin-user-picker']);
		$useAppTeams = $this->appConfig->getAppValueString('use_app_teams', '0') === '1';

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-teams', $this->buildAdminShellParams(
			'admin-teams',
			$this->l10n->t('Teams'),
			$this->l10n->t('Define organizational units, assign members, and designate managers for approvals.'),
		) + [
			'useAppTeams' => $useAppTeams,
			'adminTeamsUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.teams'),
			'adminUserSearchUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.getUsers'),
		]);
		return $this->configureCSP($response, 'admin');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getTeams(): JSONResponse
	{
		try {
			$teams = $this->teamMapper->findAll();
			$tree = $this->buildTeamTree($teams, null);
			return new JSONResponse(['success' => true, 'teams' => $tree]);
		} catch (\Throwable $e) {
			if ($this->isMissingTableException($e)) {
				\OCP\Log\logger('arbeitszeitcheck')->info('Admin teams table not yet available, returning empty teams.');
				return new JSONResponse(['success' => true, 'teams' => []]);
			}
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::getTeams: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Locale-independent detection of "table/object does not exist" errors.
	 *
	 * Inspects Nextcloud's DB exception reason codes (which the DBAL wrapper
	 * provides on every supported database) rather than parsing localised
	 * driver error messages. The string fallback only fires for non-DBAL
	 * paths (e.g. test doubles raising raw PDO exceptions).
	 */
	private function isMissingTableException(\Throwable $e): bool
	{
		if ($e instanceof DBException && $e->getReason() === DBException::REASON_DATABASE_OBJECT_NOT_FOUND) {
			return true;
		}
		$previous = $e->getPrevious();
		if ($previous instanceof DBException && $previous->getReason() === DBException::REASON_DATABASE_OBJECT_NOT_FOUND) {
			return true;
		}
		$msg = (string)$e->getMessage();
		return str_contains($msg, "doesn't exist")
			|| str_contains($msg, 'does not exist')
			|| str_contains($msg, 'no such table')
			|| str_contains($msg, 'undefined table');
	}

	/**
	 * Get a summary of what will be affected if a team is deleted.
	 *
	 * Returns counts of members, managers, and direct sub-teams. This is used
	 * by the admin UI to present a clear, WCAG-compliant confirmation dialog
	 * before performing the destructive action.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getTeamDeleteImpact(int $id): JSONResponse
	{
		try {
			$team = $this->teamMapper->find($id);

			$members = $this->teamMemberMapper->findByTeamId($id);
			$managers = $this->teamManagerMapper->findByTeamId($id);
			$children = $this->teamMapper->findByParentId($id);

			return new JSONResponse([
				'success' => true,
				'impact' => [
					'teamId' => $team->getId(),
					'teamName' => $team->getName(),
					'memberCount' => count($members),
					'managerCount' => count($managers),
					'childTeamCount' => count($children),
				],
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Team not found'),
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::getTeamDeleteImpact: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/** @param Team[] $teams */
	private function buildTeamTree(array $teams, ?int $parentId): array
	{
		$out = [];
		foreach ($teams as $team) {
			if ($team->getParentId() !== $parentId) {
				continue;
			}
			$node = $team->getSummary();
			$node['children'] = $this->buildTeamTree($teams, $team->getId());
			$out[] = $node;
		}
		return $out;
	}

	#[NoAdminRequired]
	public function createTeam(): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$name = trim((string)($params['name'] ?? ''));
			$parentId = isset($params['parentId']) ? (int)$params['parentId'] : null;
			if ($parentId === 0) {
				$parentId = null;
			}
			$sortOrder = isset($params['sortOrder']) ? (int)$params['sortOrder'] : 0;
			if ($name === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team name is required')], Http::STATUS_BAD_REQUEST);
			}
			$team = new Team();
			$team->setName($name);
			$team->setParentId($parentId);
			$team->setSortOrder($sortOrder);
			$team->setCreatedAt(new \DateTime());
			$inserted = $this->teamMapper->insert($team);
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$performedBy,
				'team_created',
				'team',
				$inserted->getId(),
				null,
				$inserted->getSummary(),
				$performedBy
			);
			return new JSONResponse(['success' => true, 'team' => $inserted->getSummary()], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::createTeam: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function updateTeam(int $id): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$team = $this->teamMapper->find($id);
			$name = trim((string)($params['name'] ?? ''));
			$parentId = isset($params['parentId']) ? (int)$params['parentId'] : null;
			if ($parentId === 0) {
				$parentId = null;
			}
			$sortOrder = isset($params['sortOrder']) ? (int)$params['sortOrder'] : 0;
			if ($name === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team name is required')], Http::STATUS_BAD_REQUEST);
			}
			if ($parentId === $id) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('A team cannot be its own parent')], Http::STATUS_BAD_REQUEST);
			}
			$oldValues = $team->getSummary();
			$team->setName($name);
			$team->setParentId($parentId);
			$team->setSortOrder($sortOrder);
			$this->teamMapper->update($team);
			$newValues = $team->getSummary();
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$performedBy,
				'team_updated',
				'team',
				$id,
				$oldValues,
				$newValues,
				$performedBy
			);
			return new JSONResponse(['success' => true, 'team' => $team->getSummary()]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::updateTeam: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function deleteTeam(int $id): JSONResponse
	{
		try {
			$team = $this->teamMapper->find($id);
			$children = $this->teamMapper->findByParentId($id);
			if (count($children) > 0) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Cannot delete a team that has sub-teams. Move or delete sub-teams first.')
				], Http::STATUS_BAD_REQUEST);
			}
			$oldValues = $team->getSummary();
			$performedBy = $this->getPerformedBy();
			$this->teamMemberMapper->deleteByTeamId($id);
			$this->teamManagerMapper->deleteByTeamId($id);
			$this->teamMapper->delete($team);
			$this->auditLogMapper->logAction(
				$performedBy,
				'team_deleted',
				'team',
				$id,
				$oldValues,
				null,
				$performedBy
			);
			return new JSONResponse(['success' => true]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::deleteTeam: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getTeamMembers(int $id): JSONResponse
	{
		try {
			$this->teamMapper->find($id);
			$members = $this->teamMemberMapper->findByTeamId($id);
			$list = [];
			foreach ($members as $m) {
				$u = $this->userManager->get($m->getUserId());
				$list[] = ['userId' => $m->getUserId(), 'displayName' => $u ? $u->getDisplayName() : $m->getUserId()];
			}
			return new JSONResponse(['success' => true, 'members' => $list]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function addTeamMember(int $id): JSONResponse
	{
		try {
			$userId = (string)($this->request->getParams()['userId'] ?? '');
			if ($userId === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User is required')], Http::STATUS_BAD_REQUEST);
			}
			$team = $this->teamMapper->find($id);
			$user = $this->userManager->get($userId);
			if ($user === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not found')], Http::STATUS_BAD_REQUEST);
			}
			if (!$user->isEnabled()) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User account is disabled')], Http::STATUS_BAD_REQUEST);
			}
			$existing = $this->teamMemberMapper->findByTeamId($id);
			foreach ($existing as $m) {
				if ($m->getUserId() === $userId) {
					return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User is already a member of this team')], Http::STATUS_BAD_REQUEST);
				}
			}
			$this->teamMemberMapper->addMember($id, $userId);
			$u = $this->userManager->get($userId);
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$userId,
				'team_member_added',
				'team_member',
				$id,
				null,
				['teamId' => $id, 'teamName' => $team->getName(), 'userId' => $userId],
				$performedBy
			);
			return new JSONResponse(['success' => true, 'member' => ['userId' => $userId, 'displayName' => $u ? $u->getDisplayName() : $userId]]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::addTeamMember: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function removeTeamMember(int $id, string $userId): JSONResponse
	{
		try {
			$team = $this->teamMapper->find($id);
			$performedBy = $this->getPerformedBy();
			$this->teamMemberMapper->removeMember($id, $userId);
			$this->auditLogMapper->logAction(
				$userId,
				'team_member_removed',
				'team_member',
				$id,
				['teamId' => $id, 'teamName' => $team->getName(), 'userId' => $userId],
				null,
				$performedBy
			);
			return new JSONResponse(['success' => true]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getTeamManagers(int $id): JSONResponse
	{
		try {
			$this->teamMapper->find($id);
			$managers = $this->teamManagerMapper->findByTeamId($id);
			$list = [];
			foreach ($managers as $m) {
				$u = $this->userManager->get($m->getUserId());
				$list[] = ['userId' => $m->getUserId(), 'displayName' => $u ? $u->getDisplayName() : $m->getUserId()];
			}
			return new JSONResponse(['success' => true, 'managers' => $list]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function addTeamManager(int $id): JSONResponse
	{
		try {
			$userId = (string)($this->request->getParams()['userId'] ?? '');
			if ($userId === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User is required')], Http::STATUS_BAD_REQUEST);
			}
			$team = $this->teamMapper->find($id);
			$user = $this->userManager->get($userId);
			if ($user === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not found')], Http::STATUS_BAD_REQUEST);
			}
			if (!$user->isEnabled()) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User account is disabled')], Http::STATUS_BAD_REQUEST);
			}
			$existing = $this->teamManagerMapper->findByTeamId($id);
			foreach ($existing as $m) {
				if ($m->getUserId() === $userId) {
					return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User is already a manager of this team')], Http::STATUS_BAD_REQUEST);
				}
			}
			$this->teamManagerMapper->addManager($id, $userId);
			$u = $this->userManager->get($userId);
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$userId,
				'team_manager_added',
				'team_manager',
				$id,
				null,
				['teamId' => $id, 'teamName' => $team->getName(), 'userId' => $userId],
				$performedBy
			);
			return new JSONResponse(['success' => true, 'manager' => ['userId' => $userId, 'displayName' => $u ? $u->getDisplayName() : $userId]]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::addTeamManager: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function removeTeamManager(int $id, string $userId): JSONResponse
	{
		try {
			$team = $this->teamMapper->find($id);
			$performedBy = $this->getPerformedBy();
			$this->teamManagerMapper->removeManager($id, $userId);
			$this->auditLogMapper->logAction(
				$userId,
				'team_manager_removed',
				'team_manager',
				$id,
				['teamId' => $id, 'teamName' => $team->getName(), 'userId' => $userId],
				null,
				$performedBy
			);
			return new JSONResponse(['success' => true]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function addTeamMembersBatch(int $id): JSONResponse
	{
		$params = $this->request->getParams();
		$result = $this->batchMutationService()->addTeamMembersBatch(
			$id,
			$params['userIds'] ?? null,
			$this->getPerformedBy()
		);
		return $this->batchMutationJsonResponse($result, 'members');
	}

	#[NoAdminRequired]
	public function addTeamManagersBatch(int $id): JSONResponse
	{
		$params = $this->request->getParams();
		$result = $this->batchMutationService()->addTeamManagersBatch(
			$id,
			$params['userIds'] ?? null,
			$this->getPerformedBy()
		);
		return $this->batchMutationJsonResponse($result, 'managers');
	}

	#[NoAdminRequired]
	public function batchUpdateUserProfiles(): JSONResponse
	{
		$params = $this->request->getParams();
		$fields = is_array($params['fields'] ?? null) ? $params['fields'] : [];
		$dryRun = !empty($params['dryRun']) || !empty($params['dry_run']);
		$result = $this->batchMutationService()->batchProfile(
			$params['userIds'] ?? null,
			$fields,
			$this->getPerformedBy(),
			$dryRun
		);
		return $this->batchMutationJsonResponse($result);
	}

	#[NoAdminRequired]
	public function batchAssignVacationPolicy(): JSONResponse
	{
		$params = $this->request->getParams();
		$policy = is_array($params['vacationPolicy'] ?? null) ? $params['vacationPolicy'] : [];
		$dryRun = !empty($params['dryRun']) || !empty($params['dry_run']);
		$result = $this->batchMutationService()->batchVacationPolicy(
			$params['userIds'] ?? null,
			$policy,
			$this->getPerformedBy(),
			$dryRun
		);
		return $this->batchMutationJsonResponse($result);
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function batchMutationJsonResponse(array $result, ?string $listKey = null): JSONResponse
	{
		if (empty($result['ok'])) {
			$error = (string)($result['error'] ?? 'unknown');
			$status = (int)($result['httpStatus'] ?? Http::STATUS_BAD_REQUEST);
			$message = match ($error) {
				'user_ids_required' => $this->l10n->t('Select at least one person.'),
				'batch_too_large' => $this->l10n->t('Too many people selected (maximum %s).', [(string)Constants::MAX_BATCH_USERS]),
				'team_not_found' => $this->l10n->t('Team not found'),
				'fields_required' => $this->l10n->t('Choose a work schedule and/or holiday region to apply.'),
				'vacation_policy_required' => $this->l10n->t('Vacation policy is required.'),
				'validation_failed' => (string)($result['message'] ?? $this->l10n->t('Validation failed.')),
				default => $this->l10n->t('Could not complete the bulk action.'),
			};
			return new JSONResponse([
				'success' => false,
				'error' => $error,
				'message' => $message,
			], $status);
		}

		$payload = [
			'success' => true,
			'summary' => $result['summary'] ?? [],
			'results' => $result['results'] ?? [],
		];
		if (isset($result['dryRun'])) {
			$payload['dryRun'] = $result['dryRun'];
		}
		if ($listKey !== null && isset($result[$listKey])) {
			$payload[$listKey] = $result[$listKey];
		}
		return new JSONResponse($payload);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getTeamsUseAppTeams(): JSONResponse
	{
		$use = $this->appConfig->getAppValueString('use_app_teams', '0') === '1';
		return new JSONResponse(['success' => true, 'useAppTeams' => $use]);
	}

	#[NoAdminRequired]
	public function setTeamsUseAppTeams(): JSONResponse
	{
		$params = $this->request->getParams();
		$use = !empty($params['useAppTeams']);
		$this->appConfig->setAppValueString('use_app_teams', $use ? '1' : '0');
		return new JSONResponse(['success' => true, 'useAppTeams' => $use]);
	}
}
