<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException;
use OCA\ArbeitszeitCheck\Service\AdminUserProfileUpdateService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IL10N;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class AdminUserProfileUpdateServiceTest extends TestCase
{
	private AdminUserProfileUpdateService $service;
	private IUserManager $userManager;
	private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
	private WorkingTimeModelMapper $workingTimeModelMapper;
	private UserVacationPolicyAssignmentMapper $vacationPolicyMapper;

	protected function setUp(): void
	{
		parent::setUp();

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$auditLogMapper = $this->createMock(AuditLogMapper::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$vacationYearBalanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$vacationAllocationService = $this->createMock(VacationAllocationService::class);
		$tariffRuleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$this->vacationPolicyMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$userOvertimeSettingsService = $this->createMock(UserOvertimeSettingsService::class);
		$userEmploymentSettingsService = $this->createMock(UserEmploymentSettingsService::class);
		$timeCaptureMethodService = $this->createMock(TimeCaptureMethodService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);
		$db = $this->createMock(IDBConnection::class);

		$this->service = new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$auditLogMapper,
			$userSettingsMapper,
			$vacationYearBalanceMapper,
			$vacationAllocationService,
			$tariffRuleSetMapper,
			$this->vacationPolicyMapper,
			$userOvertimeSettingsService,
			$userEmploymentSettingsService,
			$timeCaptureMethodService,
			$l10n,
			$db,
		);
	}

	private function serviceWithUserSettingsMapper(
		UserSettingsMapper $userSettingsMapper,
		?LaborLawProfileFactory $laborLawProfileFactory = null,
	): AdminUserProfileUpdateService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);

		return new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->createMock(AuditLogMapper::class),
			$userSettingsMapper,
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(VacationAllocationService::class),
			$this->createMock(TariffRuleSetMapper::class),
			$this->vacationPolicyMapper,
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(UserEmploymentSettingsService::class),
			$this->createMock(TimeCaptureMethodService::class),
			$l10n,
			$this->createMock(IDBConnection::class),
			$laborLawProfileFactory,
		);
	}

	/**
	 * DACH: per-user regions may cross the instance border (commuters), so an
	 * Austrian region must be accepted even on a German instance — normalised
	 * to canonical uppercase form.
	 */
	/**
	 * Existing assignment + only carryover in the payload must still upsert.
	 * Regression: early-return on assignmentMatches skipped carryover/region/country.
	 */
	public function testApplyWorkingTimeModelPersistsCarryoverWhenAssignmentUnchanged(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));

		$wtModel = new WorkingTimeModel();
		$wtModel->setId(1);
		$this->workingTimeModelMapper->method('find')->with(1)->willReturn($wtModel);

		$assignment = new UserWorkingTimeModel();
		$assignment->setId(10);
		$assignment->setUserId('alice');
		$assignment->setWorkingTimeModelId(1);
		$assignment->setVacationDaysPerYear(28);
		$assignment->setStartDate(new \DateTime('2026-01-01'));
		$assignment->setCreatedAt(new \DateTime());
		$assignment->setUpdatedAt(new \DateTime());

		$this->userWorkingTimeModelMapper->method('findEditableByUser')->willReturn($assignment);
		$this->userWorkingTimeModelMapper->expects($this->never())->method('update');
		$this->userWorkingTimeModelMapper->expects($this->never())->method('insert');

		$vacationYearBalanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$vacationYearBalanceMapper->expects($this->once())
			->method('upsert')
			->with('alice', 2026, 5.0, null, true);
		$vacationYearBalanceMapper->method('getCarryoverDays')->with('alice', 2026)->willReturn(5.0);

		$vacationAllocation = $this->createMock(VacationAllocationService::class);
		$vacationAllocation->method('applyCapToOpeningBalance')->willReturnCallback(fn (float $v) => $v);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);

		$service = new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->createMock(AuditLogMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$vacationYearBalanceMapper,
			$vacationAllocation,
			$this->createMock(TariffRuleSetMapper::class),
			$this->vacationPolicyMapper,
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(UserEmploymentSettingsService::class),
			$this->createMock(TimeCaptureMethodService::class),
			$l10n,
			$this->createMock(IDBConnection::class),
		);

		$result = $service->applyWorkingTimeModel('alice', [
			'workingTimeModelId' => 1,
			'vacationDaysPerYear' => 28,
			'startDate' => '2026-01-01',
			'vacationCarryoverDays' => '5',
			'vacationCarryoverYear' => 2026,
		], 'admin');

		$this->assertArrayHasKey('userWorkingTimeModel', $result);
		$this->assertArrayNotHasKey('unchanged', $result);
		$this->assertSame(5.0, $result['vacationCarryoverDays']);
		$this->assertSame(2026, $result['vacationCarryoverYear']);
	}

	public function testApplyWorkingTimeModelPersistsRegionWhenAssignmentUnchanged(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));

		$wtModel = new WorkingTimeModel();
		$wtModel->setId(1);
		$this->workingTimeModelMapper->method('find')->with(1)->willReturn($wtModel);

		$assignment = new UserWorkingTimeModel();
		$assignment->setId(10);
		$assignment->setUserId('alice');
		$assignment->setWorkingTimeModelId(1);
		$assignment->setVacationDaysPerYear(28);
		$assignment->setStartDate(new \DateTime('2026-01-01'));
		$assignment->setCreatedAt(new \DateTime());
		$assignment->setUpdatedAt(new \DateTime());

		$this->userWorkingTimeModelMapper->method('findEditableByUser')->willReturn($assignment);
		$this->userWorkingTimeModelMapper->expects($this->never())->method('update');

		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->expects($this->once())
			->method('setSetting')
			->with('alice', 'german_state', 'BY');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);

		$service = new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->createMock(AuditLogMapper::class),
			$userSettingsMapper,
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(VacationAllocationService::class),
			$this->createMock(TariffRuleSetMapper::class),
			$this->vacationPolicyMapper,
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(UserEmploymentSettingsService::class),
			$this->createMock(TimeCaptureMethodService::class),
			$l10n,
			$this->createMock(IDBConnection::class),
		);

		$service->applyWorkingTimeModel('alice', [
			'workingTimeModelId' => 1,
			'vacationDaysPerYear' => 28,
			'startDate' => '2026-01-01',
			'germanState' => 'BY',
		], 'admin');
	}

	public function testApplyWorkingTimeModelPersistsLaborLawWhenAssignmentUnchanged(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));

		$wtModel = new WorkingTimeModel();
		$wtModel->setId(1);
		$this->workingTimeModelMapper->method('find')->with(1)->willReturn($wtModel);

		$assignment = new UserWorkingTimeModel();
		$assignment->setId(10);
		$assignment->setUserId('alice');
		$assignment->setWorkingTimeModelId(1);
		$assignment->setVacationDaysPerYear(28);
		$assignment->setStartDate(new \DateTime('2026-01-01'));
		$assignment->setCreatedAt(new \DateTime());
		$assignment->setUpdatedAt(new \DateTime());

		$this->userWorkingTimeModelMapper->method('findEditableByUser')->willReturn($assignment);
		$this->userWorkingTimeModelMapper->expects($this->never())->method('update');

		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->expects($this->once())
			->method('setSetting')
			->with('alice', LaborLawProfileFactory::USER_SETTING_LABOR_LAW_COUNTRY, 'AT');

		$factory = $this->createMock(LaborLawProfileFactory::class);
		$factory->expects($this->once())->method('clearCache');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);

		$service = new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->createMock(AuditLogMapper::class),
			$userSettingsMapper,
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(VacationAllocationService::class),
			$this->createMock(TariffRuleSetMapper::class),
			$this->vacationPolicyMapper,
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(UserEmploymentSettingsService::class),
			$this->createMock(TimeCaptureMethodService::class),
			$l10n,
			$this->createMock(IDBConnection::class),
			$factory,
		);

		$service->applyWorkingTimeModel('alice', [
			'workingTimeModelId' => 1,
			'vacationDaysPerYear' => 28,
			'startDate' => '2026-01-01',
			'laborLawCountry' => 'at',
		], 'admin');
	}

	public function testApplyWorkingTimeModelPersistsZeroCarryoverWhenAssignmentUnchanged(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));

		$wtModel = new WorkingTimeModel();
		$wtModel->setId(1);
		$this->workingTimeModelMapper->method('find')->with(1)->willReturn($wtModel);

		$assignment = new UserWorkingTimeModel();
		$assignment->setId(10);
		$assignment->setUserId('alice');
		$assignment->setWorkingTimeModelId(1);
		$assignment->setVacationDaysPerYear(28);
		$assignment->setStartDate(new \DateTime('2026-01-01'));
		$assignment->setCreatedAt(new \DateTime());
		$assignment->setUpdatedAt(new \DateTime());

		$this->userWorkingTimeModelMapper->method('findEditableByUser')->willReturn($assignment);

		$vacationYearBalanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$vacationYearBalanceMapper->expects($this->once())
			->method('upsert')
			->with('alice', 2026, 0.0, null, true);
		$vacationYearBalanceMapper->method('getCarryoverDays')->willReturn(0.0);

		$vacationAllocation = $this->createMock(VacationAllocationService::class);
		$vacationAllocation->method('applyCapToOpeningBalance')->willReturnCallback(fn (float $v) => $v);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);

		$service = new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->createMock(AuditLogMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$vacationYearBalanceMapper,
			$vacationAllocation,
			$this->createMock(TariffRuleSetMapper::class),
			$this->vacationPolicyMapper,
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(UserEmploymentSettingsService::class),
			$this->createMock(TimeCaptureMethodService::class),
			$l10n,
			$this->createMock(IDBConnection::class),
		);

		$service->applyWorkingTimeModel('alice', [
			'workingTimeModelId' => 1,
			'vacationDaysPerYear' => 28,
			'startDate' => '2026-01-01',
			'vacationCarryoverDays' => 0,
			'vacationCarryoverYear' => 2026,
		], 'admin');
	}

	public function testApplyWorkingTimeModelAppliesCarryoverCapWhenAssignmentUnchanged(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));

		$wtModel = new WorkingTimeModel();
		$wtModel->setId(1);
		$this->workingTimeModelMapper->method('find')->with(1)->willReturn($wtModel);

		$assignment = new UserWorkingTimeModel();
		$assignment->setId(10);
		$assignment->setUserId('alice');
		$assignment->setWorkingTimeModelId(1);
		$assignment->setVacationDaysPerYear(28);
		$assignment->setStartDate(new \DateTime('2026-01-01'));
		$assignment->setCreatedAt(new \DateTime());
		$assignment->setUpdatedAt(new \DateTime());

		$this->userWorkingTimeModelMapper->method('findEditableByUser')->willReturn($assignment);

		$vacationYearBalanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$vacationYearBalanceMapper->expects($this->once())
			->method('upsert')
			->with('alice', 2026, 3.0, null, true);
		$vacationYearBalanceMapper->method('getCarryoverDays')->willReturn(3.0);

		$vacationAllocation = $this->createMock(VacationAllocationService::class);
		$vacationAllocation->expects($this->once())
			->method('applyCapToOpeningBalance')
			->with(10.0)
			->willReturn(3.0);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);

		$service = new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->createMock(AuditLogMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$vacationYearBalanceMapper,
			$vacationAllocation,
			$this->createMock(TariffRuleSetMapper::class),
			$this->vacationPolicyMapper,
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(UserEmploymentSettingsService::class),
			$this->createMock(TimeCaptureMethodService::class),
			$l10n,
			$this->createMock(IDBConnection::class),
		);

		$service->applyWorkingTimeModel('alice', [
			'workingTimeModelId' => 1,
			'vacationDaysPerYear' => 28,
			'startDate' => '2026-01-01',
			'vacationCarryoverDays' => '10',
			'vacationCarryoverYear' => 2026,
		], 'admin');
	}

	/**
	 * Source contract: assignmentMatches must never early-return before carryover.
	 */
	public function testApplyWorkingTimeModelSourceDoesNotEarlyReturnBeforeSideEffects(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/AdminUserProfileUpdateService.php'
		);
		$needle = 'workingTimeModelAssignmentMatches($currentModel, $workingTimeModelId, $vacationDaysPerYear, $startDate, $endDate))';
		$pos = strpos($src, $needle);
		$this->assertNotFalse($pos, 'assignmentMatches call site missing');
		$window = substr($src, $pos, 280);
		$this->assertStringContainsString('$updated = $currentModel;', $window);
		$this->assertStringNotContainsString("'unchanged' => true", $window);
		$this->assertDoesNotMatchRegularExpression('/\)\s*\{\s*return\s*\[/', $window);
	}

	public function testApplyOvertimeSettingsReturnsWrittenOpeningBalanceYear(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));

		$overtime = $this->createMock(UserOvertimeSettingsService::class);
		$overtime->expects($this->once())->method('setOpeningBalance')->with('alice', 2024, 12.5, 'admin');
		$overtime->method('getTrackingFrom')->willReturn(null);
		$overtime->expects($this->once())
			->method('getOpeningBalanceHours')
			->with('alice', 2024)
			->willReturn(12.5);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);

		$service = new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->createMock(AuditLogMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(VacationAllocationService::class),
			$this->createMock(TariffRuleSetMapper::class),
			$this->vacationPolicyMapper,
			$overtime,
			$this->createMock(UserEmploymentSettingsService::class),
			$this->createMock(TimeCaptureMethodService::class),
			$l10n,
			$this->createMock(IDBConnection::class),
		);

		$result = $service->applyOvertimeSettings('alice', [
			'openingBalance' => ['year' => 2024, 'hours' => '12.5'],
		], 'admin');

		$this->assertSame(2024, $result['overtimeOpeningBalanceYear']);
		$this->assertSame(12.5, $result['overtimeOpeningBalanceHours']);
	}

	public function testApplyWorkingTimeModelStoresNormalisedCrossBorderRegion(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->expects($this->once())
			->method('setSetting')
			->with('alice', 'german_state', 'AT-W');
		$userSettingsMapper->expects($this->never())->method('deleteSetting');

		$service = $this->serviceWithUserSettingsMapper($userSettingsMapper);
		$result = $service->applyWorkingTimeModel('alice', ['germanState' => ' at-w '], 'admin');
		$this->assertIsArray($result);
	}

	public function testApplyWorkingTimeModelClearsRegionOnEmptyString(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->expects($this->once())
			->method('deleteSetting')
			->with('alice', 'german_state');
		$userSettingsMapper->expects($this->never())->method('setSetting');

		$service = $this->serviceWithUserSettingsMapper($userSettingsMapper);
		$service->applyWorkingTimeModel('alice', ['germanState' => ''], 'admin');
	}

	public function testApplyWorkingTimeModelRejectsInvalidRegionBeforeAnyWrite(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->expects($this->never())->method('setSetting');
		$userSettingsMapper->expects($this->never())->method('deleteSetting');

		$service = $this->serviceWithUserSettingsMapper($userSettingsMapper);

		$this->expectException(AdminUserProfileUpdateException::class);
		$this->expectExceptionMessage('Invalid region code');
		$service->applyWorkingTimeModel('alice', ['germanState' => 'ZZ'], 'admin');
	}

	public function testApplyWorkingTimeModelStoresLaborLawCountryOverride(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->expects($this->once())
			->method('setSetting')
			->with('alice', LaborLawProfileFactory::USER_SETTING_LABOR_LAW_COUNTRY, 'AT');
		$userSettingsMapper->expects($this->never())->method('deleteSetting');

		$factory = $this->createMock(LaborLawProfileFactory::class);
		$factory->expects($this->once())->method('clearCache');

		$service = $this->serviceWithUserSettingsMapper($userSettingsMapper, $factory);
		$service->applyWorkingTimeModel('alice', ['laborLawCountry' => ' at '], 'admin');
	}

	public function testApplyWorkingTimeModelClearsLaborLawCountryOnEmptyString(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->expects($this->once())
			->method('deleteSetting')
			->with('alice', LaborLawProfileFactory::USER_SETTING_LABOR_LAW_COUNTRY);
		$userSettingsMapper->expects($this->never())->method('setSetting');

		$factory = $this->createMock(LaborLawProfileFactory::class);
		$factory->expects($this->once())->method('clearCache');

		$service = $this->serviceWithUserSettingsMapper($userSettingsMapper, $factory);
		$service->applyWorkingTimeModel('alice', ['laborLawCountry' => ''], 'admin');
	}

	public function testApplyWorkingTimeModelDoesNotClearLaborLawCacheWhenKeyAbsent(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->expects($this->once())
			->method('setSetting')
			->with('alice', 'german_state', 'NW');

		$factory = $this->createMock(LaborLawProfileFactory::class);
		$factory->expects($this->never())->method('clearCache');

		$service = $this->serviceWithUserSettingsMapper($userSettingsMapper, $factory);
		$service->applyWorkingTimeModel('alice', ['germanState' => 'NW'], 'admin');
	}

	public function testApplyWorkingTimeModelRejectsInvalidLaborLawCountry(): void
	{
		$this->userManager->method('get')->with('alice')->willReturn($this->createMock(IUser::class));
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->expects($this->never())->method('setSetting');
		$userSettingsMapper->expects($this->never())->method('deleteSetting');

		$service = $this->serviceWithUserSettingsMapper($userSettingsMapper);

		$this->expectException(AdminUserProfileUpdateException::class);
		$this->expectExceptionMessage('Invalid labour-law country');
		$service->applyWorkingTimeModel('alice', ['laborLawCountry' => 'FR'], 'admin');
	}

	public function testUpdateProfileRejectsInvalidVacationPolicyBeforeAnyWrite(): void
	{
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$model = new WorkingTimeModel();
		$model->setId(1);
		$this->workingTimeModelMapper->method('find')->with(1)->willReturn($model);

		$this->expectException(AdminUserProfileUpdateException::class);

		$this->service->updateProfile('alice', [
			'workingTimeModel' => [
				'workingTimeModelId' => 1,
				'startDate' => '2026-01-01',
			],
			'vacationPolicy' => [
				'vacationMode' => Constants::VACATION_MODE_MANUAL_FIXED,
				'manualDays' => null,
				'effectiveFrom' => '2026-01-01',
			],
			'timeCapture' => ['clockStampingEnabled' => true, 'manualTimeEntryEnabled' => true],
			'overtime' => ['openingBalance' => ['year' => 2026, 'hours' => '0']],
		], 'admin');
	}

	public function testUpdateProfileAppliesAllSections(): void
	{
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$wtModel = new WorkingTimeModel();
		$wtModel->setId(1);
		$this->workingTimeModelMapper->method('find')->willReturn($wtModel);

		$assignment = new UserWorkingTimeModel();
		$assignment->setId(10);
		$assignment->setUserId('alice');
		$assignment->setWorkingTimeModelId(1);
		$assignment->setVacationDaysPerYear(28);
		$assignment->setStartDate(new \DateTime('2026-01-01'));
		$assignment->setCreatedAt(new \DateTime());
		$assignment->setUpdatedAt(new \DateTime());

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn($assignment);
		$this->userWorkingTimeModelMapper->method('findEditableByUser')->willReturn($assignment);
		$this->userWorkingTimeModelMapper->expects($this->never())->method('update');
		$this->userWorkingTimeModelMapper->expects($this->never())->method('insert');

		$this->vacationPolicyMapper->method('findCurrentByUser')->willReturn(null);
		$this->vacationPolicyMapper->expects($this->once())->method('insert')->willReturnCallback(function ($entity) {
			$entity->setId(99);

			return $entity;
		});

		$timeCapture = $this->createMock(TimeCaptureMethodService::class);
		$timeCapture->method('setSettings')->willReturn([
			'clockStampingEnabled' => true,
			'manualTimeEntryEnabled' => true,
		]);
		$overtime = $this->createMock(UserOvertimeSettingsService::class);
		$overtime->method('getTrackingFrom')->willReturn(null);
		$overtime->method('getOpeningBalanceHours')->willReturn(0.0);

		$db = $this->createMock(IDBConnection::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);

		$service = new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->createMock(AuditLogMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(VacationAllocationService::class),
			$this->createMock(TariffRuleSetMapper::class),
			$this->vacationPolicyMapper,
			$overtime,
			$this->createMock(UserEmploymentSettingsService::class),
			$timeCapture,
			$l10n,
			$db,
		);

		$result = $service->updateProfile('alice', [
			'workingTimeModel' => [
				'workingTimeModelId' => 1,
				'vacationDaysPerYear' => 28,
				'startDate' => '2026-01-01',
				'germanState' => 'NW',
			],
			'vacationPolicy' => [
				'vacationMode' => Constants::VACATION_MODE_INHERIT,
				'inheritLowerLayers' => true,
				'effectiveFrom' => '2026-01-01',
			],
			'timeCapture' => [
				'clockStampingEnabled' => true,
				'manualTimeEntryEnabled' => true,
			],
			'overtime' => [
				'openingBalance' => ['year' => 2026, 'hours' => '0'],
			],
		], 'admin');

		$this->assertTrue($result['success']);
		$this->assertSame(99, $result['policyId']);
	}

	public function testApplyVacationPolicySkipsWriteWhenUnchanged(): void
	{
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$row = new UserVacationPolicyAssignment();
		$row->setId(5);
		$row->setUserId('alice');
		$row->setVacationMode(Constants::VACATION_MODE_MANUAL_FIXED);
		$row->setManualDays(30.0);
		$row->setEffectiveFrom(new \DateTime('2025-03-27'));
		$row->setInheritLowerLayers(false);
		$row->setCreatedBy('admin');
		$row->setCreatedAt(new \DateTime());
		$row->setUpdatedAt(new \DateTime());

		$this->vacationPolicyMapper->method('find')->with(5)->willReturn($row);
		$this->vacationPolicyMapper->expects($this->never())->method('update');
		$this->vacationPolicyMapper->expects($this->never())->method('insert');

		$result = $this->service->applyVacationPolicy('alice', [
			'policyId' => 5,
			'vacationMode' => Constants::VACATION_MODE_MANUAL_FIXED,
			'manualDays' => 30,
			'effectiveFrom' => '2025-03-27',
		], 'admin');

		$this->assertSame(5, $result['policyId']);
		$this->assertTrue($result['unchanged']);
	}

	public function testApplyDatevSettingsPersistsPersonalnummer(): void
	{
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$config = $this->createMock(\OCP\IConfig::class);
		$config->expects($this->once())
			->method('getUserValue')
			->with('alice', 'arbeitszeitcheck', Constants::USER_DATEV_PERSONALNUMMER, '')
			->willReturn('');
		$config->expects($this->once())
			->method('setUserValue')
			->with('alice', 'arbeitszeitcheck', Constants::USER_DATEV_PERSONALNUMMER, '12345678');

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);

		$service = new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$audit,
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(VacationAllocationService::class),
			$this->createMock(TariffRuleSetMapper::class),
			$this->vacationPolicyMapper,
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(UserEmploymentSettingsService::class),
			$this->createMock(TimeCaptureMethodService::class),
			$l10n,
			$this->createMock(IDBConnection::class),
			null,
			$config,
		);

		$result = $service->applyDatevSettings('alice', ['personalnummer' => '1234 5678'], 'admin');
		$this->assertSame('12345678', $result['datevPersonalnummer']);
	}

	public function testApplyDatevSettingsRejectsInvalidPersonalnummer(): void
	{
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$this->expectException(AdminUserProfileUpdateException::class);
		$this->service->applyDatevSettings('alice', ['personalnummer' => 'abc'], 'admin');
	}
}
