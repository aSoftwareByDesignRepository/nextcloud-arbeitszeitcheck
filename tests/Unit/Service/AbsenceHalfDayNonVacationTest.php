<?php

declare(strict_types=1);

/**
 * Opt-in half-day for non-vacation absence types
 * (AZC-HALF-DAY-NON-VACATION): enabled gate, range guard, weight×fraction
 * debit, trusted stored-fraction preservation and entitlement bypass.
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
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementService;
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

class AbsenceHalfDayNonVacationTest extends TestCase
{
	/** @var AbsenceService */
	private $service;

	/** @var AbsenceMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var HolidayService&\PHPUnit\Framework\MockObject\MockObject */
	private $holiday;

	/** @var float|null */
	private $calendarWeight = 1.0;

	/** @var string */
	private $enabledTypesJson = '["vacation","sick_leave"]';

	/** @var bool */
	private $entitlementBound = false;

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
			function (string $app, string $key, $default = '') {
				if ($key === 'require_substitute_types') {
					return '[]';
				}
				if ($key === Constants::CONFIG_VACATION_YEAR_MODE) {
					return Constants::VACATION_YEAR_MODE_CALENDAR;
				}
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_DAYS;
				}
				if ($key === Constants::CONFIG_HALF_DAY_ENABLED_TYPES) {
					return $this->enabledTypesJson;
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
		$alloc->method('computeYearAllocation')->willReturn([
			'entitlement' => 25.0,
			'total_remaining_for_new_requests' => 25.0,
			'allocation_valid' => true,
			'shortfall' => 0.0,
			'carryover_opening' => 0.0,
			'carryover_usable_for_new_requests' => 0.0,
			'carryover_expires_on' => null,
			'used_total_working_days' => 0.0,
		]);

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

		// SpecialEntitlementService is final — build the real instance on
		// mocked mappers so only isEnabled()/findByAbsenceTypeCode drive the
		// EC-14 bypass branch; the reservation flow is never reached because
		// findByAbsenceTypeCode returns null unless $entitlementBound is set.
		$suiteConfigMock = $this->createMock(IConfig::class);
		$suiteConfigMock->method('getAppValue')->willReturnCallback(function ($app, $key, $default) {
			return in_array($key, ['special_entitlements_enabled', 'policy_cohorts_enabled'], true)
				? '1'
				: $default;
		});
		$typesMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper::class);
		$typesMapper->method('findByAbsenceTypeCode')->willReturnCallback(function (string $code) {
			if (!$this->entitlementBound) {
				return null;
			}
			$type = new \OCA\ArbeitszeitCheck\Db\SpecialEntitlementType();
			$type->setCode('bound_days');
			$type->setAbsenceTypeCode($code);
			$type->setActive(true);
			$type->setUnit(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementType::UNIT_DAYS);
			return $type;
		});
		$wsc = new \OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig($suiteConfigMock);
		$special = new SpecialEntitlementService(
			$wsc,
			$typesMapper,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper::class),
			// Final — real instance; the resolver is never reached in these
			// tests (only the binding predicate runs).
			new \OCA\ArbeitszeitCheck\Service\SpecialEntitlementResolver(
				$this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper::class),
				$this->createMock(UserWorkingTimeModelMapper::class),
				$this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
				$this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
				new \OCA\ArbeitszeitCheck\Service\PolicyCohortResolver(
					$wsc,
					$this->createMock(\OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper::class)
				),
				$this->createMock(UserEmploymentSettingsService::class),
			),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\EntitlementSnapshotService::class),
			$this->createMock(AuditLogMapper::class),
			$this->absenceMapper,
			$locking,
			$db,
		);
		$ref = new ReflectionClass($this->service);
		$p = $ref->getProperty('specialEntitlementService');
		$p->setAccessible(true);
		$p->setValue($this->service, $special);
	}

	private function storedAbsence(string $type, float $days, string $start, string $end): Absence
	{
		$existing = new Absence();
		$existing->setId(7);
		$existing->setUserId('alice');
		$existing->setType($type);
		$existing->setStatus(Absence::STATUS_PENDING);
		$existing->setStartDate(new \DateTime($start));
		$existing->setEndDate(new \DateTime($end));
		$existing->setDays($days);
		$existing->setCreatedAt(new \DateTime('2026-08-01'));
		$existing->setUpdatedAt(new \DateTime('2026-08-01'));
		return $existing;
	}

	public function testCreateSickHalfDayWhenEnabled(): void
	{
		$this->calendarWeight = 1.0;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '0.5',
		], 'alice');
		$this->assertEqualsWithDelta(0.5, (float)$absence->getDays(), 0.001);
	}

	public function testCreateSickHalfDayWhenDisabledThrows(): void
	{
		$this->enabledTypesJson = '["vacation"]';
		$this->expectException(BusinessRuleException::class);
		try {
			$this->service->createAbsence([
				'type' => Absence::TYPE_SICK_LEAVE,
				'start_date' => '2026-08-12',
				'end_date' => '2026-08-12',
				'day_fraction' => '0.5',
			], 'alice');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::HALF_DAY_TYPE_NOT_ENABLED, $e->getReasonCode());
			throw $e;
		}
	}

	public function testCreateSickMultiDayHalfRejected(): void
	{
		$this->calendarWeight = 3.0;
		$this->expectException(BusinessRuleException::class);
		try {
			$this->service->createAbsence([
				'type' => Absence::TYPE_SICK_LEAVE,
				'start_date' => '2026-08-12',
				'end_date' => '2026-08-14',
				'day_fraction' => '0.5',
			], 'alice');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::HALF_DAY_RANGE_FORBIDDEN, $e->getReasonCode());
			throw $e;
		}
	}

	public function testCreateSickInvalidFractionRejected(): void
	{
		$this->expectException(BusinessRuleException::class);
		try {
			$this->service->createAbsence([
				'type' => Absence::TYPE_SICK_LEAVE,
				'start_date' => '2026-08-12',
				'end_date' => '2026-08-12',
				'day_fraction' => '0.25',
			], 'alice');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::HALF_DAY_INVALID, $e->getReasonCode());
			throw $e;
		}
	}

	public function testOvertimeCompHalfAlwaysRejected(): void
	{
		// Even a hand-crafted allowlist can never arm full-day-only types.
		$this->enabledTypesJson = '["vacation","overtime_comp"]';
		$this->expectException(\Exception::class);
		$this->service->createAbsence([
			'type' => Absence::TYPE_OVERTIME_COMP,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '0.5',
		], 'alice');
	}

	public function testHalfDayOnHalfWeightDayStoresQuarter(): void
	{
		// Company half-day holiday: weight 0.5 → half-day debit is 0.25,
		// not the literal 0.5 the old heuristic assumed.
		$this->calendarWeight = 0.5;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '0.5',
		], 'alice');
		$this->assertEqualsWithDelta(0.25, (float)$absence->getDays(), 0.001);
	}

	public function testClientDaysForgeryIgnoredForSick(): void
	{
		$this->calendarWeight = 1.0;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'days' => 0.5,
			'working_days' => 0.5,
		], 'alice');
		$this->assertEqualsWithDelta(1.0, (float)$absence->getDays(), 0.001);
	}

	public function testFullDaySickUnchangedByFeature(): void
	{
		$this->calendarWeight = 2.0;
		$absence = $this->service->createAbsence([
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-13',
		], 'alice');
		$this->assertEqualsWithDelta(2.0, (float)$absence->getDays(), 0.001);
	}

	public function testManagerCreateApprovedSickHalfDay(): void
	{
		$this->calendarWeight = 1.0;
		$absence = $this->service->createApprovedAbsenceForEmployeeByManager('manager1', 'alice', [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => '2026-08-12',
			'end_date' => '2026-08-12',
			'day_fraction' => '0.5',
			'reason' => 'Doctor',
		]);
		$this->assertEqualsWithDelta(0.5, (float)$absence->getDays(), 0.001);
		$this->assertSame(Absence::STATUS_APPROVED, $absence->getStatus());
	}

	public function testManagerCreateSickHalfDayWhenDisabledThrows(): void
	{
		$this->enabledTypesJson = '["vacation"]';
		$this->expectException(BusinessRuleException::class);
		try {
			$this->service->createApprovedAbsenceForEmployeeByManager('manager1', 'alice', [
				'type' => Absence::TYPE_SICK_LEAVE,
				'start_date' => '2026-08-12',
				'end_date' => '2026-08-12',
				'day_fraction' => '0.5',
			]);
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::HALF_DAY_TYPE_NOT_ENABLED, $e->getReasonCode());
			throw $e;
		}
	}

	public function testUpdatePreservesTrustedSickHalfDay(): void
	{
		$this->calendarWeight = 1.0;
		$this->absenceMapper->method('find')->willReturn(
			$this->storedAbsence(Absence::TYPE_SICK_LEAVE, 0.5, '2026-08-12', '2026-08-12')
		);
		$updated = $this->service->updateAbsence(7, [
			'reason' => 'doctor follow-up',
		], 'alice');
		$this->assertEqualsWithDelta(0.5, (float)$updated->getDays(), 0.001);
	}

	public function testUpdatePreservesTrustedQuarterOnHalfWeightDay(): void
	{
		// Weight-0.5 day: stored 0.25 = half debit — a literal days≈0.5 test
		// would have re-inflated it to the full 0.5 weight.
		$this->calendarWeight = 0.5;
		$this->absenceMapper->method('find')->willReturn(
			$this->storedAbsence(Absence::TYPE_SICK_LEAVE, 0.25, '2026-08-12', '2026-08-12')
		);
		$updated = $this->service->updateAbsence(7, [
			'reason' => 'still sick',
		], 'alice');
		$this->assertEqualsWithDelta(0.25, (float)$updated->getDays(), 0.001);
	}

	public function testUpdateExplicitFractionOnDisabledTypeThrows(): void
	{
		// Flag switched off between storage and edit: an explicit client
		// day_fraction is never trusted — the gate still applies.
		$this->enabledTypesJson = '["vacation"]';
		$this->calendarWeight = 1.0;
		$this->absenceMapper->method('find')->willReturn(
			$this->storedAbsence(Absence::TYPE_SICK_LEAVE, 0.5, '2026-08-12', '2026-08-12')
		);
		$this->expectException(BusinessRuleException::class);
		try {
			$this->service->updateAbsence(7, [
				'day_fraction' => '0.5',
			], 'alice');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::HALF_DAY_TYPE_NOT_ENABLED, $e->getReasonCode());
			throw $e;
		}
	}

	public function testUpdateOmittedFractionSurvivesFlagOff(): void
	{
		// TOCTOU: flag switched off after storage — a reason-only patch must
		// preserve the stored half (gate guards entry, not historical truth).
		$this->enabledTypesJson = '["vacation"]';
		$this->calendarWeight = 1.0;
		$this->absenceMapper->method('find')->willReturn(
			$this->storedAbsence(Absence::TYPE_SICK_LEAVE, 0.5, '2026-08-12', '2026-08-12')
		);
		$updated = $this->service->updateAbsence(7, [
			'reason' => 'still sick',
		], 'alice');
		$this->assertEqualsWithDelta(0.5, (float)$updated->getDays(), 0.001);
	}

	public function testEntitlementBoundTypeBypassesGate(): void
	{
		// EC-14: a days-unit entitlement binding owns {0.25,0.5,0.75,1} — the
		// generic opt-in gate must never fire for it. Tested via updateAbsence
		// because create funnels into the entitlement reservation flow first;
		// the gate bypass lives in resolveNonVacationDaysDebit either way.
		$this->entitlementBound = true;
		$this->enabledTypesJson = '["vacation"]';
		$this->calendarWeight = 1.0;
		$this->absenceMapper->method('find')->willReturn(
			$this->storedAbsence(Absence::TYPE_SICK_LEAVE, 1.0, '2026-08-12', '2026-08-12')
		);
		$updated = $this->service->updateAbsence(7, [
			'day_fraction' => '0.5',
		], 'alice');
		// Bound types store full schedule weight — the fraction lives in the
		// entitlement ledger, not in absences.days.
		$this->assertEqualsWithDelta(1.0, (float)$updated->getDays(), 0.001);
	}
}
