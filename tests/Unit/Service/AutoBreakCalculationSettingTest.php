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
}
