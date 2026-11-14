<?php

declare(strict_types=1);

/**
 * Mutation-style source + behaviour contracts for ArbZG §5 rest vs midnight.
 *
 * Stamp and manual paths share {@see ComplianceService::isIntradayWorkInterruption}:
 * same-calendar-day geteilte Arbeitszeit is allowed; overnight Wachdienst is not.
 * Unsafe blanket checks (end day === now day without start-day proof) must never return.
 */

namespace OCA\ArbeitszeitCheck\Tests\Mutation;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ArbzgRestMidnightMutationTest extends TestCase
{
	public function testStampAndManualShareSafeIntradayGate(): void
	{
		$src = file_get_contents(__DIR__ . '/../../lib/Service/ComplianceService.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('isIntradayWorkInterruption', $src);
		$this->assertStringContainsString('Overnight Wachdienst ending after 00:00 must still enforce', $src);
		$this->assertStringContainsString('evaluateRestPeriodForClockIn', $src);
		$this->assertStringContainsString('identical rule to', $src);
		// Must not reintroduce the unsafe blanket same-day skip (end day === now day only).
		$this->assertDoesNotMatchRegularExpression(
			'/function evaluateRestPeriodForClockIn.*?\$lastEndDay\s*===\s*\$nowDay/s',
			$src
		);
		// Stamp path must call the shared intraday helper (not a parallel unsafe shortcut).
		$this->assertMatchesRegularExpression(
			'/function evaluateRestPeriodForClockIn.*?isIntradayWorkInterruption\s*\(/s',
			$src
		);
	}

	public function testOvernightMorningStartBlockedOnManualPath(): void
	{
		$service = $this->buildService();
		$tz = new \DateTimeZone('Europe/Berlin');
		$mapper = $this->createMock(TimeEntryMapper::class);

		$ref = new \ReflectionClass($service);
		$prop = $ref->getProperty('timeEntryMapper');
		$prop->setValue($service, $mapper);

		$last = new TimeEntry();
		$last->setId(1);
		$last->setUserId('n');
		$last->setStartTime(new \DateTime('2026-03-14 22:00:00', $tz));
		$last->setEndTime(new \DateTime('2026-03-15 06:00:00', $tz));
		$last->setStatus(TimeEntry::STATUS_COMPLETED);

		$start = new \DateTime('2026-03-15 10:00:00', $tz);
		$mapper->method('findLastCompletedBeforeTime')->willReturn($last);

		$result = $service->checkRestPeriodForStartTime('n', $start);
		$this->assertFalse($result['valid']);
	}

	public function testIntradaySplitStillAllowedOnManualPath(): void
	{
		$service = $this->buildService();
		$tz = new \DateTimeZone('Europe/Berlin');
		$mapper = $this->createMock(TimeEntryMapper::class);

		$ref = new \ReflectionClass($service);
		$prop = $ref->getProperty('timeEntryMapper');
		$prop->setValue($service, $mapper);

		$last = new TimeEntry();
		$last->setId(2);
		$last->setUserId('d');
		$last->setStartTime(new \DateTime('2026-03-15 09:00:00', $tz));
		$last->setEndTime(new \DateTime('2026-03-15 12:00:00', $tz));
		$last->setStatus(TimeEntry::STATUS_COMPLETED);

		$start = new \DateTime('2026-03-15 13:00:00', $tz);
		$mapper->method('findLastCompletedBeforeTime')->willReturn($last);

		$result = $service->checkRestPeriodForStartTime('d', $start);
		$this->assertTrue($result['valid']);
	}

	public function testStampPathAllowsIntradaySplitOnClockInGate(): void
	{
		$service = $this->buildService();
		$tz = new \DateTimeZone('Europe/Berlin');
		$mapper = $this->createMock(TimeEntryMapper::class);

		$ref = new \ReflectionClass($service);
		$prop = $ref->getProperty('timeEntryMapper');
		$prop->setValue($service, $mapper);

		$tzSvc = $ref->getProperty('timeZoneService')->getValue($service);
		$now = $tzSvc->nowInStorage();
		$morningEnd = (clone $now)->modify('-2 hours');
		$morningStart = (clone $morningEnd)->modify('-3 hours');
		if ($morningStart->format('Y-m-d') !== $now->format('Y-m-d')) {
			$morningStart = (clone $now)->setTime(8, 0, 0);
			$morningEnd = (clone $now)->setTime(11, 0, 0);
			if ($morningEnd->getTimestamp() >= $now->getTimestamp()) {
				$morningEnd = (clone $now)->modify('-30 minutes');
				$morningStart = (clone $morningEnd)->modify('-3 hours');
			}
		}

		$last = new TimeEntry();
		$last->setId(3);
		$last->setUserId('stamp');
		$last->setStartTime($morningStart);
		$last->setEndTime($morningEnd);
		$last->setStatus(TimeEntry::STATUS_COMPLETED);
		$mapper->method('findLastCompletedBeforeTime')->willReturn($last);
		$mapper->method('findOverlapping')->willReturn([]);
		$mapper->method('getTotalHoursByUserAndDateRange')->willReturn(100.0);

		$issues = $service->checkComplianceBeforeClockIn('stamp');
		$rest = array_values(array_filter(
			$issues,
			static fn (array $i): bool => ($i['type'] ?? '') === ComplianceViolation::TYPE_INSUFFICIENT_REST_PERIOD
		));
		$this->assertSame([], $rest, 'Stamp path must allow same-day split');
	}

	public function testStampPathBlocksOvernightOnClockInGate(): void
	{
		$service = $this->buildService();
		$mapper = $this->createMock(TimeEntryMapper::class);

		$ref = new \ReflectionClass($service);
		$prop = $ref->getProperty('timeEntryMapper');
		$prop->setValue($service, $mapper);

		$tzSvc = $ref->getProperty('timeZoneService')->getValue($service);
		$now = $tzSvc->nowInStorage();
		$endTime = (clone $now)->modify('-4 hours');
		$startTime = (clone $endTime)->modify('-8 hours');
		if ($startTime->format('Y-m-d') === $endTime->format('Y-m-d')) {
			$startTime = (clone $endTime)->setTime(0, 0, 0)->modify('-2 hours');
		}

		$last = new TimeEntry();
		$last->setId(4);
		$last->setUserId('night');
		$last->setStartTime($startTime);
		$last->setEndTime($endTime);
		$last->setStatus(TimeEntry::STATUS_COMPLETED);
		$mapper->method('findLastCompletedBeforeTime')->willReturn($last);
		$mapper->method('findOverlapping')->willReturn([]);
		$mapper->method('getTotalHoursByUserAndDateRange')->willReturn(100.0);

		$issues = $service->checkComplianceBeforeClockIn('night');
		$rest = array_values(array_filter(
			$issues,
			static fn (array $i): bool => ($i['type'] ?? '') === ComplianceViolation::TYPE_INSUFFICIENT_REST_PERIOD
		));
		$this->assertNotEmpty($rest, 'Overnight end must still block stamp-path clock-in');
		$this->assertStringContainsString('Pause', (string)$rest[0]['message']);
	}

	private function buildService(): ComplianceService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []) {
				$parameters = is_array($parameters) ? $parameters : [$parameters];
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			}
		);
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$tz = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());
		$mapper = $this->createMock(TimeEntryMapper::class);
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isUserAllowedByAccessGroups')->willReturn(true);

		return new ComplianceService(
			$mapper,
			$this->createMock(ComplianceViolationMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(IUserManager::class),
			$l10n,
			$this->createMock(NotificationService::class),
			$this->createMock(HolidayService::class),
			$config,
			$permission,
			$tz,
			new DailyWorkingHoursCalculator($mapper, $tz),
			new LaborLawProfileFactory($config),
		);
	}
}
