<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\DashboardWidgetDataService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimeDisplayService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\VacationHoursDebitService;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DashboardWidgetDataServiceTest extends TestCase {
	private function createTimeCaptureMethodService(): TimeCaptureMethodService {
		$service = $this->createMock(TimeCaptureMethodService::class);
		$service->method('getSettings')->willReturn([
			'clockStampingEnabled' => true,
			'manualTimeEntryEnabled' => true,
		]);

		return $service;
	}

	private function createTimeZoneService(): TimeZoneService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'Europe/Berlin',
			default => $default,
		});
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		return new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());
	}

	private function createVacationHoursDebitService(): VacationHoursDebitService {
		$svc = $this->createMock(VacationHoursDebitService::class);
		$svc->method('snapshotForUser')->willReturn([
			'basis' => 'org_hours_per_day',
			'average_daily' => 8.0,
			'weekday_nets' => null,
			'one_day_hours' => 8.0,
		]);
		$svc->method('estimateForUserRange')->willReturn([
			'hours' => 8.0,
			'basis' => 'org_hours_per_day',
			'average_daily' => 8.0,
			'weekday_nets' => null,
			'one_day_hours' => 8.0,
		]);

		return $svc;
	}

	private function createService(
		TimeTrackingService $timeTrackingService,
		PermissionService $permissionService,
		IUserManager $userManager,
		?TeamResolverService $teamResolverService = null,
		?TimeEntryMapper $timeEntryMapper = null
	): DashboardWidgetDataService {
		$display = $this->createMock(OvertimeDisplayService::class);
		$display->method('getYearToDateBalanceForTrafficLight')->willReturn(0.0);
		$display->method('buildTrafficLightViewModel')->willReturn(['state' => 'green']);

		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(false);

		$mapper = $timeEntryMapper ?? $this->createMock(TimeEntryMapper::class);
		if ($timeEntryMapper === null) {
			$mapper->method('findLiveStatusByUserIds')->willReturn([]);
		}

		return new DashboardWidgetDataService(
			$timeTrackingService,
			$this->createMock(OvertimeService::class),
			$display,
			$bank,
			$this->createMock(AbsenceService::class),
			$this->createMock(AbsenceMapper::class),
			$teamResolverService ?? $this->createMock(TeamResolverService::class),
			$permissionService,
			$userManager,
			$this->createTimeZoneService(),
			$this->createTimeCaptureMethodService(),
			$this->createMock(ProjectCheckIntegrationService::class),
			$this->createVacationHoursDebitService(),
			$mapper,
		);
	}

	public function testEmployeeWidgetDataFormatsIsoSessionStartInUserDisplayTz(): void {
		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->method('lawProfile')->willReturn(
			\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::profileForCountry('DE')
		);
		$timeTrackingService->method('getStatus')->with('u1')->willReturn([
			'status' => 'active',
			'working_today_hours' => 1.0,
			'current_session_duration' => 60,
			'current_entry' => [
				'startTime' => '2026-01-15T08:30:00+01:00',
			],
		]);
		$timeTrackingService->method('getBreakStatus')->willReturn([]);
		$timeTrackingService->method('isAutoBreakCalculationEnabled')->willReturn(true);

		$overtime = $this->createMock(OvertimeService::class);
		$overtime->method('getWeeklyOvertime')->willReturn([]);

		$absence = $this->createMock(AbsenceService::class);
		$absence->method('getVacationStats')->willReturn(['year' => 2026]);

		$display = $this->createMock(OvertimeDisplayService::class);
		$display->method('getYearToDateBalanceForTrafficLight')->willReturn(2.5);
		$display->method('buildTrafficLightViewModel')->willReturn(['state' => 'green']);
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(false);

		$service = new DashboardWidgetDataService(
			$timeTrackingService,
			$overtime,
			$display,
			$bank,
			$absence,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(TeamResolverService::class),
			$this->createMock(PermissionService::class),
			$this->createMock(IUserManager::class),
			$this->createTimeZoneService(),
			$this->createTimeCaptureMethodService(),
			$this->createMock(ProjectCheckIntegrationService::class),
			$this->createVacationHoursDebitService(),
			$this->createMock(TimeEntryMapper::class),
		);

		$data = $service->getEmployeeWidgetData('u1', true);
		$this->assertSame('08:30', $data['sessionStartFormatted']);
		$this->assertTrue($data['autoBreakCalculation']);
		$this->assertFalse($data['atDailyMaximum']);
		$this->assertIsArray($data['projectCheck']);
		$this->assertFalse($data['projectCheck']['available']);
		$this->assertFalse($data['projectCheck']['linkingEnabled']);
		$this->assertSame([], $data['projectCheck']['projects']);
		// T-MOB-01 / schedule debit snapshot — always present for companion booking.
		$this->assertArrayHasKey('vacationDebitBasis', $data);
		$this->assertArrayHasKey('vacationWeekdayNets', $data);
		$this->assertArrayHasKey('vacationOneDayHours', $data);
		$this->assertArrayHasKey('vacationAverageDailyHours', $data);
		$this->assertSame('org_hours_per_day', $data['vacationDebitBasis']);
		$this->assertSame(8.0, $data['vacationOneDayHours']);
		$this->assertSame(8.0, $data['vacationAverageDailyHours']);
		$this->assertArrayHasKey('hoursGlance', $data);
		$this->assertIsArray($data['hoursGlance']);
		$this->assertCount(4, $data['hoursGlance']);
		$this->assertSame(['today', 'week', 'month', 'year'], array_column($data['hoursGlance'], 'key'));
		$this->assertSame(2.5, $data['displayBalance']);
		$this->assertArrayHasKey('absenceCreditHoursYtd', $data);
		$this->assertSame(0.0, $data['absenceCreditHoursYtd']);
	}

	public function testEmployeeWidgetDataIncludesAssignableProjectCheckProjects(): void {
		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->method('lawProfile')->willReturn(
			\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::profileForCountry('DE')
		);
		$timeTrackingService->method('getStatus')->with('u1')->willReturn([
			'status' => 'clocked_out',
			'working_today_hours' => 8.1,
			'at_daily_maximum' => false,
			'current_session_duration' => 0,
		]);
		$timeTrackingService->method('getBreakStatus')->willReturn([]);
		$timeTrackingService->method('isAutoBreakCalculationEnabled')->willReturn(true);

		$overtime = $this->createMock(OvertimeService::class);
		$overtime->method('getWeeklyOvertime')->willReturn([]);
		$absence = $this->createMock(AbsenceService::class);
		$absence->method('getVacationStats')->willReturn(['year' => 2026]);
		$display = $this->createMock(OvertimeDisplayService::class);
		$display->method('getYearToDateBalanceForTrafficLight')->willReturn(0.0);
		$display->method('buildTrafficLightViewModel')->willReturn(['state' => 'green']);
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(false);

		$projectCheck = $this->createMock(ProjectCheckIntegrationService::class);
		$projectCheck->method('isProjectCheckAvailable')->willReturn(true);
		$projectCheck->method('isLinkingEnabledForUser')->with('u1')->willReturn(true);
		$projectCheck->method('getAvailableProjects')->with('u1')->willReturn([
			[
				'id' => '42',
				'name' => 'Acme',
				'customerId' => 7,
				'customerName' => 'Acme GmbH',
				'displayName' => 'Acme (Acme GmbH)',
				'costRateMode' => 'project',
			],
		]);

		$service = new DashboardWidgetDataService(
			$timeTrackingService,
			$overtime,
			$display,
			$bank,
			$absence,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(TeamResolverService::class),
			$this->createMock(PermissionService::class),
			$this->createMock(IUserManager::class),
			$this->createTimeZoneService(),
			$this->createTimeCaptureMethodService(),
			$projectCheck,
			$this->createVacationHoursDebitService(),
			$this->createMock(TimeEntryMapper::class),
		);

		$data = $service->getEmployeeWidgetData('u1', true);
		$this->assertTrue($data['projectCheck']['available']);
		$this->assertTrue($data['projectCheck']['linkingEnabled']);
		$this->assertCount(1, $data['projectCheck']['projects']);
		$this->assertSame('42', $data['projectCheck']['projects'][0]['id']);
		$this->assertFalse($data['atDailyMaximum']);
	}

	public function testEmployeeStatusSummaryReturnsLeanPayloadWithoutHeavyQueries(): void {
		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->method('getStatus')->with('u1')->willReturn([
			'status' => 'active',
			'working_today_hours' => 3.25,
			'current_session_duration' => 11700,
			'server_now' => '2026-01-15T12:00:00+01:00',
			'server_timezone' => 'Europe/Berlin',
			'current_entry' => [
				'startTime' => '2026-01-15T08:30:00+01:00',
			],
		]);

		// The desklet summary must NOT trigger the expensive overtime / vacation /
		// traffic-light computations that the full widget payload performs.
		$overtime = $this->createMock(OvertimeService::class);
		$overtime->expects($this->never())->method('getWeeklyOvertime');
		$absence = $this->createMock(AbsenceService::class);
		$absence->expects($this->never())->method('getVacationStats');
		$display = $this->createMock(OvertimeDisplayService::class);
		$display->expects($this->never())->method('getYearToDateBalanceForTrafficLight');
		$display->expects($this->never())->method('buildTrafficLightViewModel');
		$timeTrackingService->expects($this->never())->method('getBreakStatus');

		$service = new DashboardWidgetDataService(
			$timeTrackingService,
			$overtime,
			$display,
			$this->createMock(OvertimeBankService::class),
			$absence,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(TeamResolverService::class),
			$this->createMock(PermissionService::class),
			$this->createMock(IUserManager::class),
			$this->createTimeZoneService(),
			$this->createTimeCaptureMethodService(),
			$this->createMock(ProjectCheckIntegrationService::class),
			$this->createVacationHoursDebitService(),
			$this->createMock(TimeEntryMapper::class),
		);

		$data = $service->getEmployeeStatusSummary('u1');

		$this->assertSame('active', $data['status']);
		$this->assertSame(3.25, $data['workingTodayHours']);
		$this->assertSame(11700, $data['currentSessionDuration']);
		$this->assertSame('08:30', $data['sessionStartFormatted']);
		$this->assertSame('Europe/Berlin', $data['serverTimezone']);
		$this->assertTrue($data['timeCapture']['clockStampingEnabled']);
		// Heavy fields are intentionally absent from the lean payload.
		$this->assertArrayNotHasKey('cumulativeBalance', $data);
		$this->assertArrayNotHasKey('vacationRemaining', $data);
	}

	public function testEmployeeWidgetDataExposesBreakStartTimeIso(): void {
		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->method('lawProfile')->willReturn(
			\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::profileForCountry('DE')
		);
		$timeTrackingService->method('getStatus')->with('u1')->willReturn([
			'status' => 'break',
			'working_today_hours' => 2.0,
			'current_session_duration' => 3600,
			'current_entry' => [
				'breakStartTime' => '2026-01-15T12:00:00+01:00',
			],
		]);
		$timeTrackingService->method('getBreakStatus')->willReturn([]);
		$timeTrackingService->method('isAutoBreakCalculationEnabled')->willReturn(true);

		$overtime = $this->createMock(OvertimeService::class);
		$overtime->method('getWeeklyOvertime')->willReturn([]);
		$absence = $this->createMock(AbsenceService::class);
		$absence->method('getVacationStats')->willReturn(['year' => 2026]);
		$display = $this->createMock(OvertimeDisplayService::class);
		$display->method('getYearToDateBalanceForTrafficLight')->willReturn(0.0);
		$display->method('buildTrafficLightViewModel')->willReturn(['state' => 'green']);
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(false);

		$service = new DashboardWidgetDataService(
			$timeTrackingService,
			$overtime,
			$display,
			$bank,
			$absence,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(TeamResolverService::class),
			$this->createMock(PermissionService::class),
			$this->createMock(IUserManager::class),
			$this->createTimeZoneService(),
			$this->createTimeCaptureMethodService(),
			$this->createMock(ProjectCheckIntegrationService::class),
			$this->createVacationHoursDebitService(),
			$this->createMock(TimeEntryMapper::class),
		);

		$data = $service->getEmployeeWidgetData('u1', true);
		$this->assertSame('2026-01-15T12:00:00+01:00', $data['breakStartTime']);
	}

	public function testEmployeeWidgetDataExposesServerClockAnchor(): void {
		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->method('lawProfile')->willReturn(
			\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::profileForCountry('DE')
		);
		$timeTrackingService->method('getStatus')->with('u1')->willReturn([
			'status' => 'active',
			'server_now' => '2026-01-15T10:00:00+01:00',
			'server_timezone' => 'Europe/Berlin',
		]);
		$timeTrackingService->method('getBreakStatus')->willReturn([]);
		$timeTrackingService->method('isAutoBreakCalculationEnabled')->willReturn(false);

		$overtime = $this->createMock(OvertimeService::class);
		$overtime->method('getWeeklyOvertime')->willReturn([]);
		$absence = $this->createMock(AbsenceService::class);
		$absence->method('getVacationStats')->willReturn(['year' => 2026]);
		$display = $this->createMock(OvertimeDisplayService::class);
		$display->method('getYearToDateBalanceForTrafficLight')->willReturn(0.0);
		$display->method('buildTrafficLightViewModel')->willReturn(['state' => 'green']);
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(false);

		$service = new DashboardWidgetDataService(
			$timeTrackingService,
			$overtime,
			$display,
			$bank,
			$absence,
			$this->createMock(AbsenceMapper::class),
			$this->createMock(TeamResolverService::class),
			$this->createMock(PermissionService::class),
			$this->createMock(IUserManager::class),
			$this->createTimeZoneService(),
			$this->createTimeCaptureMethodService(),
			$this->createMock(ProjectCheckIntegrationService::class),
			$this->createVacationHoursDebitService(),
			$this->createMock(TimeEntryMapper::class),
		);

		$data = $service->getEmployeeWidgetData('u1', true);
		$this->assertSame('2026-01-15T10:00:00+01:00', $data['serverNow']);
		$this->assertSame('Europe/Berlin', $data['serverTimezone']);
		$this->assertFalse($data['autoBreakCalculation']);
	}

	public function testEmployeeWidgetDataUsesTimeTrackingStatus(): void {
		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->method('lawProfile')->willReturn(
			\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::profileForCountry('DE')
		);
		$timeTrackingService->method('getStatus')->with('u1')->willReturn([
			'status' => 'active',
			'working_today_hours' => 4.5,
			'current_session_duration' => 1234,
		]);
		$timeTrackingService->method('getBreakStatus')->willReturn([]);
		$timeTrackingService->method('isAutoBreakCalculationEnabled')->willReturn(true);

		$service = $this->createService(
			$timeTrackingService,
			$this->createMock(PermissionService::class),
			$this->createMock(IUserManager::class)
		);

		$data = $service->getEmployeeWidgetData('u1', true);
		$this->assertSame('active', $data['status']);
		$this->assertSame(4.5, $data['workingTodayHours']);
		$this->assertTrue($data['autoBreakCalculation']);
	}

	public function testManagerWidgetDataDeniesUnauthorizedUsers(): void {
		$permission = $this->createMock(PermissionService::class);
		$permission->method('canAccessManagerDashboard')->with('u1')->willReturn(false);

		$service = $this->createService(
			$this->createMock(TimeTrackingService::class),
			$permission,
			$this->createMock(IUserManager::class)
		);

		$data = $service->getManagerWidgetData('u1');
		$this->assertFalse($data['authorized']);
		$this->assertSame([], $data['members']);
	}

	public function testAdminWidgetDataReturnsSummary(): void {
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isAdmin')->with('admin1')->willReturn(true);

		$team = $this->createMock(TeamResolverService::class);
		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->expects($this->never())->method('getStatus');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('isEnabled')->willReturn(true);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('search')->with('', Constants::MAX_LIST_LIMIT, 0)->willReturn([$user]);
		$userManager->method('countUsersTotal')->with(0, false)->willReturn(1);

		$service = $this->createService($timeTrackingService, $permission, $userManager, $team);
		$data = $service->getAdminWidgetData('admin1', 5);

		$this->assertTrue($data['authorized']);
		$this->assertSame(1, $data['summary']['total']);
		$this->assertSame(1, $data['summary']['clocked_out']);
		$this->assertCount(1, $data['users']);
		$this->assertSame('clocked_out', $data['users'][0]['status']);
		$this->assertFalse($data['summaryTruncated']);
	}

	public function testAdminWidgetDataCapsDisplayListAtMaxAdminWidgetUsers(): void {
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isAdmin')->willReturn(true);

		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->expects($this->exactly(50))->method('getStatus')->willReturn([
			'status' => 'active',
			'working_today_hours' => 1.0,
		]);

		// Build 60 user mocks — more than MAX_ADMIN_WIDGET_USERS (50)
		$users = [];
		$live = [];
		for ($i = 1; $i <= 60; $i++) {
			$u = $this->createMock(IUser::class);
			$u->method('getUID')->willReturn('u' . $i);
			$u->method('getDisplayName')->willReturn('User ' . $i);
			$u->method('isEnabled')->willReturn(true);
			$users[] = $u;
			$live['u' . $i] = 'active';
		}

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('search')->with('', Constants::MAX_LIST_LIMIT, 0)->willReturn($users);
		$userManager->method('countUsersTotal')->with(0, false)->willReturn(60);

		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->expects($this->once())->method('findLiveStatusByUserIds')->willReturn($live);

		$service = $this->createService(
			$timeTrackingService,
			$permission,
			$userManager,
			null,
			$mapper
		);

		// Request more than max; display list must be capped at 50
		$data = $service->getAdminWidgetData('admin1', 100);
		$this->assertCount(50, $data['users']);
		// Summary counts all 60 users from the batch map (not N×getStatus)
		$this->assertSame(60, $data['summary']['total']);
		$this->assertSame(60, $data['summary']['active']);
	}

	public function testAdminWidgetDataPrefersLiveUsersInDisplayList(): void {
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isAdmin')->willReturn(true);

		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->expects($this->once())->method('getStatus')->with('live1')->willReturn([
			'status' => 'break',
			'working_today_hours' => 2.5,
		]);

		$out = $this->createMock(IUser::class);
		$out->method('getUID')->willReturn('out1');
		$out->method('getDisplayName')->willReturn('AAA Out');
		$out->method('isEnabled')->willReturn(true);
		$liveUser = $this->createMock(IUser::class);
		$liveUser->method('getUID')->willReturn('live1');
		$liveUser->method('getDisplayName')->willReturn('ZZZ Live');
		$liveUser->method('isEnabled')->willReturn(true);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('search')->willReturn([$out, $liveUser]);
		$userManager->method('countUsersTotal')->willReturn(2);

		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('findLiveStatusByUserIds')->willReturn(['live1' => 'break']);

		$service = $this->createService($timeTrackingService, $permission, $userManager, null, $mapper);
		$data = $service->getAdminWidgetData('admin1', 1);

		$this->assertCount(1, $data['users']);
		$this->assertSame('live1', $data['users'][0]['userId']);
		$this->assertSame('break', $data['users'][0]['status']);
		$this->assertSame(2.5, $data['users'][0]['workingTodayHours']);
		$this->assertSame(1, $data['summary']['break']);
		$this->assertSame(1, $data['summary']['clocked_out']);
	}

	public function testManagerAbsenceSummaryUsesStorageCalendarToday(): void {
		$permission = $this->createMock(PermissionService::class);
		$permission->method('canAccessManagerDashboard')->willReturn(true);

		$team = $this->createMock(TeamResolverService::class);
		$team->method('getTeamMemberIds')->willReturn(['member1']);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('member1');
		$user->method('getDisplayName')->willReturn('Member');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('member1')->willReturn($user);

		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->method('getStatus')->willReturn([
			'status' => 'clocked_out',
			'working_today_hours' => 0.0,
		]);

		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->expects($this->once())
			->method('findByUsersAndDateRange')
			->with(
				['member1'],
				$this->callback(static function (\DateTimeInterface $start): bool {
					return $start->getTimezone()->getName() === 'Europe/Berlin'
						&& $start->format('H:i:s') === '00:00:00';
				}),
				$this->isInstanceOf(\DateTimeInterface::class),
				Absence::STATUS_APPROVED
			)
			->willReturn([]);

		$display = $this->createMock(OvertimeDisplayService::class);
		$bank = $this->createMock(OvertimeBankService::class);
		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('findLiveStatusByUserIds')->willReturn([]);
		$service = new DashboardWidgetDataService(
			$timeTrackingService,
			$this->createMock(OvertimeService::class),
			$display,
			$bank,
			$this->createMock(AbsenceService::class),
			$absenceMapper,
			$team,
			$permission,
			$userManager,
			$this->createTimeZoneService(),
			$this->createTimeCaptureMethodService(),
			$this->createMock(ProjectCheckIntegrationService::class),
			$this->createVacationHoursDebitService(),
			$timeEntryMapper,
		);
		$service->getManagerWidgetData('mgr1');
	}

	public function testAdminWidgetDataLimitsSearchWindow(): void {
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isAdmin')->willReturn(true);

		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->method('getStatus')->willReturn([
			'status' => 'active',
			'working_today_hours' => 1.0,
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('isEnabled')->willReturn(true);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects($this->once())->method('search')->with('', Constants::MAX_LIST_LIMIT, 0)->willReturn([$user]);
		$userManager->method('countUsersTotal')->with(0, false)->willReturn(600);

		$service = $this->createService(
			$timeTrackingService,
			$permission,
			$userManager
		);

		$data = $service->getAdminWidgetData('admin1', 999);
		$this->assertCount(1, $data['users']);
		$this->assertFalse($data['summaryTruncated']);
		$this->assertSame(600, $data['directoryTotal']);
	}

	public function testAdminWidgetDataTruncatedOnlyWhenScanCapHit(): void {
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isAdmin')->willReturn(true);

		$timeTrackingService = $this->createMock(TimeTrackingService::class);
		$timeTrackingService->method('getStatus')->willReturn([
			'status' => 'clocked_out',
			'working_today_hours' => 0.0,
		]);

		$users = [];
		for ($i = 1; $i <= Constants::MAX_LIST_LIMIT; $i++) {
			$u = $this->createMock(IUser::class);
			$u->method('getUID')->willReturn('u' . $i);
			$u->method('getDisplayName')->willReturn('User ' . $i);
			$u->method('isEnabled')->willReturn(true);
			$users[] = $u;
		}

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('search')->with('', Constants::MAX_LIST_LIMIT, 0)->willReturn($users);
		$userManager->method('countUsersTotal')->with(0, false)->willReturn(600);

		$service = $this->createService($timeTrackingService, $permission, $userManager);
		$data = $service->getAdminWidgetData('admin1', 10);

		$this->assertTrue($data['summaryTruncated']);
		$this->assertSame(Constants::MAX_LIST_LIMIT, $data['summary']['total']);
	}
}
