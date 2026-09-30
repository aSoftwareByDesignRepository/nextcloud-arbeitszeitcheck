<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Dashboard;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\DashboardWidgetDataService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimeDisplayService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\VacationHoursDebitService;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DashboardWidgetDataServiceTest extends TestCase
{
	private TimeTrackingService&MockObject $timeTracking;
	private OvertimeService&MockObject $overtime;
	private OvertimeDisplayService&MockObject $overtimeDisplay;
	private OvertimeBankService&MockObject $overtimeBank;
	private AbsenceService&MockObject $absenceService;
	private AbsenceMapper&MockObject $absenceMapper;
	private TeamResolverService&MockObject $teamResolver;
	private PermissionService&MockObject $permission;
	private IUserManager&MockObject $userManager;
	private TimeCaptureMethodService&MockObject $capture;
	private ProjectCheckIntegrationService&MockObject $projectCheck;
	private VacationHoursDebitService&MockObject $vacationDebit;
	private TimeEntryMapper&MockObject $timeEntryMapper;
	private DashboardWidgetDataService $svc;

	protected function setUp(): void
	{
		parent::setUp();
		$this->timeTracking = $this->createMock(TimeTrackingService::class);
		$this->overtime = $this->createMock(OvertimeService::class);
		$this->overtimeDisplay = $this->createMock(OvertimeDisplayService::class);
		$this->overtimeBank = $this->createMock(OvertimeBankService::class);
		$this->absenceService = $this->createMock(AbsenceService::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->capture = $this->createMock(TimeCaptureMethodService::class);
		$this->projectCheck = $this->createMock(ProjectCheckIntegrationService::class);
		$this->vacationDebit = $this->createMock(VacationHoursDebitService::class);
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($a, $k, $d = '') => $d);
		$dtz = $this->createMock(IDateTimeZone::class);
		$dtz->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$tz = new TimeZoneService($config, $dtz, $session, new NullLogger());

		$this->svc = new DashboardWidgetDataService(
			$this->timeTracking,
			$this->overtime,
			$this->overtimeDisplay,
			$this->overtimeBank,
			$this->absenceService,
			$this->absenceMapper,
			$this->teamResolver,
			$this->permission,
			$this->userManager,
			$tz,
			$this->capture,
			$this->projectCheck,
			$this->vacationDebit,
			$this->timeEntryMapper,
		);
	}

	private function user(string $uid): IUser
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('getDisplayName')->willReturn(ucfirst($uid));
		return $u;
	}

	public function testManagerWidgetUnauthorizedReturnsEmptySummary(): void
	{
		$this->permission->method('canAccessManagerDashboard')->willReturn(false);
		$r = $this->svc->getManagerWidgetData('boss');
		$this->assertFalse($r['authorized']);
		$this->assertSame([], $r['members']);
		$this->assertSame(
			['total' => 0, 'active' => 0, 'break' => 0, 'paused' => 0, 'clocked_out' => 0, 'other' => 0],
			$r['summary']
		);
	}

	public function testManagerWidgetRanksMembersAndSummarisesAbsences(): void
	{
		$this->permission->method('canAccessManagerDashboard')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->willReturn(['emma', 'ben', 'zoey', 'ghost']);
		$this->userManager->method('get')->willReturnCallback(
			fn (string $uid) => $uid === 'ghost' ? null : $this->user($uid)
		);
		// batch live statuses: emma on break, ben active, zoey clocked out
		$this->timeEntryMapper->method('findLiveStatusByUserIds')
			->willReturn(['emma' => 'break', 'ben' => 'active']);

		$this->timeTracking->method('getStatus')->willReturnCallback(static fn (string $uid) => [
			'status' => $uid === 'ben' ? 'active' : 'break',
			'working_today_hours' => $uid === 'ben' ? 3.5 : 1.0,
		]);

		// one approved vacation + one sick for today
		$vac = new Absence();
		$vac->setUserId('zoey');
		$vac->setType(Absence::TYPE_VACATION);
		$sick = new Absence();
		$sick->setUserId('emma');
		$sick->setType(Absence::TYPE_SICK_LEAVE);
		$this->absenceMapper->method('findByUsersAndDateRange')->willReturn([$vac, $sick]);

		$r = $this->svc->getManagerWidgetData('boss', 10);

		$this->assertTrue($r['authorized']);
		$this->assertSame(['total' => 3, 'active' => 1, 'break' => 1, 'paused' => 0, 'clocked_out' => 1, 'other' => 0], $r['summary']);
		// active first, then break, then clocked_out
		$this->assertSame(['ben', 'emma', 'zoey'], array_column($r['members'], 'userId'));
		$this->assertSame(3.5, $r['members'][0]['workingTodayHours']);
		$this->assertSame(0.0, $r['members'][2]['workingTodayHours']); // clocked_out: no live fetch
		$this->assertSame(['vacation' => 1, 'sick' => 1, 'other_absent' => 0, 'total_absent' => 2], $r['absenceSummary']);
	}

	public function testManagerWidgetAbsenceMapperFailureReturnsZeroedSummary(): void
	{
		$this->permission->method('canAccessManagerDashboard')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->willReturn(['emma']);
		$this->userManager->method('get')->willReturnCallback(fn ($uid) => $this->user($uid));
		$this->timeEntryMapper->method('findLiveStatusByUserIds')->willReturn([]);
		$this->absenceMapper->method('findByUsersAndDateRange')->willThrowException(new \RuntimeException('db'));

		$r = $this->svc->getManagerWidgetData('boss');
		$this->assertSame(['vacation' => 0, 'sick' => 0, 'other_absent' => 0, 'total_absent' => 0], $r['absenceSummary']);
	}

	public function testEmployeeWidgetDataAssemblesPayloadFromDeps(): void
	{
		$this->timeTracking->method('getStatus')->willReturn([
			'status' => 'clocked_out',
			'working_today_hours' => 2.5,
			'at_daily_maximum' => false,
			'current_session_duration' => 0,
			'server_now' => '2026-03-05T10:00:00+01:00',
			'server_timezone' => 'Europe/Berlin',
			'current_entry' => null,
		]);
		$this->timeTracking->method('getBreakStatus')->willReturn([
			'break_required' => false,
			'remaining_break_minutes' => 0,
			'warning_level' => 'none',
		]);
		$profile = new \OCA\ArbeitszeitCheck\Support\LaborLawProfile(
			'DE', 8.0, 11.0, [['afterHours' => 6.0, 'breakMinutes' => 30]],
			null, 24, 60.0, null, 23, 6,
			['breaks' => 'ArbZG §4'], 30, 30
		);
		$this->timeTracking->method('lawProfile')->willReturn($profile);
		$this->timeTracking->method('isAutoBreakCalculationEnabled')->willReturn(false);
		$this->overtime->method('getWeeklyOvertime')->willReturn([
			'total_hours_worked' => 20.0,
			'required_hours' => 20.0,
			'weekly_hours' => 40.0,
			'implied_daily_hours' => 8.0,
			'cumulative_balance' => 1.5,
		]);
		$this->absenceService->method('getVacationStats')->willReturn([
			'year' => 2026, 'remaining' => 12.0, 'entitlement' => 30.0, 'used' => 18.0,
			'vacation_unit' => 'days', 'vacation_hours_per_day' => 8.0,
			'carryover_days' => 0.0, 'carryover_usable' => 0.0,
		]);
		$this->overtimeDisplay->method('getYearToDateBalanceForTrafficLight')->willReturn(1.5);
		$this->overtimeDisplay->method('buildTrafficLightViewModel')->willReturn(['state' => 'green']);
		$this->overtimeBank->method('isEnabled')->willReturn(false);
		$this->vacationDebit->method('snapshotForUser')->willReturn([
			'basis' => 'org_hours_per_day', 'weekday_nets' => null, 'one_day_hours' => 8.0, 'average_daily' => 8.0,
		]);
		$this->capture->method('getSettings')->willReturn(['allowed' => true]);
		$this->projectCheck->method('isLinkingEnabledForUser')->willReturn(false);
		$this->projectCheck->method('isProjectCheckAvailable')->willReturn(false);

		$p = $this->svc->getEmployeeWidgetData('alice');
		$this->assertSame('alice', $p['userId']);
		$this->assertSame('clocked_out', $p['status']);
		$this->assertSame(2.5, $p['workingTodayHours']);
		$this->assertSame(1.5, $p['cumulativeBalance']);
		$this->assertSame('green', $p['trafficLightState']);
		$this->assertFalse($p['projectCheck']['available']);
		$this->assertSame([], $p['projectCheck']['projects']);
		$this->assertSame(30.0, $p['vacationEntitlement']);
		$this->assertArrayHasKey('premiumSummary', $p); // null when feature off / unavailable
	}
}
