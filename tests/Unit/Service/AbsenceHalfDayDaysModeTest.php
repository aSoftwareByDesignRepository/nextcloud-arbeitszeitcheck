<?php

declare(strict_types=1);

/**
 * Days-mode half-day vacation write path (resolveVacationDaysDebit + create).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCA\ArbeitszeitCheck\Service\VacationYearWindowResolver;
use OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

class AbsenceHalfDayDaysModeTest extends TestCase
{
	/** @var AbsenceService */
	private $service;

	/** @var AbsenceMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var HolidayService&\PHPUnit\Framework\MockObject\MockObject */
	private $holiday;

	/** @var float|null */
	private $calendarWeight = 1.0;

	/** @var float */
	private $remainingStub = 25.0;

	/** @var bool */
	private $failProspective = false;

	/** @var list<Absence> */
	private array $overlappingRows = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->absenceMapper->method('findOverlapping')->willReturnCallback(function () {
			return $this->overlappingRows;
		});
		$this->absenceMapper->method('lockUserAbsenceWindow');
		$this->absenceMapper->method('insert')->willReturnCallback(static function (Absence $a): Absence {
			$a->setId(42);
			return $a;
		});
		$this->absenceMapper->method('update')->willReturnCallback(static function (Absence $a): Absence {
			return $a;
		});
		$this->overlappingRows = [];

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->method('logAction');

		$team = $this->createMock(TeamResolverService::class);
		$team->method('getColleagueIds')->willReturn([]);
		$team->method('hasAssignableManagerForEmployee')->willReturn(true);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') {
				if ($key === 'require_substitute_types') {
					return '[]';
				}
				if ($key === Constants::CONFIG_VACATION_YEAR_MODE) {
					return Constants::VACATION_YEAR_MODE_CALENDAR;
				}
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_DAYS;
				}
				return $default;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction');
		$db->method('commit');
		$db->method('rollBack');

		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock');
		$locking->method('releaseLock');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t, array $p = []) => $p === [] ? $t : vsprintf($t, $p));

		$this->holiday = $this->createMock(HolidayService::class);
		$this->holiday->method('computeWorkingDaysForUser')->willReturnCallback(function () {
			return (float)$this->calendarWeight;
		});
		$this->holiday->method('computeWorkingDaysPerYearForUser')->willReturnCallback(function (string $uid, \DateTime $start, \DateTime $end): array {
			unset($uid, $end);
			$y = (int)$start->format('Y');
			$w = (float)$this->calendarWeight;
			return $w > 0 ? [$y => $w] : [];
		});

		$alloc = $this->createMock(VacationAllocationService::class);
		$alloc->method('computeYearAllocation')->willReturnCallback(function (
			string $userId,
			int $year,
			?int $excludeAbsenceId = null,
			?\DateTime $prospectiveStart = null,
			?\DateTime $prospectiveEnd = null,
			?\DateTimeInterface $asOf = null,
			?\DateTimeInterface $prospectiveRequestCreatedAt = null,
			bool $persistEntitlementSnapshot = true,
			?float $prospectiveDurationHours = null,
			?float $prospectiveDays = null,
		) {
			unset($userId, $year, $excludeAbsenceId, $prospectiveStart, $prospectiveEnd, $asOf, $prospectiveRequestCreatedAt, $persistEntitlementSnapshot, $prospectiveDurationHours);
			$remaining = $this->remainingStub;
			$need = $prospectiveDays;
			$valid = true;
			if ($this->failProspective) {
				$valid = false;
			} elseif ($need !== null && $need > $remaining + 0.001) {
				$valid = false;
			}
			return [
				'entitlement' => 25.0,
				'total_remaining_for_new_requests' => $remaining,
				'allocation_valid' => $valid,
				'shortfall' => $valid ? 0.0 : max(0.0, (float)$need - $remaining),
				'carryover_opening' => 0.0,
				'carryover_usable_for_new_requests' => 0.0,
				'carryover_expires_on' => null,
				'used_total_working_days' => 0.0,
			];
		});

		$tzConfig = $this->createMock(IConfig::class);
		$tzConfig->method('getAppValue')
			->willReturnCallback(static function ($app, $key, $default) {
				return $key === 'app_timezone' ? 'Europe/Berlin' : $default;
			});
		$dateTimeZone = $this->createMock(\OCP\IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$tz = new TimeZoneService($tzConfig, $dateTimeZone, $userSession, new NullLogger());

		$unit = new VacationUnitService($config);
		$yearResolver = new VacationYearWindowResolver(
			$config,
			$this->createMock(UserEmploymentSettingsService::class)
		);

		$this->service = new AbsenceService(
			$this->absenceMapper,
			$audit,
			$this->createMock(UserSettingsMapper::class),
			$team,
			$this->createMock(UserWorkingTimeModelMapper::class),
			$config,
			$db,
			$locking,
			$this->createMock(IUserManager::class),
			$l10n,
			$this->createMock(NotificationService::class),
			null,
			$this->holiday,
			$this->createMock(VacationYearBalanceMapper::class),
			$alloc,
			null,
			$this->createMock(MonthClosureService::class),
			$tz,
			$yearResolver,
			$unit,
			null,
		);
	}

	public function testCreateHalfDayPersistsPointFive(): void
	{
		$this->calendarWeight = 1.0;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '0.5',
		], 'alice');

		$this->assertEqualsWithDelta(0.5, (float)$absence->getDays(), 0.001);
	}

	public function testCreateFullDayUsesCalendarWeight(): void
	{
		$this->calendarWeight = 1.0;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '1',
		], 'alice');

		$this->assertEqualsWithDelta(1.0, (float)$absence->getDays(), 0.001);
	}

	public function testHalfDayRangeForbidden(): void
	{
		$this->calendarWeight = 3.0;
		$this->expectException(BusinessRuleException::class);
		$this->expectExceptionMessage('Half-day vacation is only allowed');
		try {
			$this->service->createAbsence([
				'type' => Absence::TYPE_VACATION,
				'start_date' => '2026-08-12',
				'end_date' => '2026-08-14',
				'day_fraction' => '0.5',
			], 'alice');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::VAC_HALF_DAY_RANGE_FORBIDDEN, $e->getReasonCode());
			throw $e;
		}
	}

	public function testHalfDayOnHolidayRejected(): void
	{
		$this->calendarWeight = 0.0;
		$this->expectException(BusinessRuleException::class);
		try {
			$this->service->createAbsence([
				'type' => Absence::TYPE_VACATION,
				'start_date' => '2026-08-12',
				'end_date' => '2026-08-12',
				'day_fraction' => 0.5,
			], 'alice');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::VAC_HALF_DAY_NON_WORKING, $e->getReasonCode());
			throw $e;
		}
	}

	public function testInvalidFractionRejected(): void
	{
		$this->expectException(BusinessRuleException::class);
		try {
			$this->service->createAbsence([
				'type' => Absence::TYPE_VACATION,
				'start_date' => '2026-08-12',
				'end_date' => '2026-08-12',
				'day_fraction' => '0.25',
			], 'alice');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::VAC_HALF_DAY_INVALID, $e->getReasonCode());
			throw $e;
		}
	}

	public function testLocaleCommaHalfAccepted(): void
	{
		$this->calendarWeight = 1.0;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '0,5',
		], 'alice');
		$this->assertEqualsWithDelta(0.5, (float)$absence->getDays(), 0.001);
	}

	public function testClientDaysFieldIgnored(): void
	{
		$this->calendarWeight = 1.0;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'days' => 0.01,
			'working_days' => 0.01,
			'day_fraction' => '1',
		], 'alice');
		$this->assertEqualsWithDelta(1.0, (float)$absence->getDays(), 0.001);
	}

	/**
	 * Argus NG-02: forging only `days=0.5` (no day_fraction) must NOT grant a half debit.
	 */
	public function testClientDaysAloneDoesNotCreateHalfDay(): void
	{
		$this->calendarWeight = 1.0;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'days' => 0.5,
			'working_days' => 0.5,
		], 'alice');
		$this->assertEqualsWithDelta(1.0, (float)$absence->getDays(), 0.001);
	}

	public function testHalfDayAllowedWhenRemainingIsExactlyHalf(): void
	{
		$this->calendarWeight = 1.0;
		$this->remainingStub = 0.5;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '0.5',
		], 'alice');
		$this->assertEqualsWithDelta(0.5, (float)$absence->getDays(), 0.001);
	}

	public function testFullDayRejectedWhenRemainingIsExactlyHalf(): void
	{
		$this->calendarWeight = 1.0;
		$this->remainingStub = 0.5;
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Not enough vacation remaining');
		$this->service->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '1',
		], 'alice');
	}

	public function testHoursModeIgnoresDayFraction(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_HOURS;
				}
				if ($key === Constants::CONFIG_VACATION_HOURS_PER_DAY) {
					return '8';
				}
				if ($key === 'require_substitute_types') {
					return '[]';
				}
				if ($key === Constants::CONFIG_VACATION_YEAR_MODE) {
					return Constants::VACATION_YEAR_MODE_CALENDAR;
				}
				return $default;
			}
		);
		$unit = new VacationUnitService($config);
		$ref = new ReflectionClass($this->service);
		foreach (['vacationUnitService' => $unit, 'config' => $config] as $prop => $val) {
			$p = $ref->getProperty($prop);
			$p->setAccessible(true);
			$p->setValue($this->service, $val);
		}

		$this->calendarWeight = 1.0;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '0.5',
			'duration_hours' => '8',
			'require_duration_hours' => true,
		], 'alice');

		// Days column stays calendar weight; half fraction ignored in hours mode.
		$this->assertEqualsWithDelta(1.0, (float)$absence->getDays(), 0.001);
		$this->assertEqualsWithDelta(8.0, (float)$absence->getDurationHours(), 0.001);
	}

	public function testDayFractionMatrixViaCanonicalize(): void
	{
		$date = new \DateTime('2026-08-12');
		$canon = fn (array $data): array => \OCA\ArbeitszeitCheck\Support\AbsenceDayShape::canonicalize(
			$data, $date, clone $date
		);
		$this->assertEqualsWithDelta(1.0, $canon([])['start_fraction'], 1e-9);
		$this->assertEqualsWithDelta(1.0, $canon(['day_fraction' => ''])['start_fraction'], 1e-9);
		$this->assertEqualsWithDelta(0.5, $canon(['day_fraction' => '0.5'])['start_fraction'], 1e-9);
		$this->assertEqualsWithDelta(0.5, $canon(['day_fraction' => '0,5'])['start_fraction'], 1e-9);
		$this->assertEqualsWithDelta(1.0, $canon(['day_fraction' => '1,0'])['start_fraction'], 1e-9);
		$this->expectException(\OCA\ArbeitszeitCheck\Support\ShapeInputException::class);
		$canon(['day_fraction' => '0.5000001']);
	}

	public function testUpdateHalfToFullAndFullToHalf(): void
	{
		$this->calendarWeight = 1.0;
		$existing = new Absence();
		$existing->setId(7);
		$existing->setUserId('alice');
		$existing->setType(Absence::TYPE_VACATION);
		$existing->setStatus(Absence::STATUS_PENDING);
		$existing->setStartDate(new \DateTime('2026-08-12'));
		$existing->setEndDate(new \DateTime('2026-08-12'));
		$existing->setDays(0.5);
		$existing->setCreatedAt(new \DateTime('2026-08-01'));
		$existing->setUpdatedAt(new \DateTime('2026-08-01'));
		$this->absenceMapper->method('find')->willReturn($existing);

		$full = $this->service->updateAbsence(7, [
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '1',
		], 'alice');
		$this->assertEqualsWithDelta(1.0, (float)$full->getDays(), 0.001);

		$half = $this->service->updateAbsence(7, [
			'day_fraction' => '0.5',
		], 'alice');
		$this->assertEqualsWithDelta(0.5, (float)$half->getDays(), 0.001);
	}

	/**
	 * Zeus MF-01: reason-only / API patch without day_fraction must not inflate 0.5 → 1.0.
	 */
	public function testUpdateOmittingDayFractionPreservesTrustedHalfDay(): void
	{
		$this->calendarWeight = 1.0;
		$existing = new Absence();
		$existing->setId(71);
		$existing->setUserId('alice');
		$existing->setType(Absence::TYPE_VACATION);
		$existing->setStatus(Absence::STATUS_PENDING);
		$existing->setStartDate(new \DateTime('2026-08-12'));
		$existing->setEndDate(new \DateTime('2026-08-12'));
		$existing->setDays(0.5);
		$existing->setReason('doctor');
		$existing->setCreatedAt(new \DateTime('2026-08-01'));
		$existing->setUpdatedAt(new \DateTime('2026-08-01'));
		$this->absenceMapper->method('find')->willReturn($existing);

		$updated = $this->service->updateAbsence(71, [
			'reason' => 'doctor follow-up',
		], 'alice');
		$this->assertEqualsWithDelta(0.5, (float)$updated->getDays(), 0.001);
		$this->assertSame('doctor follow-up', $updated->getReason());
	}

	/**
	 * Expanding a half-day to a multi-day range without day_fraction must use calendar weight
	 * (not preserve 0.5 under-debit).
	 */
	public function testUpdateExpandRangeWithoutDayFractionUsesCalendar(): void
	{
		$this->calendarWeight = 3.0;
		$existing = new Absence();
		$existing->setId(72);
		$existing->setUserId('alice');
		$existing->setType(Absence::TYPE_VACATION);
		$existing->setStatus(Absence::STATUS_PENDING);
		$existing->setStartDate(new \DateTime('2026-08-12'));
		$existing->setEndDate(new \DateTime('2026-08-12'));
		$existing->setDays(0.5);
		$existing->setCreatedAt(new \DateTime('2026-08-01'));
		$existing->setUpdatedAt(new \DateTime('2026-08-01'));
		$this->absenceMapper->method('find')->willReturn($existing);

		$updated = $this->service->updateAbsence(72, [
			'end_date' => '2026-08-14',
		], 'alice');
		$this->assertEqualsWithDelta(3.0, (float)$updated->getDays(), 0.001);
	}

	public function testUpdateHalfToMultiDayRangeForbidden(): void
	{
		$this->calendarWeight = 3.0;
		$existing = new Absence();
		$existing->setId(8);
		$existing->setUserId('alice');
		$existing->setType(Absence::TYPE_VACATION);
		$existing->setStatus(Absence::STATUS_PENDING);
		$existing->setStartDate(new \DateTime('2026-08-12'));
		$existing->setEndDate(new \DateTime('2026-08-12'));
		$existing->setDays(0.5);
		$existing->setCreatedAt(new \DateTime('2026-08-01'));
		$existing->setUpdatedAt(new \DateTime('2026-08-01'));
		$this->absenceMapper->method('find')->willReturn($existing);

		$this->expectException(BusinessRuleException::class);
		try {
			$this->service->updateAbsence(8, [
				'end_date' => '2026-08-14',
				'day_fraction' => '0.5',
			], 'alice');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::VAC_HALF_DAY_RANGE_FORBIDDEN, $e->getReasonCode());
			throw $e;
		}
	}

	public function testOverlapBlocksSecondHalfSameDay(): void
	{
		$this->calendarWeight = 1.0;
		$blocker = new Absence();
		$blocker->setId(99);
		$blocker->setType(Absence::TYPE_VACATION);
		$blocker->setStatus(Absence::STATUS_PENDING);
		$blocker->setStartDate(new \DateTime('2026-08-12'));
		$blocker->setEndDate(new \DateTime('2026-08-12'));
		$this->overlappingRows = [$blocker];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('overlaps');
		$this->service->createAbsence([
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '0.5',
		], 'alice');
	}

	public function testManagerCreateApprovedHalfDay(): void
	{
		$this->calendarWeight = 1.0;
		$absence = $this->service->createApprovedAbsenceForEmployeeByManager('manager1', 'alice', [
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '0.5',
			'reason' => 'Doctor',
		]);
		$this->assertEqualsWithDelta(0.5, (float)$absence->getDays(), 0.001);
		$this->assertSame(Absence::STATUS_APPROVED, $absence->getStatus());
	}
}
