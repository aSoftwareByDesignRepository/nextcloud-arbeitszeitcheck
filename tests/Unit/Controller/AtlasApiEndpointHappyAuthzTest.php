<?php

declare(strict_types=1);

/**
 * Atlas API endpoint sweep — happy-path + in-action authz-negative proof.
 *
 * Mirrors nextcloud/apps/mobilitycheck/tests/Unit/Controller/AtlasApiEndpointHappyAuthzTest.php
 * adapted to arbeitszeitcheck's deny vocabulary:
 *   - app-access gate:    AppAccessMiddleware   -> PermissionService::isUserAllowedByAccessGroups
 *   - admin gate:         AppAdminMiddleware    -> PermissionService::isAdmin
 *   - in-action checks:   PermissionService canX / canViewX / canResolveViolation
 *   - kiosk auth:         KioskTerminalService::validateTerminalToken + KioskAuthService
 *   - mobile seat:        MobileSeatService::isUserAllowed
 *
 * Unit-level calls bypass middleware; middleware denies are proven by the
 * already-green AppAdminAuthorizationIntegrationTest / AppAccessGateIntegrationTest.
 * This sweep proves: every routed action returns 2xx/3xx when allowed
 * (happy path) and every action with an in-action check denies (>=400 or
 * deny exception, including arz's uniform-404 deny convention) when denied.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\AbsenceController;
use OCA\ArbeitszeitCheck\Controller\AdminController;
use OCA\ArbeitszeitCheck\Controller\ComplianceController;
use OCA\ArbeitszeitCheck\Controller\DashboardWidgetController;
use OCA\ArbeitszeitCheck\Controller\ExportController;
use OCA\ArbeitszeitCheck\Controller\GdprController;
use OCA\ArbeitszeitCheck\Controller\HealthController;
use OCA\ArbeitszeitCheck\Controller\HolidayController;
use OCA\ArbeitszeitCheck\Controller\KioskAdminController;
use OCA\ArbeitszeitCheck\Controller\KioskController;
use OCA\ArbeitszeitCheck\Controller\LicenseAdminController;
use OCA\ArbeitszeitCheck\Controller\ManagerController;
use OCA\ArbeitszeitCheck\Controller\MobileBootstrapController;
use OCA\ArbeitszeitCheck\Controller\MonthClosureController;
use OCA\ArbeitszeitCheck\Controller\OutlookIcalSubscriptionController;
use OCA\ArbeitszeitCheck\Controller\OvertimePayoutController;
use OCA\ArbeitszeitCheck\Controller\PageController;
use OCA\ArbeitszeitCheck\Controller\ReportController;
use OCA\ArbeitszeitCheck\Controller\SettingsController;
use OCA\ArbeitszeitCheck\Controller\SubstituteController;
use OCA\ArbeitszeitCheck\Controller\TimeEntryController;
use OCA\ArbeitszeitCheck\Controller\TimeTrackingController;
use OCA\ArbeitszeitCheck\Exception\AppAccessDeniedException;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\NotAppAdminException;
use OCA\ArbeitszeitCheck\Exception\OutlookIcalSubscriptionAuthException;
use OCA\ArbeitszeitCheck\Exception\OutlookIcalSubscriptionBadRequestException;
use OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException;
use OCA\ArbeitszeitCheck\Middleware\KioskUnauthorizedException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskAuthService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskTerminalService;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\MobileSeatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TerminalDeviceService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

final class AtlasApiEndpointHappyAuthzTest extends TestCase
{
	private const CONTROLLERS = [
		PageController::class,
		TimeTrackingController::class,
		DashboardWidgetController::class,
		MobileBootstrapController::class,
		TimeEntryController::class,
		AbsenceController::class,
		ManagerController::class,
		SubstituteController::class,
		ComplianceController::class,
		HolidayController::class,
		ReportController::class,
		SettingsController::class,
		AdminController::class,
		LicenseAdminController::class,
		KioskAdminController::class,
		KioskController::class,
		OvertimePayoutController::class,
		OutlookIcalSubscriptionController::class,
		ExportController::class,
		GdprController::class,
		HealthController::class,
		MonthClosureController::class,
	];

	/**
	 * Actions whose happy path is a designed non-2xx (permanent stubs,
	 * deliberately disabled routes). Empty unless proven otherwise — the
	 * sweep asserts these still reject cleanly (>=400, no crash) while every
	 * other action must return a happy 2xx/3xx.
	 *
	 * @var list<string>
	 */
	private const HAPPY_PATH_PERMANENT_NA = [
	];

	/**
	 * Actions whose authz contract is ENTITY OWNERSHIP, not a PermissionService
	 * gate. They run with allow=true (permissions permissive) plus a
	 * foreign-owned ('mallory') entity — so a deny can only have come from the
	 * in-action ownership check. Everything else in AUTHZ_ACTIONS runs
	 * allow=false and is satisfied by whichever in-action gate fires first.
	 */
	private const AUTHZ_OWNER_CHECK_ACTIONS = [
		'AbsenceController::delete',
		'TimeEntryController::show',
		'TimeEntryController::update',
		'TimeEntryController::getDeletionImpact',
		'TimeEntryController::requestCorrection',
		'TimeEntryController::cancelCorrection',
		'TimeEntryController::delete',
		'TimeEntryController::apiShow',
		'TimeEntryController::apiUpdate',
		'TimeEntryController::apiDelete',
	];

	/**
	 * Actions with an in-action deny path (PermissionService canX/isX,
	 * kiosk token/auth, mobile seat) that must produce >=400 or a deny
	 * exception when the caller lacks rights. Derived per action by code
	 * inspection; actions gated only by middleware/attributes are NOT in
	 * this list — their deny proof lives in the green integration tests.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const AUTHZ_ACTIONS = [
		AbsenceController::class => [
			// 'cancel' denies via 303 page redirect (designed HTML UX — the deny
			// lands in middleware, not a status code); 'delete'/'approve'/'reject'
			// are JSON denies we can assert here. approve/reject gate on
			// canManageEmployee; delete on ownership (foreign entity 'mallory').
			'delete', 'approve', 'reject',
		],
		ComplianceController::class => [
			// 'dashboard'/'violations' are pages that render an error panel —
			// the JSON APIs below carry the real in-action deny contract.
			'getViolations', 'getViolation', 'resolveViolation', 'getReport', 'runCheck',
		],
		ExportController::class => [
			// 'datev' delivers a deny as an error CSV download (payroll UX) —
			// asserted via the CSV deny-marker branch below. 'datevConfig' 403s.
			'datev', 'datevConfig',
		],
		KioskController::class => [
			// requireTerminal — validateTerminalToken deny throws KioskUnauthorizedException
			'config', 'users', 'identify', 'action', 'stamp', 'heartbeat', 'enrollScan',
		],
		ManagerController::class => [
			// Page actions (dashboard, employeeTimeEntriesPage, employeeAbsencesPage,
			// monthClosuresPage) deny via 303 redirect — middleware-level, not
			// asserted here. The JSON API actions carry the in-action contract.
			'getManagedTeams', 'revisionPdfUsers', 'revisionPdfAvailableMonths',
			'revisionPdfUsersForMonth', 'getTeamOverview', 'getScopedEmployees',
			'getEmployeeTimeEntries', 'getEmployeeAbsences', 'estimateEmployeeVacationHours',
			'createEmployeeAbsence', 'getManagerAssignableProjectcheckProjects',
			'createEmployeeTimeEntry', 'getPendingApprovals', 'getTeamOvertimeAlerts',
			'exportTeamOvertimeCsv', 'getTeamCompliance', 'getTeamHoursSummary',
			'approveAbsence', 'rejectAbsence', 'approveTimeEntryCorrection',
			'rejectTimeEntryCorrection', 'correctTimeEntry', 'getPendingTimeEntryCorrections',
			'getTeamAbsenceCalendar',
		],
		MonthClosureController::class => [
			// isAdmin gate
			'reopen',
		],
		OutlookIcalSubscriptionController::class => [
			// requireAppAdmin -> PermissionService::isAdmin; feed scope authz via
			// foreign-owned token record (manager mismatch -> AuthException)
			'adminWebcalLocalAccess', 'adminEnableWebcalLocalAccess', 'adminTeams',
			'adminActiveSubscriptions', 'adminCreateToken', 'adminCreateSubscriptionLink',
			'adminRotateToken', 'tokenizedFeed', 'tokenizedFeedLegacy', 'authenticatedFeed',
		],
		ReportController::class => [
			// canViewUserReport / isAdmin / canAccessManagerDashboard
			'daily', 'weekly', 'monthly', 'overtime', 'absence', 'team', 'premium',
		],
		TimeEntryController::class => [
			// 'edit' denies by re-rendering the list page with an error panel —
			// designed page UX. The JSON/mutation actions below carry the
			// in-action ownership-deny contract (foreign entity 'mallory').
			'show', 'update', 'getDeletionImpact', 'requestCorrection',
			'cancelCorrection', 'delete', 'apiShow', 'apiUpdate', 'apiDelete',
		],
		AdminController::class => [
			// in-action isAdmin gate. (addTeamMember/addTeamManager and most Admin
			// mutations are AppAdminMiddleware-gated — proven by integration tests.)
			'migrateVacationUnit',
		],
	];

	/** @var array<string, object> */
	private array $byType = [];

	/** @var array<string, bool> */
	private array $instantiating = [];

	/** @var array<string, object> */
	private array $entityMocks = [];

	/**
	 * Authz-deny mode: owned entities belong to foreign user 'mallory', so
	 * in-action ownership checks (entity->getUserId() !== actor 'alice')
	 * actually exercise the deny branch instead of silently passing.
	 */
	private bool $foreignOwnedEntities = false;

	/**
	 * "ControllerShortName::action" currently being invoked — dynamic stubs
	 * consult the per-action override maps below for action-specific fixtures.
	 */
	private string $currentAction = '';

	/**
	 * Global collaborator method fixtures: 'FQCN::method' => return value.
	 * Applied to every controller — methods whose generic default shape is
	 * semantically wrong for ANY caller (policy/decision arrays etc.).
	 */
	/**
	 * App-config key fixtures consulted by IConfig/IAppConfig stubs — unknown
	 * keys fall through to the caller-supplied default so production defaults
	 * stay authoritative.
	 */
	private const APP_CONFIG = [
		'month_closure_enabled' => '1',
		'instanceid' => 'atlas-instance',
	];

	/** @var array<string, mixed>|null */
	private ?array $depOverrides = null;

	/**
	 * Global collaborator method fixtures: 'FQCN::method' => return value.
	 * Applied to every controller — methods whose generic default shape is
	 * semantically wrong for ANY caller (policy/decision arrays etc.).
	 *
	 * @return array<string, mixed>
	 */
	private function depOverrides(): array
	{
		return $this->depOverrides ??= [
			'OCA\ArbeitszeitCheck\Service\ComplianceService::checkRestPeriodForStartTime' => ['valid' => true, 'message' => ''],
			'OCA\ArbeitszeitCheck\Service\ComplianceService::blockingIssuesForCompletedEntry' => [],
			'OCA\ArbeitszeitCheck\Service\ComplianceService::checkComplianceForCompletedEntry' => [],
			'OCA\ArbeitszeitCheck\Service\TimeEntryDeletionPolicy::evaluate' => ['canDelete' => true, 'blockMessage' => null, 'blockCode' => null],
			'OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService::validateProposal' => null,
			'OCA\ArbeitszeitCheck\Db\TimeEntry::validate' => [],
			'OCA\ArbeitszeitCheck\Service\DashboardWidgetDataService::getManagerWidgetData' => ['authorized' => true, 'pendingApprovals' => [], 'employees' => []],
			'OCA\ArbeitszeitCheck\Service\DashboardWidgetDataService::getAdminWidgetData' => ['authorized' => true, 'stats' => []],
			'OCA\ArbeitszeitCheck\Service\AbsenceService::getAbsence' => $this->entityMock(\OCA\ArbeitszeitCheck\Db\Absence::class),
			'OCA\ArbeitszeitCheck\Service\AbsenceService::createAbsence' => $this->entityMock(\OCA\ArbeitszeitCheck\Db\Absence::class),
			'OCA\ArbeitszeitCheck\Service\AbsenceService::updateAbsence' => $this->entityMock(\OCA\ArbeitszeitCheck\Db\Absence::class),
			'OCA\ArbeitszeitCheck\Service\AbsenceService::cancelAbsence' => $this->entityMock(\OCA\ArbeitszeitCheck\Db\Absence::class),
			'OCA\ArbeitszeitCheck\Service\AbsenceService::shortenAbsence' => $this->entityMock(\OCA\ArbeitszeitCheck\Db\Absence::class),
			'OCA\ArbeitszeitCheck\Service\AbsenceService::approveAbsence' => $this->entityMock(\OCA\ArbeitszeitCheck\Db\Absence::class),
			'OCA\ArbeitszeitCheck\Service\AbsenceService::rejectAbsence' => $this->entityMock(\OCA\ArbeitszeitCheck\Db\Absence::class),
			'OCA\ArbeitszeitCheck\Service\AbsenceService::approveBySubstitute' => $this->entityMock(\OCA\ArbeitszeitCheck\Db\Absence::class),
			'OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionService::createToken' => [
				'token' => 'tok', 'subscriptionId' => 1, 'eventCount' => 1,
				'windowStart' => '2026-09-01', 'windowEnd' => '2027-09-01',
				'feedLanguageCode' => 'en',
			],
			'OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionService::rotateToken' => [
				'token' => 'tok', 'subscriptionId' => 1, 'eventCount' => 1,
				'windowStart' => '2026-09-01', 'windowEnd' => '2027-09-01',
				'feedLanguageCode' => 'en',
			],
			'OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionService::buildTokenizedFeed' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR",
			'OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionService::buildAuthenticatedFeed' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR",
			'OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionService::listActiveSubscriptionsForAdmin' => [],
			'OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine::computeForDate' => [
				'trace' => [], 'days' => 20, 'hours' => 160, 'source' => 'manual',
				'ruleSetId' => 1, 'summary' => [],
			],
			'OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine::redactTraceForUser' => [],
			'OCA\ArbeitszeitCheck\Service\TeamResolverService::getTeamMemberIds' => ['bob'],
			'OCA\ArbeitszeitCheck\Db\WorkingTimeModel::validate' => [],
			'OCA\ArbeitszeitCheck\Db\TariffRuleSet::validate' => [],
			'OCA\ArbeitszeitCheck\Service\TimeZoneService::nowAsIso' => '2026-09-10T10:00:00Z',
			'OCA\ArbeitszeitCheck\Service\TimeZoneService::storageTimeZoneName' => 'UTC',
			'OCA\ArbeitszeitCheck\Service\Kiosk\KioskEnrollmentService::completeScan' => ['scanId' => 1],
			'OCA\ArbeitszeitCheck\Service\Kiosk\KioskOfflineStampService::stampRfid' => ['success' => true],
			'OCA\ArbeitszeitCheck\Service\Kiosk\KioskActionService::performAction' => ['ok' => true],
			'OCA\ArbeitszeitCheck\Service\OvertimeService::getDailyOvertime' => ['overtime_hours' => 0.0],
			'OCA\ArbeitszeitCheck\Service\OvertimeService::calculateOvertime' => ['overtime_hours' => 0.0, 'total_hours_worked' => 8.0],
			'OCA\ArbeitszeitCheck\Service\ComplianceService::getComplianceStatus' => ['compliant' => true, 'score' => 100, 'warning_violations' => []],
			'OCA\ArbeitszeitCheck\Service\TimeTrackingService::getStatus' => ['status' => 'clocked_out'],
		];
	}

	/** @var array<string, array<string, mixed>>|null */
	private ?array $actionDepOverrides = null;

	/**
	 * Per-action collaborator overrides (lazy — values may be Closures that
	 * build entity mocks at call time):
	 * 'Controller::action' => ['DepFQCN::method' => return value|\Closure].
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function actionDepOverrides(): array
	{
		return $this->actionDepOverrides ??= [
			// Token creation must see a free scope/language slot; rotation must
			// see an existing subscription (default nullable->mock provides it).
			'OutlookIcalSubscriptionController::adminCreateToken' => [
				'OCA\ArbeitszeitCheck\Db\OutlookIcalSubscriptionTokenMapper::findForScopeLanguage' => null,
			],
			'OutlookIcalSubscriptionController::adminCreateSubscriptionLink' => [
				'OCA\ArbeitszeitCheck\Db\OutlookIcalSubscriptionTokenMapper::findForScopeLanguage' => null,
			],
			'AdminController::createTariffRuleSet' => [
				'OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper::findByCodeAndVersion' => null,
			],
			'AdminController::activateTariffRuleSet' => [
				'OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper::findByRuleSetId' => fn () => [
					$this->entityMock(\OCA\ArbeitszeitCheck\Db\TariffRuleModule::class),
				],
				// Declared methods (not __call) — the entity-override map does not reach them.
				'OCA\ArbeitszeitCheck\Db\TariffRuleModule::getModuleType' => 'base_formula',
				'OCA\ArbeitszeitCheck\Db\TariffRuleModule::getConfig' => [
					'reference_days' => 260,
					'reference_week_days' => 5,
				],
			],
		];
	}

	/**
	 * Per-action entity getter overrides:
	 * 'Controller::action' => ['getterprop' (lowercased) => value].
	 */
	private const ACTION_ENTITY_OVERRIDES = [
		'TimeEntryController::cancelCorrection' => ['status' => 'pending_approval'],
		'AbsenceController::approve' => ['status' => 'pending'],
		'AbsenceController::reject' => ['status' => 'pending'],
		'ManagerController::approveAbsence' => ['status' => 'pending'],
		'ManagerController::rejectAbsence' => ['status' => 'pending'],
		'ManagerController::approveTimeEntryCorrection' => ['status' => 'pending_approval'],
		'ManagerController::rejectTimeEntryCorrection' => ['status' => 'pending_approval'],
		'AdminController::deleteWorkingTimeModel' => ['isdefault' => false, 'default' => false],
		'AdminController::deleteTariffRuleSet' => ['status' => 'draft'],
		'AdminController::updateTariffRuleSet' => ['status' => 'draft'],
		'AdminController::activateTariffRuleSet' => [
			'status' => 'draft',
			'moduletype' => 'base_formula',
			'config' => ['reference_days' => 260, 'reference_week_days' => 5],
		],
		'AdminController::retireTariffRuleSet' => ['status' => 'active'],
	];

	/**
	 * Per-action request params: 'Controller::action' => ['param' => value].
	 */
	private const ACTION_PARAMS = [
		'AdminController::createWorkingTimeModel' => ['type' => 'full_time'],
		'AdminController::updateWorkingTimeModel' => ['type' => 'full_time'],
		'AdminController::migrateVacationUnit' => ['targetUnit' => 'days'],
		'AdminController::createTariffRuleSet' => ['modules' => [['moduleType' => 'base_formula', 'config' => ['reference_days' => 260, 'reference_week_days' => 5]]]],
		'AdminController::updateTariffRuleSet' => ['modules' => [['moduleType' => 'base_formula', 'config' => ['reference_days' => 260, 'reference_week_days' => 5]]]],
		'OutlookIcalSubscriptionController::adminCreateToken' => ['teamId' => 1, 'languageCode' => 'en'],
		'OutlookIcalSubscriptionController::adminCreateSubscriptionLink' => ['teamId' => 1, 'languageCode' => 'en'],
		'OutlookIcalSubscriptionController::adminRotateToken' => ['teamId' => 1, 'languageCode' => 'en'],
	];

	/**
	 * Per-action request params that must NOT reach the action — the global
	 * fixture is broad, and endpoints like tariff create/update explicitly
	 * reject foreign fields via array_key_exists guards.
	 * 'Controller::action' => ['paramName', ...]
	 */
	private const ACTION_PARAM_REMOVALS = [
		'AdminController::createTariffRuleSet' => ['status', 'createdAt', 'updatedAt'],
		'AdminController::updateTariffRuleSet' => ['status', 'tariffCode', 'version'],
	];

	/**
	 * Per-action method-argument fixtures:
	 * 'Controller::action' => ['paramName' => value].
	 */
	private const ACTION_ARGS = [
		'TimeEntryController::checkOverlap' => [
			'startTime' => '2026-09-10T09:00:00Z',
			'endTime' => '2026-09-10T17:00:00Z',
		],
		'KioskController::stamp' => [
			'method' => 'rfid',
			'rfidUid' => 'rfid-1',
			'action' => 'clock_in',
			'clientRequestId' => 'req-1',
			'occurredAt' => '2026-09-10T10:00:00Z',
		],
		'KioskController::identify' => [
			'method' => 'rfid',
			'rfidUid' => 'rfid-1',
		],
		'OutlookIcalSubscriptionController::tokenizedFeed' => [
			'token' => 'tok',
			'teamId' => 1,
		],
		'OutlookIcalSubscriptionController::tokenizedFeedLegacy' => [
			'token' => 'tok',
			'teamId' => 1,
		],
		'AdminController::getStateHolidays' => ['state' => 'NW', 'year' => 2026],
		'AdminController::getHolidaySuggestions' => ['state' => 'NW', 'year' => 2026],
		'AdminController::saveStateHoliday' => ['state' => 'NW', 'year' => 2026],
		'AdminController::settingsSection' => ['section' => 'access'],
	];

	protected function setUp(): void
	{
		parent::setUp();
		$this->byType = [];
	}

	public function testEveryControllerActionHappyPathIs2xxOr3xx(): void
	{
		$proved = [];
		$failures = [];
		$permanentNa = [];
		foreach (self::CONTROLLERS as $class) {
			$this->byType = [];
			$this->foreignOwnedEntities = false;
			$ctrl = $this->buildController($class, allow: true, mode: 'happy');
			$ref = new ReflectionClass($class);
			foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->getName() === '__construct') {
					continue;
				}
				if ($method->isStatic()) {
					continue;
				}
				// DI-wiring setters from traits are not route actions.
				if ($method->getName() === 'setCspService') {
					continue;
				}
				$symbol = $ref->getShortName() . '::' . $method->getName();
				$this->currentAction = $symbol;
				if (in_array($symbol, self::HAPPY_PATH_PERMANENT_NA, true)) {
					try {
						$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
					} catch (\Throwable $e) {
						$failures[] = $symbol . ' permanent-na threw ' . $e::class . ': ' . $e->getMessage();
						continue;
					}
					if (!$result instanceof Response || $result->getStatus() >= 400 === false) {
						$status = $result instanceof Response ? $result->getStatus() : 0;
						$failures[] = $symbol . ' permanent-na expected >=400, got ' . $status;
						continue;
					}
					$permanentNa[] = $symbol;
					continue;
				}
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method));
				} catch (\Throwable $e) {
					$frames = array_slice($e->getTrace(), 0, 3);
					$where = [];
					foreach ($frames as $fr) {
						$where[] = ($fr['class'] ?? '') . ($fr['type'] ?? '') . ($fr['function'] ?? '') . '@' . basename((string)($fr['file'] ?? '')) . ':' . ($fr['line'] ?? 0);
					}
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage() . ' [' . implode(' <- ', $where) . ']';
					continue;
				}
				if (!$result instanceof Response) {
					$failures[] = $symbol . ' not Response';
					continue;
				}
				$status = $result->getStatus();
				// Honest happy only: 2xx/3xx. Never whitelist 4xx/5xx as designed-happy theater.
				$okStatus = ($status >= 200 && $status < 300) || ($status >= 300 && $status < 400);
				if (!$okStatus) {
					$body = '';
					if ($result instanceof DataResponse || $result instanceof JSONResponse) {
						$body = (string)json_encode($result->getData());
					}
					$failures[] = $symbol . ' status=' . var_export($status, true) . ' class=' . $result::class . ' body=' . $body;
					continue;
				}
				if ($status < 300 && ($result instanceof DataResponse || $result instanceof JSONResponse)) {
					$data = $result->getData();
					if (is_array($data)) {
						if (array_key_exists('ok', $data) && $data['ok'] !== true) {
							$failures[] = $symbol . ' ok=false body=' . json_encode($data);
							continue;
						}
						if (array_key_exists('success', $data) && $data['success'] !== true) {
							$failures[] = $symbol . ' success=false body=' . json_encode($data);
							continue;
						}
					}
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "Happy-path failures:\n" . implode("\n", $failures));
		self::assertEqualsCanonicalizing(
			self::HAPPY_PATH_PERMANENT_NA,
			$permanentNa,
			'permanent NA stubs must stay denied (api-matrix n_a)'
		);
		self::assertGreaterThanOrEqual(200, count($proved), 'expected >=200 controller actions, got ' . count($proved));
	}

	public function testAuthzNegativePerEndpointAction(): void
	{
		$proved = [];
		$failures = [];
		foreach (self::AUTHZ_ACTIONS as $class => $actions) {
			foreach ($actions as $action) {
				$this->byType = [];
				$this->foreignOwnedEntities = true;
				// Rebuild dep fixtures so cached entities are foreign-owned.
				$this->depOverrides = null;
				$this->actionDepOverrides = null;
				$ref = new ReflectionClass($class);
				$symbol = $ref->getShortName() . '::' . $action;
				// Ownership-gated actions keep permissions permissive so the deny
				// can only come from the in-action owner check.
				$allow = in_array($symbol, self::AUTHZ_OWNER_CHECK_ACTIONS, true);
				$ctrl = $this->buildController($class, allow: $allow, mode: 'authz');
				self::assertTrue($ref->hasMethod($action), $class . '::' . $action);
				$method = $ref->getMethod($action);
				$this->currentAction = $symbol;
				try {
					$result = $method->invokeArgs($ctrl, $this->dummyArgs($method, authz: true));
					if (!$result instanceof Response) {
						$failures[] = $symbol . ' not Response';
						continue;
					}
					if ($result instanceof DataDownloadResponse) {
						// Download endpoints deliver an authz deny as an error CSV
						// (designed payroll UX — e.g. ExportController::datev). The
						// deny is proven only if the CSV payload carries an error
						// marker; a silent happy CSV would be an authz hole.
						$csv = (string)$result->render();
						if (stripos($csv, 'denied') === false && stripos($csv, 'error') === false) {
							$failures[] = $symbol . ' deny CSV without error marker';
						}
						$proved[] = $symbol;
						continue;
					}
					$status = $result->getStatus();
					if ($status < 400) {
						$body = ($result instanceof DataResponse || $result instanceof JSONResponse)
							? (string)json_encode($result->getData()) : '';
						$failures[] = $symbol . ' deny status=' . $status . ' body=' . $body;
						continue;
					}
					if ($result instanceof DataResponse || $result instanceof JSONResponse) {
						$data = $result->getData();
						if (is_array($data)) {
							if (array_key_exists('ok', $data) && $data['ok'] !== false) {
								$failures[] = $symbol . ' deny envelope ok!=false';
								continue;
							}
							if (array_key_exists('success', $data) && $data['success'] !== false) {
								$failures[] = $symbol . ' deny envelope success!=false';
								continue;
							}
						}
					}
				} catch (AppAccessDeniedException|NotAppAdminException|TimeCaptureForbiddenException|KioskUnauthorizedException|KioskException|OutlookIcalSubscriptionAuthException|BusinessRuleException $e) {
					self::assertNotSame('', $e->getMessage(), $symbol);
				} catch (\Throwable $e) {
					$failures[] = $symbol . ' threw ' . $e::class . ': ' . $e->getMessage();
					continue;
				}
				$proved[] = $symbol;
			}
		}
		self::assertSame([], $failures, "AuthZ failures:\n" . implode("\n", $failures));
		self::assertGreaterThanOrEqual(0, count($proved));
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @param 'happy'|'authz' $mode
	 * @return T
	 */
	private function buildController(string $class, bool $allow, string $mode): object
	{
		$ref = new ReflectionClass($class);
		$ctor = $ref->getConstructor();
		self::assertNotNull($ctor);
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$name = $param->getName();
			$type = $param->getType();
			if ($name === 'appName') {
				$args[] = 'arbeitszeitcheck';
				continue;
			}
			if ($type === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			if ($type instanceof ReflectionNamedType && $type->getName() === IRequest::class) {
				$args[] = $this->request($mode, $class);
				continue;
			}
			$typeName = $this->resolveTypeName($type);
			if ($typeName === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
				$args[] = $param->isDefaultValueAvailable()
					? $param->getDefaultValue()
					: ($type->getName() === 'int' ? 1 : ($type->getName() === 'bool' ? true : 'x'));
				continue;
			}
			$args[] = $this->mockFor($typeName, $allow, $mode, $class);
		}
		return $ref->newInstanceArgs($args);
	}

	private function resolveTypeName(\ReflectionType $type): ?string
	{
		if ($type instanceof ReflectionNamedType) {
			return $type->isBuiltin() ? null : $type->getName();
		}
		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $t) {
				if ($t instanceof ReflectionNamedType && !$t->isBuiltin() && $t->getName() !== 'null') {
					return $t->getName();
				}
			}
		}
		return null;
	}

	/** @param class-string $controllerClass */
	private function mockFor(string $typeName, bool $allow, string $mode, string $controllerClass): object
	{
		$key = $typeName . ':' . ($allow ? '1' : '0') . ':' . $mode . ':' . $controllerClass;
		if (isset($this->byType[$key])) {
			return $this->byType[$key];
		}
		$denied = !$allow && $mode === 'authz';

		if ($typeName === PermissionService::class) {
			$mock = $this->createMock(PermissionService::class);
			$this->stubMethod($mock, 'isAdmin', $allow);
			$this->stubMethod($mock, 'isUserAllowedByAccessGroups', $allow);
			$this->stubMethod($mock, 'isAccessRestrictionEnabled', !$allow);
			$this->stubMethod($mock, 'canManageEmployee', $allow);
			$this->stubMethod($mock, 'canAccessManagerDashboard', $allow);
			$this->stubMethod($mock, 'canViewUserReport', $allow);
			$this->stubMethod($mock, 'canViewUserCompliance', $allow);
			$this->stubMethod($mock, 'canResolveViolation', $allow);
			$this->stubMethod($mock, 'getAllowedAccessGroups', []);
			$this->stubMethod($mock, 'getAllowedAccessUserIds', []);
			$this->stubMethod($mock, 'getConfiguredAppAdminUserIds', []);
			$this->stubMethod($mock, 'listAppAccessUserIds', []);
			$this->stubMethod($mock, 'hasRole', $allow);
			$this->stubMethod($mock, 'purgeUser', null);
			$this->stubMethod($mock, 'logPermissionDenied', null);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === LicenseService::class) {
			$mock = $this->createMock(LicenseService::class);
			$this->stubMethod($mock, 'isMobilePlanActive', $allow || $mode === 'happy');
			$this->stubMethod($mock, 'isTerminalPlanActive', true);
			$this->stubMethod($mock, 'buildEnvelope', ['format' => 'AZC1', 'payloadB64' => 'p', 'signatureB64' => 's']);
			$this->stubMethod($mock, 'getLicenseSummary', ['active' => true, 'dateValid' => true, 'plan' => 'mobile']);
			$this->stubMethod($mock, 'isStoredLicenseCryptographicallyValid', true);
			$this->stubMethod($mock, 'getMobileSeatLimit', 5);
			$this->stubMethod($mock, 'getTerminalDeviceLimit', 3);
			$this->stubMethod($mock, 'hasStoredLicense', true);
			$this->stubMethod($mock, 'getInstanceIdForBinding', 'inst');
			$this->stubMethod($mock, 'applyLicenseKey', true);
			$this->stubMethod($mock, 'getLastApplyErrorCode', '');
			$this->stubMethod($mock, 'getLastApplyErrorMessage', '');
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === MobileSeatService::class) {
			$mock = $this->createMock(MobileSeatService::class);
			$this->stubPublicMethodsWithOverrides($mock, MobileSeatService::class, [
				'isUserAllowed' => $allow,
			]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === KioskAuthService::class) {
			$mock = $this->createMock(KioskAuthService::class);
			if ($denied) {
				$this->stubThrow($mock, 'identify', new KioskUnauthorizedException('denied'));
				$this->stubThrow($mock, 'validateSession', new KioskUnauthorizedException('denied'));
				$this->stubThrow($mock, 'assertUserEligibleForAction', new KioskUnauthorizedException('denied'));
				$this->stubThrow($mock, 'resolveUserIdFromRfid', new KioskUnauthorizedException('denied'));
				$this->stubMethod($mock, 'listPinUsers', []);
			} else {
				$this->stubMethod($mock, 'identify', ['session' => ['id' => 1, 'token' => 'k'], 'user' => ['id' => 'alice']]);
				$this->stubMethod($mock, 'assertUserEligibleForAction', null);
				$this->stubMethod($mock, 'resolveUserIdFromRfid', 'alice');
				$this->stubMethod($mock, 'listPinUsers', []);
					$this->stubMethod($mock, 'validateSession', $this->createMock(\OCA\ArbeitszeitCheck\Db\KioskSession::class));
			}
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === KioskTerminalService::class) {
			$mock = $this->createMock(KioskTerminalService::class);
			if ($denied) {
				$this->stubPublicMethodsWithOverrides($mock, KioskTerminalService::class, [
					'validateTerminalToken' => null,
				]);
			} else {
				// KioskTerminal uses magic @method getters (__call) — only the
				// entityMock path stubs them; stubMethod() skips undeclared names.
				$terminal = $this->entityMock(\OCA\ArbeitszeitCheck\Db\KioskTerminal::class);
				$this->stubPublicMethodsWithOverrides($mock, KioskTerminalService::class, [
					'validateTerminalToken' => $terminal,
					'createPendingTerminal' => [
						'terminal' => $terminal,
						'pairingCode' => 'ABC123',
						'pairingExpiresAt' => '2026-09-11T10:00:00Z',
					],
					'recordHeartbeat' => null,
				]);
			}
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === TerminalDeviceService::class) {
			$mock = $this->createMock(TerminalDeviceService::class);
			$this->stubPublicMethodsWithOverrides($mock, TerminalDeviceService::class, [
				'getActiveCount' => 1,
				'getDeviceLimit' => 3,
				'hasCapacity' => true,
				'findAllActiveDevices' => [],
			]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IUserSession::class) {
			$mock = $this->createMock(IUserSession::class);
			$user = $this->createMock(IUser::class);
			$this->stubMethod($user, 'getUID', 'alice');
			$this->stubMethod($user, 'getDisplayName', 'Alice');
			$this->stubMethod($mock, 'getUser', $user);
			$this->stubMethod($mock, 'isLoggedIn', true);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IL10N::class) {
			$mock = $this->createMock(IL10N::class);
			$this->stubMethod($mock, 't', static function (string $text, array $params = []) {
				if ($params === []) {
					return $text;
				}
				try {
					return vsprintf($text, $params);
				} catch (\Throwable) {
					return $text;
				}
			});
			$this->stubMethod($mock, 'l', 'x');
			$this->stubMethod($mock, 'getLanguageCode', 'en');
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === 'OCP\L10N\IFactory' || $typeName === 'OCP\IL10NFactory' || str_ends_with($typeName, '\IFactory')) {
			$l10n = $this->createMock(IL10N::class);
			$this->stubMethod($l10n, 't', static function (string $text, array $params = []) {
				if ($params === []) {
					return $text;
				}
				try {
					return vsprintf($text, $params);
				} catch (\Throwable) {
					return $text;
				}
			});
			$this->stubMethod($l10n, 'l', 'x');
			$mock = $this->createMock($typeName);
			$this->stubMethod($mock, 'get', $l10n);
			$this->stubMethod($mock, 'getUserLanguage', 'en');
			$this->stubMethod($mock, 'findLanguage', 'en');
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IGroupManager::class) {
			$mock = $this->createMock(IGroupManager::class);
			$this->stubMethod($mock, 'isAdmin', $allow);
			$this->stubMethod($mock, 'getUserGroupIds', []);
			$group = $this->createMock(\OCP\IGroup::class);
			$this->stubMethod($mock, 'get', $group);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IURLGenerator::class) {
			$mock = $this->createMock(IURLGenerator::class);
			$this->stubMethod($mock, 'linkToRoute', 'https://x/apps/arbeitszeitcheck');
			$this->stubMethod($mock, 'linkToRouteAbsolute', 'https://x/apps/arbeitszeitcheck');
			$this->stubMethod($mock, 'linkTo', 'https://x/');
			$this->stubMethod($mock, 'getAbsoluteURL', 'https://x/');
			$this->stubMethod($mock, 'imagePath', '/img/x.svg');
			$this->stubMethod($mock, 'linkToDefaultPageUrl', 'https://x/');
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IConfig::class || $typeName === IAppConfig::class) {
			$mock = $this->createMock($typeName);
			foreach ([
				'getSystemValueBool' => false, 'getSystemValueInt' => 0,
				'getValueBool' => false, 'getValueInt' => 0, 'getValueFloat' => 0.0,
				'getValueStringArray' => [], 'getKeys' => [], 'getAppKeys' => [],
				'isSensitive' => false,
			] as $m => $ret) {
					$this->stubMethod($mock, $m, $ret);
			}
			// Key-aware getters: an arg matching APP_CONFIG wins, else the last
			// arg (the caller-supplied default) is returned unchanged.
			$keyed = static function (...$a) {
				foreach ($a as $arg) {
					if (is_string($arg) && array_key_exists($arg, self::APP_CONFIG)) {
						return self::APP_CONFIG[$arg];
					}
				}
				return end($a) ?: '';
			};
			foreach (['getAppValue', 'getUserValue', 'getValueString', 'getAppValueString', 'getUserValueString', 'getSystemValue', 'getSystemValueString'] as $m) {
				$this->stubMethod($mock, $m, $keyed);
			}
			$this->stubMethod($mock, 'getAppValueBool', static function (...$a) {
				foreach ($a as $arg) {
					if (is_string($arg) && array_key_exists($arg, self::APP_CONFIG)) {
						return self::APP_CONFIG[$arg] === '1';
					}
				}
				return (bool)end($a);
			});
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === ITimeFactory::class) {
			$mock = $this->createMock(ITimeFactory::class);
			$this->stubMethod($mock, 'getTime', 1760000000);
			$this->stubMethod($mock, 'now', new \DateTimeImmutable('2026-09-10T10:00:00Z'));
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === IThrottler::class) {
			$mock = $this->createMock(IThrottler::class);
			foreach (['registerAttempt', 'sleepDelay', 'sleepDelayOrThrowOnMax', 'resetDelay', 'getAttempts'] as $m) {
					$this->stubMethod($mock, $m, 0);
			}
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === \OCP\IAppManager::class) {
			$mock = $this->createMock(\OCP\IAppManager::class);
			$this->stubMethod($mock, 'isEnabledForUser', true);
			$this->stubMethod($mock, 'isInstalled', true);
			$this->stubMethod($mock, 'getAppInfo', []);
			$this->stubMethod($mock, 'getAppVersion', '1.0.0');
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === \OCP\IUserManager::class) {
			$mock = $this->createMock(\OCP\IUserManager::class);
			$user = $this->createMock(IUser::class);
			$this->stubMethod($user, 'getUID', 'bob');
			$this->stubMethod($user, 'getDisplayName', 'Bob');
			$this->stubMethod($user, 'isEnabled', true);
			$this->stubMethod($mock, 'get', $user);
			$this->stubMethod($mock, 'getDisplayName', 'Bob');
			$this->stubMethod($mock, 'userExists', true);
			$this->stubMethod($mock, 'search', [$user]);
			$this->stubMethod($mock, 'searchDisplayName', [$user]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === \OCA\ArbeitszeitCheck\Service\CSPService::class) {
			$mock = $this->createMock(\OCA\ArbeitszeitCheck\Service\CSPService::class);
			$this->stubPublicMethodsWithOverrides($mock, \OCA\ArbeitszeitCheck\Service\CSPService::class, [
				// decorator — pass the response through unchanged
				'applyPolicyWithNonce' => static fn ($response) => $response,
			]);
			$this->byType[$key] = $mock;
			return $mock;
		}

		if ($typeName === \OCP\ISession::class) {
			$mock = $this->createMock(\OCP\ISession::class);
			$this->stubMethod($mock, 'get', null);
			$this->stubMethod($mock, 'exists', false);
			$this->byType[$key] = $mock;
			return $mock;
		}

		// Default: generic mock with every declared public method stubbed to a
		// typed default return (arrays get a realistic entity payload, nullable
		// -> null, class-typed -> a fresh mock). Iteration failures on this path
		// are the evidence list for bespoke stubs above.
		try {
			$mock = $this->createMock($typeName);
			$this->stubPublicMethods($mock, $typeName);
			$this->byType[$key] = $mock;
			return $mock;
		} catch (\Throwable) {
		}
		// Final/readonly classes cannot be doubled — instantiate the real
		// object with recursively resolved ctor args instead.
		if (isset($this->instantiating[$typeName]) || count($this->instantiating) > 8) {
			self::fail('Circular ctor dep while instantiating ' . $typeName . ' for ' . $controllerClass);
		}
		$this->instantiating[$typeName] = true;
		try {
			$real = $this->instantiateClass($typeName, $allow, $mode, $controllerClass, []);
		} finally {
			unset($this->instantiating[$typeName]);
		}
		if ($real !== null) {
			$this->byType[$key] = $real;
			return $real;
		}
		self::fail('Cannot mock or instantiate ctor dep ' . $typeName . ' for ' . $controllerClass);
	}

	/**
	 * @param list<string> $chain recursion guard
	 */
	private function instantiateClass(string $typeName, bool $allow, string $mode, string $controllerClass, array $chain): ?object
	{
		if (in_array($typeName, $chain, true) || count($chain) > 8) {
			return null;
		}
		if (interface_exists($typeName) || !class_exists($typeName)) {
			return null;
		}
		$ref = new ReflectionClass($typeName);
		if ($ref->isAbstract() || !$ref->isInstantiable()) {
			return null;
		}
		$ctor = $ref->getConstructor();
		if ($ctor === null) {
			return $ref->newInstance();
		}
		$args = [];
		foreach ($ctor->getParameters() as $param) {
			$type = $param->getType();
			$name = $param->getName();
			if ($name === 'appName') {
				$args[] = 'arbeitszeitcheck';
				continue;
			}
			if ($type instanceof ReflectionNamedType && $type->getName() === IRequest::class) {
				$args[] = $this->request($mode, $controllerClass);
				continue;
			}
			if ($type === null || ($type instanceof ReflectionNamedType && $type->isBuiltin())) {
				if ($param->isDefaultValueAvailable()) {
					$args[] = $param->getDefaultValue();
				} elseif ($type instanceof ReflectionNamedType) {
					$args[] = match ($type->getName()) { 'int' => 1, 'bool' => true, 'float' => 1.0, 'array' => [], default => 'x' };
				} else {
					$args[] = null;
				}
				continue;
			}
			$dep = $this->resolveTypeName($type);
			if ($dep === null) {
				$args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
				continue;
			}
			$mock = $this->mockFor($dep, $allow, $mode, $controllerClass);
			$args[] = $mock;
		}
		try {
			return $ref->newInstanceArgs($args);
		} catch (\Throwable) {
			return null;
		}
	}

	/** @param MockObject&object $mock */
	private function stubPublicMethods(object $mock, string $typeName): void
	{
		$this->stubPublicMethodsWithOverrides($mock, $typeName, []);
	}

	/**
	 * @param MockObject&object $mock
	 * @param array<string, mixed> $overrides first-registration wins — callers
	 *        MUST supply overrides here rather than stubMethod() afterwards.
	 */
	private function stubPublicMethodsWithOverrides(object $mock, string $typeName, array $overrides): void
	{
		try {
			$sref = new ReflectionClass($typeName);
			foreach ($sref->getMethods(ReflectionMethod::IS_PUBLIC) as $sm) {
				// Stub inherited methods too — actions call mapper/service APIs
				// declared on parents (QBMapper::findEntity etc.).
				if ($sm->isConstructor() || $sm->isDestructor() || $sm->isStatic() || $sm->isFinal()) {
					continue;
				}
				$name = $sm->getName();
				$hasOverride = array_key_exists($name, $overrides);
				if (!$hasOverride) {
					if (str_starts_with($name, '__') || str_starts_with($name, 'phpunit')) {
						continue;
					}
					$ret = $sm->getReturnType();
					if ($ret instanceof ReflectionNamedType && $ret->getName() === 'void') {
						continue;
					}
				}
				$payload = $hasOverride
					? $overrides[$name]
					: $this->defaultReturn($sm->getReturnType(), $name, $typeName);
				// Wrapper methods (locks, transactions, atomic sections) take a
				// callable they must RUN — a static stub would starve the wrapped
				// action logic. Callback-accepting business methods (resolvers,
				// transformers) must keep their declared return instead.
				if (!$hasOverride && preg_match('/with|lock|transaction|atomic|guard|around|within|wrap/i', $name)) {
					foreach ($sm->getParameters() as $sp) {
						$st = $sp->getType();
						if (($st instanceof ReflectionNamedType && $st->getName() === 'callable')
							|| $st instanceof ReflectionUnionType && array_filter($st->getTypes(), fn ($t) => $t->getName() === 'callable')) {
							$payload = function () {
								foreach (func_get_args() as $a) {
									if (is_callable($a)) {
										// Supply values for the callable's required params
										// (e.g. name-resolvers, IDBConnection in transactional()).
										$cbArgs = [];
										try {
											$rf = $a instanceof \Closure ? new \ReflectionFunction($a) : new \ReflectionFunction(\Closure::fromCallable($a));
											foreach ($rf->getParameters() as $cp) {
												if ($cp->isOptional()) {
													break;
												}
												$ct = $cp->getType();
												$tn = $ct instanceof ReflectionNamedType ? $ct->getName() : '';
												$cbArgs[] = match (true) {
													$tn === 'string' => 'alice',
													$tn === 'int' => 1,
													$tn === 'bool' => true,
													$tn === 'float' => 1.0,
													$tn === 'array' => [],
													$tn !== '' && (class_exists($tn) || interface_exists($tn)) => $this->entityMock($tn),
													default => 'alice',
												};
											}
										} catch (\Throwable) {
										}
										return $a(...$cbArgs);
									}
								}
								return null;
							};
							break;
						}
					}
				}
				$this->stubMethod($mock, $name, $payload, $typeName);
			}
		} catch (\Throwable $e) {
			// A mid-loop abort silently leaves later methods unstubbed —
			// surface it as an assertion failure instead of STDERR noise.
			$this->fail("stubPublicMethodsWithOverrides($typeName) aborted: " . $e::class . ' ' . $e->getMessage());
		}
	}

	/**
	 * Guarded stub: never call $mock->method() for a non-configurable name —
	 * InvocationMocker::method() throws AFTER registering a matcher with a
	 * null methodNameRule, which poisons every subsequent invocation on the
	 * mock with MethodNameNotConfiguredException.
	 *
	 * @param MockObject&object $mock
	 */
	private function stubMethod(object $mock, string $name, mixed $ret, string $typeName = ''): void
	{
		if (!$this->isConfigurableMethod($mock, $name)) {
			return;
		}
		if ($ret instanceof \Closure) {
			$mock->method($name)->willReturnCallback($ret);
			return;
		}
		// Dynamic resolution: per-action and global dep overrides win over the
		// static default, so one mock can serve every action's fixture needs.
		$mock->method($name)->willReturnCallback(function (...$a) use ($name, $ret, $typeName) {
			$k = $typeName . '::' . $name;
			$ov = $this->actionDepOverrides()[$this->currentAction] ?? [];
			if ($typeName !== '' && array_key_exists($k, $ov)) {
				$v = $ov[$k];
				return $v instanceof \Closure ? $v(...$a) : $v;
			}
			if ($typeName !== '' && array_key_exists($k, $this->depOverrides())) {
				$v = $this->depOverrides()[$k];
				return $v instanceof \Closure ? $v(...$a) : $v;
			}
			return $ret;
		});
	}

	/** @param MockObject&object $mock */
	private function stubThrow(object $mock, string $name, \Throwable $e): void
	{
		if (!$this->isConfigurableMethod($mock, $name)) {
			return;
		}
		$mock->method($name)->willThrowException($e);
	}

	private function isConfigurableMethod(object $mock, string $name): bool
	{
		try {
			$ref = new \ReflectionMethod($mock, $name);
			if ($ref->isStatic() || $ref->isFinal()) {
				return false;
			}
			$ret = $ref->getReturnType();
			// void/never can't take willReturn — the mock's default no-op is correct.
			if ($ret instanceof ReflectionNamedType && in_array($ret->getName(), ['void', 'never'], true)) {
				return false;
			}
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function defaultReturn(?\ReflectionType $ret, string $name, string $typeName = ''): mixed
	{
		$entity = [
			'id' => 1,
			'ok' => true,
			'success' => true,
			'status' => 'active',
			'user_id' => 'alice',
			'items' => [],
			'total' => 0,
		];
		if ($ret instanceof ReflectionNamedType) {
			if ($ret->getName() === 'void') {
				return null;
			}
			if ($ret->getName() === 'array') {
				if (preg_match('/^(list|search|query|all|codes|options|names|pinned|grouped|find)/i', $name)
					|| str_ends_with(strtolower($name), 'list')
					|| str_ends_with(strtolower($name), 's')
					|| preg_match('/s(By|For|In|Of|With)[A-Z]/', $name)
				) {
					return [];
				}
				return $entity;
			}
			if ($ret->getName() === 'string') {
				return 'x';
			}
			if ($ret->getName() === 'int') {
				return 1;
			}
			if ($ret->getName() === 'bool') {
				return true;
			}
			if ($ret->getName() === 'float') {
				return 1.0;
			}
			if (!$ret->isBuiltin() && (class_exists($ret->getName()) || interface_exists($ret->getName()))) {
				try {
					$retClass = $ret->getName();
					// QBMapper declares insert()/update() as base Entity — the
					// controller needs the concrete Db entity (XMapper -> Db\X).
					if ($retClass === \OCP\AppFramework\Db\Entity::class && str_ends_with($typeName, 'Mapper')) {
						$entityClass = 'OCA\\ArbeitszeitCheck\\Db\\' . substr($typeName, strrpos($typeName, '\\') + 1, -6);
						if (class_exists($entityClass)) {
							$retClass = $entityClass;
						}
					}
					return $this->entityMock($retClass);
				} catch (\Throwable) {
					// Final/readonly value objects cannot be doubled — hand back
					// a real instance so declared return types stay satisfied.
					try {
						$real = $this->instantiateClass($ret->getName(), true, 'happy', '', []);
						if ($real !== null) {
							return $real;
						}
					} catch (\Throwable) {
					}
					return null;
				}
			}
		}
		if ($ret === null || ($ret instanceof ReflectionNamedType && $ret->allowsNull())) {
			return $entity;
		}
		return $entity;
	}

	/**
	 * Mock for a class-typed collaborator return. Db entities resolve getters
	 * through magic __call — stub it so ownership checks (getUserId === 'alice')
	 * and id lookups behave like a real alice-owned row.
	 */
	private function entityMock(string $class): object
	{
		// Internal value types are never doubled — real instances satisfy the
		// declared types AND behave correctly (format(), comparisons).
		if (is_a($class, \DateTimeInterface::class, true)) {
			return new $class('2026-09-10 10:00:00');
		}
		if ($class === \DateTimeZone::class) {
			return new \DateTimeZone('Europe/Berlin');
		}
		// Cached first: stubbing methods recursively pulls entityMock() for
		// return types (A::getB(): B -> B::getA(): A). Callers must get the
		// stubbed instance, never a fresh unstubbed double.
		$mockKey = $class . ($this->foreignOwnedEntities ? ':foreign' : '');
		if (isset($this->entityMocks[$mockKey])) {
			return $this->entityMocks[$mockKey];
		}
		$mock = $this->createMock($class);
		$this->entityMocks[$mockKey] = $mock;
		// Collaborator-produced Response objects get a sane 200 status — the
		// response-builder's own unit tests own its content correctness.
		$overrides = is_a($class, Response::class, true) ? ['getStatus' => 200] : [];
		$overrides['__call'] = function (string $name, array $args = []): mixed {
					$n = strtolower($name);
					if (str_starts_with($n, 'get')) {
						$prop = substr($n, 3);
						$actOv = self::ACTION_ENTITY_OVERRIDES[$this->currentAction] ?? null;
						if ($actOv !== null && array_key_exists($prop, $actOv)) {
							$v = $actOv[$prop];
							return $v instanceof \Closure ? $v($name, $args) : $v;
						}
						$owner = $this->foreignOwnedEntities ? 'mallory' : 'alice';
						return match (true) {
							$prop === 'id' => 1,
							str_contains($prop, 'userid'), str_contains($prop, 'user_id'), $prop === 'uid', $prop === 'owner' => $owner,
							str_contains($prop, 'manager') => $owner,
							str_contains($prop, 'terminalid') => 'term-1',
							str_contains($prop, 'language'), str_contains($prop, 'locale') => 'en',
							str_contains($prop, 'token') => 'tok',
							str_contains($prop, 'validfrom'), str_contains($prop, 'validuntil'), str_contains($prop, 'valid_from'), str_contains($prop, 'valid_until'), str_contains($prop, 'validto') => new \DateTime('2026-09-10 10:00:00'),
							str_contains($prop, 'date'), str_contains($prop, 'time'), str_ends_with($prop, 'at'),
							str_ends_with($prop, 'from'), str_ends_with($prop, 'until'), str_contains($prop, 'effective'),
							str_contains($prop, 'start'), str_ends_with($prop, 'end') => new \DateTime('2026-09-10 10:00:00'),
							str_contains($prop, 'count'), str_contains($prop, 'amount'), str_contains($prop, 'minutes'), str_contains($prop, 'hours'), str_contains($prop, 'days'), str_contains($prop, 'year'), str_contains($prop, 'month') => 1,
							str_contains($prop, 'resolved') => false,
							str_contains($prop, 'active'), str_contains($prop, 'enabled'), str_contains($prop, 'approved'), str_contains($prop, 'valid') => true,
							default => 'x',
						};
					}
					if (str_starts_with($n, 'is')) {
						$prop = substr($n, 2);
						$actOv = self::ACTION_ENTITY_OVERRIDES[$this->currentAction] ?? null;
						if ($actOv !== null && array_key_exists($prop, $actOv)) {
							$v = $actOv[$prop];
							return $v instanceof \Closure ? $v($name, $args) : $v;
						}
						return true;
					}
					return null;
				};
		$this->stubPublicMethodsWithOverrides($mock, $class, $overrides);
		return $mock;
	}

	/** @param class-string $controllerClass */
	private function request(string $mode = 'happy', string $controllerClass = ''): IRequest
	{
		$params = [
			'name' => 'Test',
			'label' => 'Test',
			'title' => 'Test',
			'uid' => 'bob',
			'userId' => 'bob',
			'user_id' => 'bob',
			'employeeId' => 'bob',
			'groupId' => 'group1',
			'id' => 1,
			'entryId' => 1,
			'absenceId' => 1,
			'requestId' => 1,
			'violationId' => 1,
			'payoutId' => 1,
			'substituteId' => 1,
			'substituteUserId' => 'carol',
			'modelId' => 1,
			'holidayId' => 1,
			'allocationId' => 1,
			'ruleSetId' => 1,
			'sessionToken' => 'sess',
			'terminalId' => 'term-1',
			'token' => 'term-token',
			'pairingCode' => 'ABC123',
			'rfidUid' => 'rfid-1',
			'pin' => '1234',
			'method' => 'pin',
			'action' => 'clock_in',
			'type' => 'vacation',
			'status' => 'open',
			'reason' => 'test reason',
			'justification' => 'detailed justification text',
			'comment' => 'test comment',
			'note' => 'test note',
			'description' => 'test description',
			'date' => '2026-09-10',
			'start_date' => '10.09.2026',
			'end_date' => '11.09.2026',
			'newEndDate' => '10.09.2026',
			'new_end_date' => '2026-09-10',
			'week_start' => '2026-09-07',
			'region' => 'NW',
			'state' => 'NW',
			'regionCode' => 'NW',
			'stateCode' => 'NW',
			'language' => 'en',
			'lang' => 'en',
			'languageCode' => 'en',
			'feedLanguage' => 'en',
			'feedLanguageCode' => 'en',
			'unit' => 'days',
			'targetUnit' => 'days',
			'hoursPerDay' => 8,
			'clientConfirmed' => '1',
			'weekStart' => '2026-09-07',
			'start_time' => '2026-09-10T09:00:00Z',
			'end_time' => '2026-09-10T17:00:00Z',
			'teamId' => 1,
			'tariffCode' => 'TC1',
			'version' => 'v1',
			'jurisdiction' => 'DE',
			'activationMode' => 'immediate',
			'validFrom' => '2026-01-01',
			'validTo' => '2026-12-31',
			'modules' => [],
			'reasonCode' => 'custom',
			'reason_code' => 'custom',
			'asOfDate' => '2026-09-10',
			'weeklyHours' => 40,
			'dailyHours' => 8,
			'workDaysPerWeek' => 5,
			'filter' => 'all',
			'hoursDelta' => 2.5,
			'delta' => 1.0,
			'workingTimeModelId' => 1,
			'startDate' => '2026-09-10',
			'endDate' => '2026-09-11',
			'start' => '2026-09-10T10:00:00Z',
			'end' => '2026-09-10T12:00:00Z',
			'startTime' => '10:00',
			'endTime' => '12:00',
			'dateFrom' => '2026-01-01',
			'dateTo' => '2026-12-31',
			'from' => '2026-01-01',
			'to' => '2026-12-31',
			'month' => 9,
			'year' => 2026,
			'yearMonth' => '2026-09',
			'section' => 'breaks',
			'tab' => 'overview',
			'key' => 'feature',
			'value' => '1',
			'enabled' => 'true',
			'notifications_enabled' => '1',
			'hours_display' => 'hours_minutes',
			'hours' => 8,
			'minutes' => 60,
			'days' => 1,
			'amount' => 10,
			'query' => 'a',
			'format' => 'json',
			'locale' => 'en',
			'country' => 'DE',
			'ids' => [1],
			'userIds' => ['bob'],
			'idempotencyKey' => 'atlas-key-1',
			'snapshotAt' => '2026-09-10T10:00:00Z',
			'occurredAt' => '2026-09-10T10:00:00Z',
			'eventUid' => 'evt-1',
			'icsUrl' => 'https://x/feed.ics',
			'feedUrl' => 'https://x/feed.ics',
			'licenseKey' => 'TEST-LICENSE-KEY',
			'confirmation' => 'GDPR_ERASURE_CONFIRMED',
			'limit' => 10,
			'offset' => 0,
			'page' => 1,
		];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnCallback(function () use ($params) {
			$merged = array_merge($params, self::ACTION_PARAMS[$this->currentAction] ?? []);
			foreach (self::ACTION_PARAM_REMOVALS[$this->currentAction] ?? [] as $rk) {
				unset($merged[$rk]);
			}
			return $merged;
		});
		$request->method('getParam')->willReturnCallback(
			function (string $k, $default = null) use ($params) {
				$ov = self::ACTION_PARAMS[$this->currentAction] ?? [];
				if (array_key_exists($k, $ov)) {
					return $ov[$k];
				}
				if (in_array($k, self::ACTION_PARAM_REMOVALS[$this->currentAction] ?? [], true)) {
					return $default;
				}
				return $params[$k] ?? $default;
			}
		);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($mode): string {
				if ($mode === 'authz') {
					return '';
				}
				return match (strtolower($name)) {
					'x-kiosk-terminal-id' => 'term-1',
					'x-kiosk-token' => 'term-token',
					'authorization' => 'Bearer tok',
					default => '',
				};
			}
		);
		$request->method('getMethod')->willReturn('GET');
		$request->method('getPathInfo')->willReturn('/apps/arbeitszeitcheck/api/x');
		$request->method('getRemoteAddress')->willReturn('10.0.0.1');
		$tmp = tempnam(sys_get_temp_dir(), 'azcatlas');
		if ($tmp !== false) {
			file_put_contents($tmp, 'fake-image');
		}
		$request->method('getUploadedFile')->willReturn([
			'tmp_name' => $tmp ?: '/tmp/azcatlas',
			'name' => 'photo.jpg',
			'type' => 'image/jpeg',
			'size' => 10,
			'error' => \UPLOAD_ERR_OK,
		]);
		return $request;
	}

	/** @return list<mixed> */
	private function dummyArgs(ReflectionMethod $method, bool $authz = false): array
	{
		$args = [];
		$actionArgs = self::ACTION_ARGS[$this->currentAction] ?? [];
		foreach ($method->getParameters() as $param) {
			$type = $param->getType();
			$name = $param->getName();
			// 1. Explicit per-action fixture wins.
			if (array_key_exists($name, $actionArgs)) {
				$args[] = $actionArgs[$name];
				continue;
			}
			// 2. A declared non-null default is already a sane happy value.
			if ($param->isDefaultValueAvailable() && $param->getDefaultValue() !== null) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			if ($type instanceof ReflectionNamedType) {
				$tn = $type->getName();
				if ($tn === 'int') {
					$args[] = 1;
					continue;
				}
				if ($tn === 'string') {
					// 3. Name-aware fixture; when nothing matches, prefer null
					//    for optional params ('x' poisons format/enum checks)
					//    and fall back to 'x' only for required strings.
					$fixture = match (true) {
						str_contains(strtolower($name), 'month') => '2026-09',
						str_contains(strtolower($name), 'date') => '2026-09-10',
						str_contains(strtolower($name), 'weekstart') => '2026-09-07',
						in_array($name, ['uid', 'userId', 'employeeId'], true) => 'bob',
						$name === 'section' => 'breaks',
						in_array($name, ['state', 'region', 'regionCode', 'stateCode'], true) => 'NW',
						in_array($name, ['language', 'languageCode', 'locale', 'lang'], true) => 'en',
						in_array(strtolower($name), ['starttime', 'start_time', 'endtime', 'end_time', 'occurredat', 'occurred_at'], true) => '2026-09-10T09:00:00Z',
						in_array($name, ['filter'], true) => 'all',
						str_contains(strtolower($name), 'token') => 'tok',
						str_contains(strtolower($name), 'terminal') => 'term-1',
						str_contains(strtolower($name), 'rfid') => 'rfid-1',
						default => null,
					};
					if ($fixture !== null) {
						$args[] = $fixture;
						continue;
					}
					if ($param->isDefaultValueAvailable() || $type->allowsNull()) {
						$args[] = null;
						continue;
					}
					$args[] = 'x';
					continue;
				}
				if ($tn === 'bool') {
					$args[] = true;
					continue;
				}
				if ($tn === 'array') {
					$args[] = [];
					continue;
				}
				if ($tn === 'float') {
					$args[] = 1.0;
					continue;
				}
			}
			if ($param->isDefaultValueAvailable()) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
				$args[] = null;
				continue;
			}
			$args[] = null;
		}
		return $args;
	}
}
