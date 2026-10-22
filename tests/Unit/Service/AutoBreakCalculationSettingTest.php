<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator;
use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Personal setting dependency: auto_break_calculation on/off changes TimeTrackingService behaviour.
 */
class AutoBreakCalculationSettingTest extends TestCase
{
	/** @var UserSettingsMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $userSettingsMapper;

	private TimeTrackingService $service;

	protected function setUp(): void
	{
		parent::setUp();

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'max_daily_hours' => '10',
			'min_rest_period' => '11',
			'app_timezone' => 'Europe/Berlin',
			'country' => 'DE',
			default => $default,
		});
		$config->method('getUserValue')->willReturn('');

		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());
		$dailyHoursCalculator = new DailyWorkingHoursCalculator($timeEntryMapper, $timeZoneService);

		$timeCaptureMethodService = $this->createMock(TimeCaptureMethodService::class);
		$timeCaptureMethodService->method('assertClockStampingAllowed')->willReturnCallback(static function (): void {
		});

		$complianceService = $this->createMock(ComplianceService::class);
		$complianceService->method('checkComplianceBeforeClockIn')->willReturn([]);

		// Inert resolver bound to this test's config — the service's lazy
		// Server::get fallback would otherwise bind the live dev database
		// and let a saved org policy force auto-break on in these tests.
		$inertResolver = new \OCA\ArbeitszeitCheck\Support\BreakPolicyResolver(
			$config,
			new \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory($config, $this->userSettingsMapper),
			$this->userSettingsMapper,
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			$timeEntryMapper,
			$timeZoneService,
			$this->createMock(IL10N::class),
		);

		$this->service = new TimeTrackingService(
			$timeEntryMapper,
			$this->createMock(ComplianceViolationMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(ProjectCheckIntegrationService::class),
			$complianceService,
			$this->createMock(IL10N::class),
			$config,
			$this->userSettingsMapper,
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			$this->createMock(MonthClosureGuard::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ILockingProvider::class),
			$timeZoneService,
			$dailyHoursCalculator,
			null,
			$timeCaptureMethodService,
			null,
			$inertResolver,
		);
	}

	public function testIsAutoBreakCalculationEnabledRespectsUserSettingOn(): void
	{
		$this->userSettingsMapper->expects($this->once())
			->method('getStringSetting')
			->with('u1', 'auto_break_calculation', '1')
			->willReturn('1');

		$this->assertTrue($this->service->isAutoBreakCalculationEnabled('u1'));
	}

	public function testIsAutoBreakCalculationEnabledRespectsUserSettingOff(): void
	{
		$this->userSettingsMapper->expects($this->once())
			->method('getStringSetting')
			->with('u1', 'auto_break_calculation', '1')
			->willReturn('0');

		$this->assertFalse($this->service->isAutoBreakCalculationEnabled('u1'));
	}

	public function testCalculateAndSetAutomaticBreakReturnsFalseWhenSettingOff(): void
	{
		$this->userSettingsMapper->method('getStringSetting')
			->with('u1', 'auto_break_calculation', '1')
			->willReturn('0');

		$entry = new TimeEntry();
		$entry->setUserId('u1');
		$entry->setStartTime(new \DateTime('2024-01-15T08:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15T16:00:00'));

		$this->assertFalse($this->service->calculateAndSetAutomaticBreak($entry));
		$this->assertNull($entry->getBreakStartTime());
		$this->assertNull($entry->getBreakEndTime());
	}

	// ------------------------------------------------------------------
	// Enforced organisational break policy (DISUS)
	// ------------------------------------------------------------------

	/**
	 * Service whose resolver sees the given org break_policy_json and model
	 * break_rules — used to prove the employee-side opt-out is overridden.
	 */
	private function serviceWithPolicy(array $policy, ?array $modelBreakRules, $timeEntryMapper): TimeTrackingService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'max_daily_hours' => '10',
			'min_rest_period' => '11',
			'app_timezone' => 'Europe/Berlin',
			'country' => 'DE',
			\OCA\ArbeitszeitCheck\Constants::CONFIG_BREAK_POLICY_JSON => json_encode($policy),
			default => $default,
		});
		$config->method('getUserValue')->willReturn('');

		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());

		$userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		if ($modelBreakRules !== null) {
			$model = new \OCA\ArbeitszeitCheck\Db\WorkingTimeModel();
			$model->setId(7);
			$model->setBreakRules(json_encode($modelBreakRules));
			$assignment = new \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel();
			$assignment->setWorkingTimeModelId(7);
			$userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn($assignment);
			$workingTimeModelMapper->method('find')->willReturn($model);
		} else {
			$userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		}

		$resolver = new \OCA\ArbeitszeitCheck\Support\BreakPolicyResolver(
			$config,
			new \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory($config, $this->userSettingsMapper),
			$this->userSettingsMapper,
			$userWorkingTimeModelMapper,
			$workingTimeModelMapper,
			$timeEntryMapper,
			$timeZoneService,
			$this->createMock(IL10N::class),
		);

		return new TimeTrackingService(
			$timeEntryMapper,
			$this->createMock(ComplianceViolationMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(ProjectCheckIntegrationService::class),
			$this->createMock(ComplianceService::class),
			$this->createMock(IL10N::class),
			$config,
			$this->userSettingsMapper,
			$userWorkingTimeModelMapper,
			$workingTimeModelMapper,
			$this->createMock(MonthClosureGuard::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(ILockingProvider::class),
			$timeZoneService,
			new DailyWorkingHoursCalculator($timeEntryMapper, $timeZoneService),
			null,
			null,
			null,
			$resolver,
		);
	}

	public function testEnforcedPolicyOverridesEmployeeOptOut(): void
	{
		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$service = $this->serviceWithPolicy(
			['enforced' => true, 'tiers' => [['afterHours' => 6, 'breakMinutes' => 45]]],
			null,
			$timeEntryMapper,
		);

		// Employee preference stays '0' — enforcement must ignore it entirely.
		$this->userSettingsMapper->expects($this->never())->method('getStringSetting');

		$this->assertTrue($service->isAutoBreakCalculationEnabled('u1'));
	}

	public function testNonEnforcedPolicyStillRespectsEmployeeOptOut(): void
	{
		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$service = $this->serviceWithPolicy(
			['enforced' => false, 'tiers' => [['afterHours' => 6, 'breakMinutes' => 45]]],
			null,
			$timeEntryMapper,
		);

		$this->userSettingsMapper->expects($this->once())
			->method('getStringSetting')
			->with('u1', 'auto_break_calculation', '1')
			->willReturn('0');

		$this->assertFalse($service->isAutoBreakCalculationEnabled('u1'));
	}

	public function testAutoBreakUsesTightenedPolicyTierAndTagsSource(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$entry = new TimeEntry();
		$entry->setId(10);
		$entry->setUserId('u1');
		// 8h > law's 6h→30min tier; org tier tightens to 45min.
		$entry->setStartTime(new \DateTime('2024-01-15T08:00:00', $tz));
		$entry->setEndTime(new \DateTime('2024-01-15T16:00:00', $tz));

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$service = $this->serviceWithPolicy(
			['enforced' => true, 'tiers' => [['afterHours' => 6, 'breakMinutes' => 45]]],
			null,
			$timeEntryMapper,
		);

		$this->assertTrue($service->calculateAndSetAutomaticBreak($entry));

		$breaks = json_decode((string)$entry->getBreaks(), true);
		$this->assertIsArray($breaks);
		$this->assertCount(1, $breaks);
		$this->assertSame(45, $breaks[0]['duration_minutes']);
		$this->assertTrue($breaks[0]['automatic']);
		$this->assertSame('org', $breaks[0]['policy_source']);
		$this->assertSame(45, $breaks[0]['required_break_minutes']);
	}

	public function testAutoBreakRespectsLawOnlyWhenNoPolicy(): void
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$entry = new TimeEntry();
		$entry->setId(11);
		$entry->setUserId('u1');
		$entry->setStartTime(new \DateTime('2024-01-15T08:00:00', $tz));
		$entry->setEndTime(new \DateTime('2024-01-15T16:00:00', $tz));

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		// enforced + no tightening tiers → falls back to law, still enforced.
		$service = $this->serviceWithPolicy(
			['enforced' => true, 'tiers' => []],
			null,
			$timeEntryMapper,
		);

		$this->assertTrue($service->calculateAndSetAutomaticBreak($entry));

		$breaks = json_decode((string)$entry->getBreaks(), true);
		$this->assertSame(30, $breaks[0]['duration_minutes']);
		$this->assertSame('law', $breaks[0]['policy_source']);
	}
}
