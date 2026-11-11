<?php

declare(strict_types=1);

/**
 * Unit tests for AbsenceService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
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
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\AbsenceNotificationMailService;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\SpecialEntitlementService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Class AbsenceServiceTest
 */
class AbsenceServiceTest extends TestCase
{
	/** @var AbsenceService */
	private $service;

	/** @var AbsenceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var UserSettingsMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userSettingsMapper;

	/** @var TeamResolverService|\PHPUnit\Framework\MockObject\MockObject */
	private $teamResolver;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var NotificationService|\PHPUnit\Framework\MockObject\MockObject */
	private $notificationService;

	/** @var HolidayService|\PHPUnit\Framework\MockObject\MockObject */
	private $holidayCalendarService;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var UserWorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userWorkingTimeModelMapper;

	/** @var VacationYearBalanceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $vacationYearBalanceMapper;

	/** @var VacationAllocationService|\PHPUnit\Framework\MockObject\MockObject */
	private $vacationAllocationService;

	/** @var array<string,mixed>|null Override for VacationAllocationService::computeYearAllocation return shape */
	private ?array $vacationAllocationStub = null;

	/** When true, prospective allocation (non-null dates) returns allocation_valid false */
	private bool $vacationAllocationFailProspective = false;

	/** Controls TeamResolverService::hasAssignableManagerForEmployee in unit tests */
	private bool $hasAssignableManagerForTests = true;

	/** @var IDBConnection|\PHPUnit\Framework\MockObject\MockObject */
	private $db;

	/** @var ILockingProvider|\PHPUnit\Framework\MockObject\MockObject */
	private $lockingProvider;

	/** @var MonthClosureService|\PHPUnit\Framework\MockObject\MockObject */
	private $monthClosureService;

	protected function setUp(): void
	{
		parent::setUp();

		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->teamResolver->method('getColleagueIds')->willReturnCallback(function ($userId) {
			return ['colleague1', 'colleague2'];
		});
		$this->hasAssignableManagerForTests = true;
		$this->teamResolver->method('hasAssignableManagerForEmployee')->willReturnCallback(function () {
			return $this->hasAssignableManagerForTests;
		});
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default = '') {
			if ($app !== 'arbeitszeitcheck') {
				return $default;
			}
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
		});
		$this->db = $this->createMock(IDBConnection::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->lockingProvider->method('acquireLock');
		$this->lockingProvider->method('releaseLock');
		$this->monthClosureService = $this->createMock(MonthClosureService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(function (string $uid) {
			if (in_array($uid, ['colleague1', 'colleague2', 'designated_substitute', 'substitute1'], true)) {
				$user = $this->createMock(\OCP\IUser::class);
				$user->method('isEnabled')->willReturn(true);
				$user->method('getUID')->willReturn($uid);
				$user->method('getDisplayName')->willReturn($uid);
				return $user;
			}
			return null;
		});
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->vacationYearBalanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$this->vacationYearBalanceMapper->method('getCarryoverDays')->willReturn(0.0);
		$this->l10n = $this->createMock(IL10N::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->holidayCalendarService = $this->createMock(HolidayService::class);
		$this->holidayCalendarService
			->method('computeWorkingDaysPerYearForUser')
			->willReturnCallback(static function (string $userId, \DateTime $start, \DateTime $end): array {
				unset($userId);
				$result = [];
				$cur = (clone $start)->setTime(0, 0, 0);
				$endNorm = (clone $end)->setTime(0, 0, 0);
				while ($cur <= $endNorm) {
					if ((int)$cur->format('N') < 6) {
						$y = (int)$cur->format('Y');
						$result[$y] = ($result[$y] ?? 0) + 1.0;
					}
					$cur->modify('+1 day');
				}
				return $result;
			});
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(2.0);

		$this->l10n->method('t')
			->willReturnCallback(function ($text) {
				return $text;
			});

		$this->vacationAllocationStub = null;
		$this->vacationAllocationFailProspective = false;
		$this->vacationAllocationService = $this->createMock(VacationAllocationService::class);
		$this->vacationAllocationService->method('isCarryoverUsableForNewRequests')->willReturn(true);
		$this->vacationAllocationService->method('getMaxCarryoverOpeningCap')->willReturn(null);
		$this->vacationAllocationService->method('computeYearAllocation')->willReturnCallback(function ($userId, $year, $exclude, $pStart, $pEnd, $asOf, $createdAt = null, $persistSnapshot = true) {
			unset($userId, $year, $exclude, $asOf, $createdAt, $persistSnapshot);
			if ($this->vacationAllocationStub !== null) {
				return $this->vacationAllocationStub;
			}
			if ($this->vacationAllocationFailProspective && $pStart !== null && $pEnd !== null) {
				return [
					'entitlement' => 25.0,
					'carryover_opening' => 0.0,
					'carryover_usable_for_new_requests' => 0.0,
					'carryover_expires_on' => null,
					'total_remaining_for_new_requests' => 0.0,
					'used_total_working_days' => 0.0,
					'allocation_valid' => false,
					'shortfall' => 4.0,
				];
			}
			return [
				'entitlement' => 25.0,
				'carryover_opening' => 0.0,
				'carryover_usable_for_new_requests' => 0.0,
				'carryover_expires_on' => null,
				'total_remaining_for_new_requests' => 25.0,
				'used_total_working_days' => 0.0,
				'allocation_valid' => true,
				'shortfall' => 0.0,
			];
		});

		$this->service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$this->lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService
		);
	}

	/**
	 * Test creating a valid absence request
	 */
	public function testCreateAbsenceSuccess(): void
	{
		$userId = 'testuser';
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+4 days');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'reason' => 'Summer vacation'
		];

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->with($userId, $this->isInstanceOf(\DateTime::class), $this->isInstanceOf(\DateTime::class), $this->anything())
			->willReturn([]);

		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')
			->with($userId, 'vacation_days_per_year', 25)->willReturn(25);

		$absence = new Absence();
		$absence->setId(123);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(5.0);

		$this->absenceMapper->expects($this->once())
			->method('lockUserAbsenceWindow');

		$this->absenceMapper->expects($this->once())
			->method('insert')
			->willReturn($absence);

		// Mock audit logging
		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_created', 'absence', 123, null, $this->isType('array'));

		$result = $this->service->createAbsence($data, $userId);

		$this->assertSame($absence, $result);
	}

	/**
	 * Custom-type writes must serialize against admin delete/deactivate:
	 * a `custom_*` create acquires the SHARED catalog lock (exclusive twin
	 * lives in CustomAbsenceTypeService) and releases it on every exit path —
	 * including validation failure. Built-in types must not touch it.
	 */
	public function testCustomTypeCreateAcquiresAndReleasesCatalogLock(): void
	{
		$acquired = [];
		$released = [];
		$lp = $this->createMock(ILockingProvider::class);
		$lp->method('acquireLock')->willReturnCallback(
			static function (string $key, int $type) use (&$acquired): void {
				$acquired[] = [$key, $type];
			}
		);
		$lp->method('releaseLock')->willReturnCallback(
			static function (string $key, int $type) use (&$released): void {
				$released[] = [$key, $type];
			}
		);
		$service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$lp,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService
		);

		$start = (new \DateTime())->modify('+14 days');
		try {
			// custom_* type — catalog lock must wrap even the failing validation
			$service->createAbsence([
				'type' => 'custom_lockprobe',
				'start_date' => $start->format('Y-m-d'),
				'end_date' => $start->format('Y-m-d'),
			], 'testuser');
		} catch (\Throwable $e) {
			// validation failure acceptable — we assert the lock protocol
		}
		$this->assertContains(
			[DbLockKeys::absenceTypeCatalog(), ILockingProvider::LOCK_SHARED],
			$acquired,
			'custom-type create must acquire the shared catalog lock'
		);
		$this->assertContains(
			[DbLockKeys::absenceTypeCatalog(), ILockingProvider::LOCK_SHARED],
			$released,
			'catalog lock must be released on the exception path'
		);
	}

	public function testBuiltinTypeNeverTouchesCatalogLock(): void
	{
		$acquired = [];
		$lp = $this->createMock(ILockingProvider::class);
		$lp->method('acquireLock')->willReturnCallback(
			static function (string $key, int $type) use (&$acquired): void {
				$acquired[] = $key;
			}
		);
		$lp->method('releaseLock');
		$service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$lp,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService
		);

		try {
			$service->updateAbsence(999, [
				'type' => Absence::TYPE_PERSONAL_LEAVE,
				'start_date' => '2026-10-22',
				'end_date' => '2026-10-22',
			], 'testuser');
		} catch (\Throwable $e) {
			// find(999) may throw — irrelevant; only the lock behavior matters
		}
		$this->assertNotContains(DbLockKeys::absenceTypeCatalog(), $acquired);
	}

	public function testCatalogLockContentionThrowsCatalogBusyException(): void
	{
		// Fail-fast catalog contention must surface as CatalogBusyException
		// (HTTP 409 ABSENCE_TYPE_CATALOG_BUSY) — never an opaque 500 — and must
		// still release the already-held user mutation lock.
		$released = [];
		$lp = $this->createMock(ILockingProvider::class);
		$lp->method('acquireLock')->willReturnCallback(
			static function (string $key, int $type): void {
				if ($key === DbLockKeys::absenceTypeCatalog()) {
					throw new \OCP\Lock\LockedException($key);
				}
			}
		);
		$lp->method('releaseLock')->willReturnCallback(
			static function (string $key, int $type) use (&$released): void {
				$released[] = $key;
			}
		);
		$service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$lp,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService
		);

		$start = (new \DateTime())->modify('+14 days');
		try {
			$service->createAbsence([
				'type' => 'custom_lockprobe',
				'start_date' => $start->format('Y-m-d'),
				'end_date' => $start->format('Y-m-d'),
			], 'testuser');
			$this->fail('expected CatalogBusyException');
		} catch (\OCA\ArbeitszeitCheck\Exception\CatalogBusyException $e) {
			$this->assertSame('ABSENCE_TYPE_CATALOG_BUSY', $e->getReasonCode());
		}
		$this->assertNotEmpty($released, 'user mutation lock must be released on the contention path');
	}

	public function testCreateAbsenceTriggersHrRequestCreatedNotification(): void
	{
		$userId = 'testuser';
		// Pin to mid-week so the range always includes working days regardless of "today".
		$start = new \DateTime('next monday');
		$start->modify('+14 days');
		$end = (clone $start)->modify('+1 day');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'reason' => 'Vacation',
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$absence = new Absence();
		$absence->setId(124);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(2.0);
		$this->absenceMapper->method('insert')->willReturn($absence);

		$mailService = $this->createMock(AbsenceNotificationMailService::class);
		$mailService->expects($this->once())
			->method('sendHrOfficeNotification')
			->with($absence, 'request_created', $userId);

		$service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$this->lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			$mailService
		);

		$service->createAbsence($data, $userId);
	}

	/**
	 * Test creating absence with empty/whitespace date string – parseDate rejects
	 */
	public function testCreateAbsenceEmptyDateString(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => '   ',
			'end_date' => (new \DateTime())->modify('+7 days')->format('Y-m-d'),
			'reason' => 'Test'
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Date is required and cannot be empty');

		$this->service->createAbsence($data, $userId);
	}

	public function testCreateAbsenceRejectsRelativeDateString(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => 'next monday',
			'end_date' => (new \DateTime())->modify('+7 days')->format('Y-m-d'),
			'reason' => 'Test',
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Invalid date format. Expected yyyy-mm-dd or dd.mm.yyyy');

		$this->service->createAbsence($data, $userId);
	}

	public function testCreateAbsenceRejectsIsoDateTimeString(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-06-01T12:34:56Z',
			'end_date' => (new \DateTime())->modify('+7 days')->format('Y-m-d'),
			'reason' => 'Test',
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Invalid date format. Expected yyyy-mm-dd or dd.mm.yyyy');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test vacation with 0 working days (e.g. weekend-only) – must be rejected
	 */
	public function testCreateAbsenceVacationZeroWorkingDays(): void
	{
		$userId = 'testuser';
		$futureSat = (new \DateTime())->modify('next Saturday');
		$futureSun = (clone $futureSat)->modify('+1 day');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $futureSat->format('Y-m-d'),
			'end_date' => $futureSun->format('Y-m-d'),
			'reason' => 'Weekend only'
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$year = (int)$futureSat->format('Y');
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([$year => 0.0]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(0.0);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Vacation must include at least one working day');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Hours mode + weekend + posted duration_hours must still be rejected (no invented debit).
	 */
	public function testCreateAbsenceVacationHoursWeekendWithDurationRejected(): void
	{
		$userId = 'testuser';
		$futureSat = (new \DateTime())->modify('next Saturday');
		$futureSun = (clone $futureSat)->modify('+1 day');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $futureSat->format('Y-m-d'),
			'end_date' => $futureSun->format('Y-m-d'),
			'reason' => 'Weekend hours attempt',
			'duration_hours' => '8',
			'require_duration_hours' => true,
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$year = (int)$futureSat->format('Y');
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([$year => 0.0]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(0.0);

		$unitConfig = $this->createMock(IConfig::class);
		$unitConfig->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_HOURS;
				}
				if ($key === Constants::CONFIG_VACATION_HOURS_PER_DAY) {
					return '8';
				}
				return $default;
			}
		);
		$unit = new \OCA\ArbeitszeitCheck\Service\VacationUnitService($unitConfig);
		$debit = $this->createMock(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class);
		$debit->method('estimateForUserRange')->willReturn([
			'hours' => 0.0,
			'basis' => 'org_hours_per_day',
			'average_daily' => 8.0,
			'one_day_hours' => 8.0,
			'weekday_nets' => null,
		]);

		$ref = new \ReflectionClass($this->service);
		$unitProp = $ref->getProperty('vacationUnitService');
		$unitProp->setAccessible(true);
		$unitProp->setValue($this->service, $unit);
		$debitProp = $ref->getProperty('vacationHoursDebitService');
		$debitProp->setAccessible(true);
		$debitProp->setValue($this->service, $debit);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Vacation must include at least one working day');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test vacation when computeWorkingDaysPerYear returns empty – fallback to HolidayService
	 */
	public function testCreateAbsenceVacationFallbackWhenEmpty(): void
	{
		$userId = 'testuser';
		$futureStart = (new \DateTime())->modify('+14 days');
		$futureEnd = (clone $futureStart)->modify('+2 days');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $futureStart->format('Y-m-d'),
			'end_date' => $futureEnd->format('Y-m-d'),
			'reason' => 'Fallback test'
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')->willReturn([]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(2.0);

		$absence = new Absence();
		$absence->setId(125);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $futureStart);
		$absence->setEndDate(clone $futureEnd);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(2.0);
		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($absence);
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$result = $this->service->createAbsence($data, $userId);
		$this->assertSame($absence, $result);
	}

	/**
	 * Test creating absence with invalid date range (start after end)
	 */
	public function testCreateAbsenceInvalidDateRange(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2024-06-05',
			'end_date' => '2024-06-01', // End before start
			'reason' => 'Invalid range'
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Start date cannot be after end date');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating vacation with a past start date (migration/backfill records)
	 */
	public function testCreateAbsenceAllowsPastVacationDate(): void
	{
		$userId = 'testuser';
		$yesterday = (new \DateTime())->modify('-1 day')->format('Y-m-d');
		$tomorrow = (new \DateTime())->modify('+1 day')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $yesterday,
			'end_date' => $tomorrow,
			'reason' => 'Past date'
		];

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$absence = new Absence();
		$absence->setId(1234);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime($yesterday));
		$absence->setEndDate(new \DateTime($tomorrow));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(2.0);

		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($absence);
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$result = $this->service->createAbsence($data, $userId);
		$this->assertSame($absence, $result);
	}

	/**
	 * Test sick leave with start date within allowed past window (7 days) – should succeed
	 */
	public function testCreateAbsenceSickLeaveWithinPastWindow(): void
	{
		$userId = 'testuser';
		$threeDaysAgo = (new \DateTime())->modify('-3 days')->format('Y-m-d');
		$tomorrow = (new \DateTime())->modify('+1 day')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $threeDaysAgo,
			'end_date' => $tomorrow,
			'reason' => 'Sick'
		];

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$absence = new Absence();
		$absence->setId(124);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStartDate(new \DateTime($threeDaysAgo));
		$absence->setEndDate(new \DateTime($tomorrow));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(3.0);
		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($absence);
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$result = $this->service->createAbsence($data, $userId);
		$this->assertSame($absence, $result);
	}

	/**
	 * Test sick leave with start date more than 7 days in past – should succeed for backfill/migration.
	 */
	public function testCreateAbsenceSickLeaveAllowsOlderPastStartDate(): void
	{
		$userId = 'testuser';
		$tenDaysAgo = (new \DateTime())->modify('-10 days')->format('Y-m-d');
		$tomorrow = (new \DateTime())->modify('+1 day')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $tenDaysAgo,
			'end_date' => $tomorrow,
			'reason' => 'Sick'
		];

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$absence = new Absence();
		$absence->setId(125);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStartDate(new \DateTime($tenDaysAgo));
		$absence->setEndDate(new \DateTime($tomorrow));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(8.0);
		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($absence);
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$result = $this->service->createAbsence($data, $userId);
		$this->assertSame($absence, $result);
	}

	/**
	 * Test creating absence with overlapping dates
	 */
	public function testCreateAbsenceOverlapping(): void
	{
		$userId = 'testuser';
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+4 days');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'reason' => 'Overlapping'
		];

		$existingAbsence = new Absence();
		$existingAbsence->setType(Absence::TYPE_VACATION);
		$existingAbsence->setStartDate($start);
		$existingAbsence->setEndDate($end);

		$this->absenceMapper->expects($this->once())
			->method('findOverlapping')
			->with($userId, $this->isInstanceOf(\DateTime::class), $this->isInstanceOf(\DateTime::class), $this->anything())
			->willReturn([$existingAbsence]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('This period overlaps with an existing');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating vacation absence exceeding 30 days
	 */
	public function testCreateAbsenceVacationExceedsLimit(): void
	{
		$userId = 'testuser';
		$futureStart = (new \DateTime())->modify('+14 days')->format('Y-m-d');
		$futureEnd = (new \DateTime())->modify('+49 days')->format('Y-m-d'); // 35 days span
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $futureStart,
			'end_date' => $futureEnd,
			'reason' => 'Too long'
		];

		$this->absenceMapper->expects($this->once())
			->method('findOverlapping')
			->willReturn([]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Vacation cannot exceed 30 days');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating vacation absence when entitlement exceeded
	 */
	public function testCreateAbsenceVacationEntitlementExceeded(): void
	{
		$userId = 'testuser';
		$futureStart = (new \DateTime())->modify('+14 days')->format('Y-m-d');
		$futureEnd = (new \DateTime())->modify('+18 days')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $futureStart,
			'end_date' => $futureEnd,
			'reason' => 'No days left'
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')
			->with($userId, 'vacation_days_per_year', 25)->willReturn(25);
		$year = (int)(new \DateTime($futureStart))->format('Y');
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([$year => 4.0]);

		$this->vacationAllocationFailProspective = true;

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Not enough vacation remaining');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating personal leave exceeding 5 days
	 */
	public function testCreateAbsencePersonalLeaveExceedsLimit(): void
	{
		$userId = 'testuser';
		$futureStart = (new \DateTime())->modify('+14 days')->format('Y-m-d');
		$futureEnd = (new \DateTime())->modify('+24 days')->format('Y-m-d'); // 10 days span
		$data = [
			'type' => Absence::TYPE_PERSONAL_LEAVE,
			'start_date' => $futureStart,
			'end_date' => $futureEnd,
			'reason' => 'Too long'
		];

		$this->absenceMapper->expects($this->once())
			->method('findOverlapping')
			->willReturn([]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Personal leave cannot exceed 5 days');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test getting absence by ID (user owns it)
	 */
	public function testGetAbsenceOwnedByUser(): void
	{
		$userId = 'testuser';
		$absenceId = 123;

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$result = $this->service->getAbsence($absenceId, $userId);

		$this->assertSame($absence, $result);
	}

	/**
	 * Test getting absence by ID (user does not own it)
	 */
	public function testGetAbsenceNotOwnedByUser(): void
	{
		$userId = 'testuser';
		$otherUserId = 'otheruser';
		$absenceId = 123;

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($otherUserId);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$result = $this->service->getAbsence($absenceId, $userId);

		$this->assertNull($result, 'User should not access absences they do not own');
	}

	/**
	 * Test getting non-existent absence
	 */
	public function testGetAbsenceNotFound(): void
	{
		$userId = 'testuser';
		$absenceId = 999;

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willThrowException(new DoesNotExistException('Absence not found'));

		$result = $this->service->getAbsence($absenceId, $userId);

		$this->assertNull($result);
	}

	/**
	 * Test updating a pending absence
	 */
	public function testUpdateAbsencePending(): void
	{
		$userId = 'testuser';
		$absenceId = 123;
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+4 days');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);
		$absence->setReason('Original reason');
		$absence->setDays(5.0);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->willReturn([]);

		$newStart = (clone $start)->modify('+1 day');
		$newEnd = (clone $end)->modify('+1 day');
		$year = (int)$newStart->format('Y');
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([$year => 5.0]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$this->absenceMapper->expects($this->once())
			->method('lockUserAbsenceWindow');

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($this->isInstanceOf(Absence::class))
			->willReturnCallback(function (Absence $a) {
				return $a;
			});

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_updated', 'absence', $absenceId, $this->isType('array'), $this->isType('array'));

		$updateData = [
			'start_date' => $newStart->format('Y-m-d'),
			'end_date' => $newEnd->format('Y-m-d'),
		];

		$result = $this->service->updateAbsence($absenceId, $updateData, $userId);

		$this->assertInstanceOf(Absence::class, $result);
	}

	public function testUpdateAbsenceWithNewSubstituteTransitionsToSubstitutePending(): void
	{
		$userId = 'testuser';
		$absenceId = 223;
		$start = (new \DateTime())->modify('+10 days');
		$end = (clone $start)->modify('+2 days');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);
		$absence->setDays(3.0);
		$absence->setSubstituteUserId(null);

		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([(int)$start->format('Y') => 3.0]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(3.0);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->auditLogMapper->expects($this->once())->method('logAction');
		$this->notificationService->expects($this->once())->method('notifySubstitutionRequest');

		$result = $this->service->updateAbsence($absenceId, ['substitute_user_id' => 'colleague1'], $userId);
		$this->assertSame(Absence::STATUS_SUBSTITUTE_PENDING, $result->getStatus());
	}

	public function testUpdateAbsenceRemovingSubstituteTransitionsToPending(): void
	{
		$userId = 'testuser';
		$absenceId = 224;
		$start = (new \DateTime())->modify('+10 days');
		$end = (clone $start)->modify('+2 days');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);
		$absence->setDays(3.0);
		$absence->setSubstituteUserId('colleague1');

		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([(int)$start->format('Y') => 3.0]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(3.0);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->auditLogMapper->expects($this->once())->method('logAction');
		$this->notificationService->expects($this->never())->method('notifySubstitutionRequest');

		$result = $this->service->updateAbsence($absenceId, ['substitute_user_id' => null], $userId);
		$this->assertSame(Absence::STATUS_PENDING, $result->getStatus());
	}

	/**
	 * Test updating a non-pending absence (should fail)
	 */
	public function testUpdateAbsenceNotPending(): void
	{
		$userId = 'testuser';
		$absenceId = 123;
		$futureDate = (new \DateTime())->modify('+14 days')->format('Y-m-d');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_APPROVED);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Only pending absences can be updated');

		$this->service->updateAbsence($absenceId, ['start_date' => $futureDate], $userId);
	}

	/**
	 * Test deleting a pending absence
	 */
	public function testDeleteAbsencePending(): void
	{
		$userId = 'testuser';
		$absenceId = 123;

		$start = (new \DateTime())->modify('+7 days');
		$end = (clone $start)->modify('+2 days');
		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setStartDate($start);
		$absence->setEndDate($end);
		$absence->setDays(2.0);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->absenceMapper->expects($this->once())
			->method('delete')
			->with($absence);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_deleted', 'absence', $absenceId, $this->isType('array'), null);

		$this->service->deleteAbsence($absenceId, $userId);
	}

	/**
	 * Test deleting a non-pending absence (should fail)
	 */
	public function testDeleteAbsenceNotPending(): void
	{
		$userId = 'testuser';
		$absenceId = 123;

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_APPROVED);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Only pending absences can be deleted');

		$this->service->deleteAbsence($absenceId, $userId);
	}

	/**
	 * Test approving an absence request
	 */
	public function testApproveAbsence(): void
	{
		$userId = 'employee';
		$approverId = 'manager';
		$absenceId = 123;
		$comment = 'Approved for summer vacation';
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+4 days');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate($start);
		$absence->setEndDate($end);
		$absence->setDays(5.0);

		$this->absenceMapper->expects($this->exactly(3))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($this->isInstanceOf(Absence::class))
			->willReturnCallback(function (Absence $a) {
				return $a;
			});

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_approved', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $approverId);

		$this->notificationService->expects($this->once())
			->method('notifyAbsenceApproved')
			->with($userId, $this->isType('array'));

		$result = $this->service->approveAbsence($absenceId, $approverId, $comment);

		$this->assertInstanceOf(Absence::class, $result);
		$this->assertSame(Absence::STATUS_APPROVED, $result->getStatus());
	}

	/**
	 * Test approving a non-pending absence (should fail)
	 */
	public function testApproveAbsenceNotPending(): void
	{
		$approverId = 'manager';
		$absenceId = 123;

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId('employee');
		$absence->setStatus(Absence::STATUS_APPROVED);

		$this->absenceMapper->expects($this->exactly(3))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException::class);
		$this->expectExceptionMessage('This absence was already decided by another manager.');

		$this->service->approveAbsence($absenceId, $approverId);
	}

	public function testApproveAbsenceMapsLockedExceptionToConcurrentDecision(): void
	{
		$approverId = 'manager';
		$absenceId = 124;

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId('employee');
		$absence->setStatus(Absence::STATUS_PENDING);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$lockingProvider = $this->createMock(ILockingProvider::class);
		$lockingProvider->method('acquireLock')
			->willThrowException(new \OCP\Lock\LockedException('azc/ab/test'));
		$this->service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService
		);

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException::class);
		$this->expectExceptionMessage('This absence was already decided by another manager.');

		$this->service->approveAbsence($absenceId, $approverId);
	}

	public function testApproveAbsenceBlockedWhenMonthFinalized(): void
	{
		$approverId = 'manager';
		$absenceId = 321;
		$start = new \DateTime('2026-04-01');
		$end = new \DateTime('2026-04-03');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId('employee');
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);

		$this->absenceMapper->expects($this->exactly(3))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->monthClosureService->expects($this->once())
			->method('assertDateRangeMutable')
			->with(
				'employee',
				$this->isInstanceOf(\DateTimeInterface::class),
				$this->isInstanceOf(\DateTimeInterface::class)
			)
			->willThrowException(new \Exception('Month is finalized'));

		$this->absenceMapper->expects($this->never())->method('update');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Month is finalized');

		$this->service->approveAbsence($absenceId, $approverId);
	}

	/**
	 * Test rejecting an absence request
	 */
	public function testRejectAbsence(): void
	{
		$userId = 'employee';
		$approverId = 'manager';
		$absenceId = 123;
		$comment = 'Not enough vacation days remaining';
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+4 days');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate($start);
		$absence->setEndDate($end);
		$absence->setDays(5.0);

		$this->absenceMapper->expects($this->exactly(3))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($this->isInstanceOf(Absence::class))
			->willReturnCallback(function (Absence $a) {
				return $a;
			});

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_rejected', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $approverId);

		$this->notificationService->expects($this->once())
			->method('notifyAbsenceRejected')
			->with($userId, $this->isType('array'), $comment);

		$result = $this->service->rejectAbsence($absenceId, $approverId, $comment);

		$this->assertInstanceOf(Absence::class, $result);
		$this->assertSame(Absence::STATUS_REJECTED, $result->getStatus());
	}

	/**
	 * Test getting vacation statistics
	 */
	public function testGetVacationStats(): void
	{
		$userId = 'testuser';
		$year = 2024;

		$this->absenceMapper->expects($this->once())
			->method('getSickLeaveDays')
			->with($userId, $year)
			->willReturn(3.0);

		$this->vacationAllocationStub = [
			'entitlement' => 30.0,
			'carryover_opening' => 0.0,
			'carryover_usable_for_new_requests' => 0.0,
			'carryover_expires_on' => null,
			'total_remaining_for_new_requests' => 15.0,
			'used_total_working_days' => 15.0,
			'allocation_valid' => true,
			'shortfall' => 0.0,
		];

		$stats = $this->service->getVacationStats($userId, $year);

		$this->assertIsArray($stats);
		$this->assertEquals($year, $stats['year']);
		$this->assertEquals(30, $stats['entitlement']);
		$this->assertEquals(0.0, $stats['carryover_days']);
		$this->assertEquals(30.0, $stats['total_available']);
		$this->assertEquals(15, $stats['used']);
		$this->assertEquals(15, $stats['remaining']); // 30 - 15 = 15
		$this->assertEquals(3, $stats['sick_days']);
	}

	/**
	 * Test getting vacation stats with default entitlement
	 */
	public function testGetVacationStatsDefaultEntitlement(): void
	{
		$userId = 'testuser';
		$year = 2024;

		$this->absenceMapper->expects($this->once())
			->method('getSickLeaveDays')
			->willReturn(0.0);

		$this->vacationAllocationStub = [
			'entitlement' => 25.0,
			'carryover_opening' => 0.0,
			'carryover_usable_for_new_requests' => 0.0,
			'carryover_expires_on' => null,
			'total_remaining_for_new_requests' => 15.0,
			'used_total_working_days' => 10.0,
			'allocation_valid' => true,
			'shortfall' => 0.0,
		];

		$stats = $this->service->getVacationStats($userId, $year);

		$this->assertEquals(25, $stats['entitlement']);
		$this->assertEquals(0.0, $stats['carryover_days']);
		$this->assertEquals(25.0, $stats['total_available']);
		$this->assertEquals(10, $stats['used']);
		$this->assertEquals(15, $stats['remaining']); // 25 - 10 = 15
	}

	/**
	 * Test getting absences by user with filters
	 */
	public function testGetAbsencesByUserWithStatusFilter(): void
	{
		$userId = 'testuser';

		$pendingAbsence = new Absence();
		$pendingAbsence->setStatus(Absence::STATUS_PENDING);

		$approvedAbsence = new Absence();
		$approvedAbsence->setStatus(Absence::STATUS_APPROVED);

		$this->absenceMapper->expects($this->once())
			->method('findByUser')
			->with($userId, null, null)
			->willReturn([$pendingAbsence, $approvedAbsence]);

		$filters = ['status' => Absence::STATUS_PENDING];
		$result = $this->service->getAbsencesByUser($userId, $filters);

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertSame($pendingAbsence, $result[0]);
	}

	/**
	 * Test substitute approves absence (Vertretungs-Freigabe)
	 */
	public function testApproveBySubstitute(): void
	{
		$absenceId = 123;
		$substituteUserId = 'substitute1';
		$employeeUserId = 'employee1';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId', 'getId', 'getType', 'getStartDate', 'getEndDate', 'getDays', 'setStatus', 'setApproverComment'])
			->onlyMethods(['getSummary'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->method('getSubstituteUserId')->willReturn($substituteUserId);
		$absence->method('getUserId')->willReturn($employeeUserId);
		$absence->method('getId')->willReturn($absenceId);
		$absence->method('getType')->willReturn(Absence::TYPE_VACATION);
		$absence->method('getStartDate')->willReturn(new \DateTime('2024-06-01'));
		$absence->method('getEndDate')->willReturn(new \DateTime('2024-06-05'));
		$absence->method('getDays')->willReturn(5);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$absence->expects($this->once())
			->method('setStatus')
			->with(Absence::STATUS_PENDING);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($absence)
			->willReturn($absence);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($employeeUserId, 'absence_substitute_approved', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $substituteUserId);

		$this->notificationService->expects($this->once())
			->method('notifySubstituteApproved')
			->with($employeeUserId, $substituteUserId, $this->isType('array'));

		$result = $this->service->approveBySubstitute($absenceId, $substituteUserId);

		$this->assertSame($absence, $result);
	}

	/**
	 * Test approveBySubstitute rejects when wrong substitute
	 */
	public function testApproveBySubstituteRejectsWrongSubstitute(): void
	{
		$absenceId = 123;
		$wrongSubstituteId = 'wrong_substitute';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->method('getSubstituteUserId')->willReturn('designated_substitute');
		$absence->method('getUserId')->willReturn('employee1');

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->expectExceptionMessage('Absence not found');

		$this->service->approveBySubstitute($absenceId, $wrongSubstituteId);
	}

	/**
	 * Test approveBySubstitute rejects when status is not substitute_pending
	 */
	public function testApproveBySubstituteRejectsWrongStatus(): void
	{
		$absenceId = 123;
		$substituteUserId = 'substitute1';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_PENDING);
		$absence->method('getSubstituteUserId')->willReturn($substituteUserId);
		$absence->method('getUserId')->willReturn('employee1');

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Absence is not awaiting substitute approval');

		$this->service->approveBySubstitute($absenceId, $substituteUserId);
	}

	public function testApproveBySubstituteBlockedWhenMonthFinalized(): void
	{
		$absenceId = 456;
		$substituteUserId = 'substitute1';

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId('employee1');
		$absence->setStatus(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->setSubstituteUserId($substituteUserId);
		$absence->setStartDate(new \DateTime('2026-04-05'));
		$absence->setEndDate(new \DateTime('2026-04-06'));

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->monthClosureService->expects($this->once())
			->method('assertDateRangeMutable')
			->with(
				'employee1',
				$this->isInstanceOf(\DateTimeInterface::class),
				$this->isInstanceOf(\DateTimeInterface::class)
			)
			->willThrowException(new \Exception('Month is finalized'));

		$this->absenceMapper->expects($this->never())->method('update');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Month is finalized');

		$this->service->approveBySubstitute($absenceId, $substituteUserId);
	}

	/**
	 * Test substitute declines absence
	 */
	public function testDeclineBySubstitute(): void
	{
		$absenceId = 123;
		$substituteUserId = 'substitute1';
		$employeeUserId = 'employee1';
		$comment = 'I cannot cover these dates';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId', 'getId', 'getType', 'getStartDate', 'getEndDate', 'getDays', 'setStatus', 'setApproverComment'])
			->onlyMethods(['getSummary'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->method('getSubstituteUserId')->willReturn($substituteUserId);
		$absence->method('getUserId')->willReturn($employeeUserId);
		$absence->method('getId')->willReturn($absenceId);
		$absence->method('getType')->willReturn(Absence::TYPE_VACATION);
		$absence->method('getStartDate')->willReturn(new \DateTime('2024-06-01'));
		$absence->method('getEndDate')->willReturn(new \DateTime('2024-06-05'));
		$absence->method('getDays')->willReturn(5);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$absence->expects($this->once())
			->method('setStatus')
			->with(Absence::STATUS_SUBSTITUTE_DECLINED);

		$absence->expects($this->once())
			->method('setApproverComment')
			->with($comment);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($absence)
			->willReturn($absence);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($employeeUserId, 'absence_substitute_declined', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $substituteUserId);

		$this->notificationService->expects($this->once())
			->method('notifySubstituteDeclined')
			->with($employeeUserId, $substituteUserId, $this->isType('array'));

		$result = $this->service->declineBySubstitute($absenceId, $substituteUserId, $comment);

		$this->assertSame($absence, $result);
	}

	/**
	 * Test declineBySubstitute rejects when wrong substitute
	 */
	public function testDeclineBySubstituteRejectsWrongSubstitute(): void
	{
		$absenceId = 123;
		$wrongSubstituteId = 'wrong_substitute';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->method('getSubstituteUserId')->willReturn('designated_substitute');
		$absence->method('getUserId')->willReturn('employee1');

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->expectExceptionMessage('Absence not found');

		$this->service->declineBySubstitute($absenceId, $wrongSubstituteId, 'comment');
	}

	/**
	 * BOLA oracle regression (Atlas web_api 2026-09-23): the designated-substitute
	 * check must run BEFORE the substitute-pending status check, and a
	 * non-designated caller gets the same "Absence not found" as a missing id —
	 * existence itself must not leak.
	 */
	public function testApproveBySubstituteDeniesBeforeStatusCheck(): void
	{
		$absenceId = 123;
		$wrongSubstituteId = 'wrong_substitute';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId'])
			->getMock();
		// Already decided — must still report "Absence not found",
		// never reveal existence or real status to a non-designated caller.
		$absence->method('getStatus')->willReturn(Absence::STATUS_APPROVED);
		$absence->method('getSubstituteUserId')->willReturn('designated_substitute');
		$absence->method('getUserId')->willReturn('employee1');

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->expectExceptionMessage('Absence not found');

		$this->service->approveBySubstitute($absenceId, $wrongSubstituteId);
	}

	public function testDeclineBySubstituteDeniesBeforeStatusCheck(): void
	{
		$absenceId = 123;
		$wrongSubstituteId = 'wrong_substitute';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_CANCELLED);
		$absence->method('getSubstituteUserId')->willReturn('designated_substitute');
		$absence->method('getUserId')->willReturn('employee1');

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->expectExceptionMessage('Absence not found');

		$this->service->declineBySubstitute($absenceId, $wrongSubstituteId, 'comment');
	}

	/**
	 * When no assignable manager exists, requests without substitute are auto-approved at creation.
	 */
	public function testCreateAbsenceAutoApprovesWhenNoAssignableManager(): void
	{
		$this->hasAssignableManagerForTests = false;

		$userId = 'solo';
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+2 days');
		$data = [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'reason' => 'Test',
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(3.0);

		$inserted = new Absence();
		$inserted->setId(500);
		$inserted->setUserId($userId);
		$inserted->setType(Absence::TYPE_SICK_LEAVE);
		$inserted->setStartDate(clone $start);
		$inserted->setEndDate(clone $end);
		$inserted->setStatus(Absence::STATUS_PENDING);
		$inserted->setDays(3.0);
		$inserted->setCreatedAt(new \DateTime());
		$inserted->setUpdatedAt(new \DateTime());

		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($inserted);
		$this->absenceMapper->expects($this->once())->method('update')
			->willReturnCallback(function ($a) {
				$this->assertSame(Absence::STATUS_APPROVED, $a->getStatus());
				return $a;
			});
		$this->auditLogMapper->expects($this->exactly(2))->method('logAction');
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('rollBack');

		$this->notificationService->expects($this->once())
			->method('notifyAbsenceApproved')
			->with(
				$userId,
				$this->callback(static function (array $payload): bool {
					return ($payload['days'] ?? null) === 3.0
						&& ($payload['type'] ?? '') === Absence::TYPE_SICK_LEAVE;
				})
			);

		$result = $this->service->createAbsence($data, $userId);
		$this->assertSame(Absence::STATUS_APPROVED, $result->getStatus());
	}

	public function testCreateApprovedAbsenceForEmployeeByManager(): void
	{
		$managerId = 'mgr1';
		$targetId = 'emp1';
		$start = (new \DateTime())->modify('-40 days');
		$end = (clone $start)->modify('+2 days');
		$data = [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'reason' => 'HR migration',
		];

		$this->absenceMapper->expects($this->exactly(2))->method('findOverlapping')->willReturn([]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(3.0);

		$inserted = new Absence();
		$inserted->setId(9001);
		$inserted->setUserId($targetId);
		$inserted->setType(Absence::TYPE_SICK_LEAVE);
		$inserted->setStartDate(clone $start);
		$inserted->setEndDate(clone $end);
		$inserted->setStatus(Absence::STATUS_APPROVED);
		$inserted->setDays(3.0);
		$inserted->setCreatedAt(new \DateTime());

		$this->absenceMapper->expects($this->once())->method('lockUserAbsenceWindow');
		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($inserted);
		$this->auditLogMapper->expects($this->once())->method('logAction')->with(
			$targetId,
			'absence_manager_recorded',
			'absence',
			9001,
			null,
			$this->isType('array'),
			$managerId
		);
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('rollBack');

		$this->notificationService->expects($this->once())->method('notifyAbsenceApproved');

		$result = $this->service->createApprovedAbsenceForEmployeeByManager($managerId, $targetId, $data);
		$this->assertSame(Absence::STATUS_APPROVED, $result->getStatus());
		$this->assertSame($targetId, $result->getUserId());
	}

	public function testCreateApprovedAbsenceForEmployeeByManagerRejectsSelf(): void
	{
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('You cannot record an absence for yourself with this action.');

		$this->service->createApprovedAbsenceForEmployeeByManager('u1', 'u1', [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => '2024-01-02',
			'end_date' => '2024-01-03',
		]);
	}

	/**
	 * Historical entries (end date strictly before today) must never enter the
	 * SUBSTITUTE_PENDING workflow even if the caller submits a substitute_user_id.
	 * Vertretung is a forward-looking workflow and is meaningless once the period
	 * has elapsed. The substitute reference is silently dropped and the status
	 * routes directly into PENDING (or AUTO_APPROVED downstream, depending on the
	 * team configuration). This guards against API consumers (mobile app, scripts)
	 * accidentally putting historical migration records into a pending-substitute
	 * limbo that no one can resolve.
	 */
	public function testCreateAbsencePastDateDropsSubstituteAndStaysPending(): void
	{
		$userId = 'testuser';
		$tenDaysAgo = (new \DateTime())->modify('-10 days')->format('Y-m-d');
		$fiveDaysAgo = (new \DateTime())->modify('-5 days')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $tenDaysAgo,
			'end_date' => $fiveDaysAgo,
			'reason' => 'Migrated vacation',
			'substitute_user_id' => 'colleague1',
		];

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$persisted = null;
		$this->absenceMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (Absence $absence) use (&$persisted) {
				$absence->setId(9001);
				$persisted = $absence;
				return $absence;
			});
		$this->auditLogMapper->expects($this->once())->method('logAction');
		/* The substitute notification path must never fire for historical entries.
		 * If it did, the substitute would receive a misleading "please approve a
		 * shift that already happened" notification. */
		$this->notificationService->expects($this->never())->method('notifySubstitutionRequest');

		$result = $this->service->createAbsence($data, $userId);

		$this->assertNotNull($result);
		$this->assertInstanceOf(Absence::class, $persisted);
		/** @var Absence $persisted */
		$this->assertNull($persisted->getSubstituteUserId(),
			'Substitute reference must be cleared for historical entries.');
		$this->assertNotSame(Absence::STATUS_SUBSTITUTE_PENDING, $persisted->getStatus(),
			'Historical entries must never enter the substitute-pending workflow.');
	}

	/**
	 * Sanity check that a future absence with a substitute still routes through
	 * the substitute approval workflow. Regression guard for the past-aware
	 * branch above so we do not accidentally short-circuit live requests.
	 */
	public function testCreateAbsenceFutureDateWithSubstituteStillRoutesToSubstitutePending(): void
	{
		$userId = 'testuser';
		$start = (new \DateTime())->modify('+14 days')->format('Y-m-d');
		$end = (new \DateTime())->modify('+18 days')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $start,
			'end_date' => $end,
			'reason' => 'Future trip',
			'substitute_user_id' => 'colleague1',
		];

		$this->absenceMapper->expects($this->exactly(2))->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$persisted = null;
		$this->absenceMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (Absence $absence) use (&$persisted) {
				$absence->setId(9002);
				$persisted = $absence;
				return $absence;
			});
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$this->service->createAbsence($data, $userId);

		$this->assertInstanceOf(Absence::class, $persisted);
		/** @var Absence $persisted */
		$this->assertSame('colleague1', $persisted->getSubstituteUserId());
		$this->assertSame(Absence::STATUS_SUBSTITUTE_PENDING, $persisted->getStatus());
	}

	/**
	 * The absence summary exposes server-computed temporal flags so the frontend
	 * does not have to re-derive "today" in JS where iPadOS / Chrome have been
	 * observed to drift up to a calendar day due to DST and timezone offsets.
	 * Exercising the entity directly keeps this fast and free of test doubles.
	 */
	public function testAbsenceSummaryExposesPastCurrentFutureFlags(): void
	{
		$yesterday = (new \DateTime())->modify('-1 day');
		$twoDaysAgo = (new \DateTime())->modify('-2 days');
		$tomorrow = (new \DateTime())->modify('+1 day');
		$inTwoDays = (new \DateTime())->modify('+2 days');
		$today = new \DateTime('today');

		$past = new Absence();
		$past->setUserId('u1');
		$past->setType(Absence::TYPE_VACATION);
		$past->setStartDate($twoDaysAgo);
		$past->setEndDate($yesterday);
		$past->setStatus(Absence::STATUS_APPROVED);
		$past->setDays(2.0);

		$current = new Absence();
		$current->setUserId('u1');
		$current->setType(Absence::TYPE_VACATION);
		$current->setStartDate($yesterday);
		$current->setEndDate($tomorrow);
		$current->setStatus(Absence::STATUS_APPROVED);
		$current->setDays(3.0);

		$future = new Absence();
		$future->setUserId('u1');
		$future->setType(Absence::TYPE_VACATION);
		$future->setStartDate($tomorrow);
		$future->setEndDate($inTwoDays);
		$future->setStatus(Absence::STATUS_PENDING);
		$future->setDays(2.0);

		$pastSummary = $past->getSummary();
		$currentSummary = $current->getSummary();
		$futureSummary = $future->getSummary();

		$this->assertTrue($pastSummary['isPast']);
		$this->assertFalse($pastSummary['isCurrent']);
		$this->assertFalse($pastSummary['isFuture']);

		$this->assertFalse($currentSummary['isPast']);
		$this->assertTrue($currentSummary['isCurrent']);
		$this->assertFalse($currentSummary['isFuture']);

		$this->assertFalse($futureSummary['isPast']);
		$this->assertFalse($futureSummary['isCurrent']);
		$this->assertTrue($futureSummary['isFuture']);

		// Sanity check on unused `today` reference to silence static analysis
		// while keeping the assertion intent explicit.
		$this->assertInstanceOf(\DateTime::class, $today);
	}

	/**
	 * `isManagerRecorded` is the server-side heuristic the manager-record API
	 * uses (and emits as the `absence_manager_recorded` audit-log action). It
	 * encodes "approved by a human approver other than the absence owner and
	 * other than the system." Clients can rely on this flag to render a clear
	 * "Recorded by manager" badge without re-implementing the heuristic in JS.
	 */
	public function testAbsenceSummaryDetectsManagerRecorded(): void
	{
		$start = (new \DateTime())->modify('-5 days');
		$end = (new \DateTime())->modify('-1 day');

		$managerRecorded = new Absence();
		$managerRecorded->setUserId('emp1');
		$managerRecorded->setType(Absence::TYPE_VACATION);
		$managerRecorded->setStartDate($start);
		$managerRecorded->setEndDate($end);
		$managerRecorded->setStatus(Absence::STATUS_APPROVED);
		$managerRecorded->setApprovedByUserId('manager1');
		$managerRecorded->setDays(4.0);

		$autoApprovedBySystem = new Absence();
		$autoApprovedBySystem->setUserId('emp2');
		$autoApprovedBySystem->setType(Absence::TYPE_VACATION);
		$autoApprovedBySystem->setStartDate($start);
		$autoApprovedBySystem->setEndDate($end);
		$autoApprovedBySystem->setStatus(Absence::STATUS_APPROVED);
		$autoApprovedBySystem->setApprovedByUserId('system');
		$autoApprovedBySystem->setDays(4.0);

		$selfApproved = new Absence();
		$selfApproved->setUserId('emp3');
		$selfApproved->setType(Absence::TYPE_VACATION);
		$selfApproved->setStartDate($start);
		$selfApproved->setEndDate($end);
		$selfApproved->setStatus(Absence::STATUS_APPROVED);
		$selfApproved->setApprovedByUserId('emp3');
		$selfApproved->setDays(4.0);

		$pendingNoApprover = new Absence();
		$pendingNoApprover->setUserId('emp4');
		$pendingNoApprover->setType(Absence::TYPE_VACATION);
		$pendingNoApprover->setStartDate($start);
		$pendingNoApprover->setEndDate($end);
		$pendingNoApprover->setStatus(Absence::STATUS_PENDING);
		$pendingNoApprover->setDays(4.0);

		$this->assertTrue($managerRecorded->getSummary()['isManagerRecorded']);
		$this->assertFalse($autoApprovedBySystem->getSummary()['isManagerRecorded']);
		$this->assertFalse($selfApproved->getSummary()['isManagerRecorded']);
		$this->assertFalse($pendingNoApprover->getSummary()['isManagerRecorded']);
	}

	private function makeAbsenceRow(int $id, string $userId, string $status, string $type, string $start, string $end): Absence
	{
		$a = new Absence();
		$a->setId($id);
		$a->setUserId($userId);
		$a->setType($type);
		$a->setStatus($status);
		$a->setStartDate(new \DateTime($start));
		$a->setEndDate(new \DateTime($end));
		$a->setDays(2.0);
		$a->setCreatedAt(new \DateTime('-30 days'));
		$a->setUpdatedAt(new \DateTime('-30 days'));
		return $a;
	}

	public function testCancelAbsenceCancelsFuturePending(): void
	{
		$start = (new \DateTime('+10 days'))->format('Y-m-d');
		$end = (new \DateTime('+12 days'))->format('Y-m-d');
		$absence = $this->makeAbsenceRow(11, 'alice', Absence::STATUS_PENDING, Absence::TYPE_PERSONAL_LEAVE, $start, $end);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->expects($this->once())->method('update')
			->willReturnCallback(static fn (Absence $a) => $a);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'absence_cancelled', 'absence', 11);

		$out = $this->service->cancelAbsence(11, 'alice');
		$this->assertSame(Absence::STATUS_CANCELLED, $out->getStatus());
	}

	public function testCancelAbsenceRejectsAlreadyStarted(): void
	{
		$absence = $this->makeAbsenceRow(11, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_PERSONAL_LEAVE, '-2 days', '+5 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->expects($this->never())->method('update');
		$this->expectException(\Exception::class);
		$this->service->cancelAbsence(11, 'alice');
	}

	public function testCancelAbsenceRejectsNotOwnedAndTerminalStatus(): void
	{
		// foreign owner -> uniform not-found via getAbsence
		$absence = $this->makeAbsenceRow(11, 'bob', Absence::STATUS_PENDING, Absence::TYPE_PERSONAL_LEAVE, '+5 days', '+7 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		try {
			$this->service->cancelAbsence(11, 'alice');
			$this->fail('expected exception');
		} catch (\Exception $e) {
			$this->assertStringContainsString('not found', $e->getMessage());
		}

		// rejected status cannot be cancelled even for the owner
		$absence2 = $this->makeAbsenceRow(12, 'alice', Absence::STATUS_REJECTED, Absence::TYPE_PERSONAL_LEAVE, '+5 days', '+7 days');
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willReturn($absence2);
		// rebuild service with the fresh mapper
		$service = $this->rebuildService($mapper);
		$this->expectException(\Exception::class);
		$service->cancelAbsence(12, 'alice');
	}

	public function testShortenAbsenceRejectsNotStartedAndInvalidNewEnd(): void
	{
		$absence = $this->makeAbsenceRow(21, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_PERSONAL_LEAVE, '+5 days', '+9 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		try {
			$this->service->shortenAbsence(21, 'alice', (new \DateTime('+6 days'))->format('Y-m-d'));
			$this->fail('expected not-started rejection');
		} catch (\Exception $e) {
			$this->assertStringContainsString('already started', $e->getMessage());
		}

		// started, but new end must be < original end
		$absence2 = $this->makeAbsenceRow(22, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_PERSONAL_LEAVE, '-5 days', '+9 days');
		$service = $this->rebuildService($this->mapperReturning($absence2));
		try {
			$service->shortenAbsence(22, 'alice', (new \DateTime('+10 days'))->format('Y-m-d'));
			$this->fail('expected earlier-than-original rejection');
		} catch (\Exception $e) {
			$this->assertStringContainsString('earlier than the original', $e->getMessage());
		}
	}

	public function testShortenAbsenceSuccess(): void
	{
		$absence = $this->makeAbsenceRow(23, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_PERSONAL_LEAVE, '-5 days', '+9 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->expects($this->once())->method('update')
			->willReturnCallback(static fn (Absence $a) => $a);
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'absence_shortened', 'absence', 23);

		$out = $this->service->shortenAbsence(23, 'alice', (new \DateTime('+3 days'))->format('Y-m-d'));
		$this->assertSame((new \DateTime('+3 days'))->format('Y-m-d'), $out->getEndDate()->format('Y-m-d'));
		$this->assertSame(2.0, $out->getDays()); // holiday service stub returns 2.0
	}

	/**
	 * Regression: shortenAbsence must pass a defined request payload to
	 * adjustConsumedAbsence — with the SE service wired (production path),
	 * an undefined $data arg threw a TypeError that escaped as a generic 500.
	 */
	public function testShortenAbsenceAdjustsConsumedSpecialEntitlement(): void
	{
		$newEnd = (new \DateTime('+3 days'))->format('Y-m-d');
		$absence = $this->makeAbsenceRow(24, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_SPECIAL_LEAVE, '-5 days', '+9 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->expects($this->once())->method('update')
			->willReturnCallback(static fn (Absence $a) => $a);

		// SpecialEntitlementService is final: build it without the constructor and
		// inject the reservations mapper through class scope (readonly-safe). The
		// adjust path early-returns on no reservation, but reaching it at all proves
		// a defined array was passed — previously the call died on undefined $data.
		$reservations = $this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class);
		$reservations->expects($this->once())->method('findByAbsenceId')->with(24)->willReturn(null);
		$se = (new \ReflectionClass(SpecialEntitlementService::class))->newInstanceWithoutConstructor();
		\Closure::bind(function (object $r): void {
			$this->reservations = $r;
		}, $se, SpecialEntitlementService::class)($reservations);

		$service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$this->lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService,
			null,
			null,
			null,
			null,
			null,
			null,
			$se
		);
		$out = $service->shortenAbsence(24, 'alice', $newEnd);
		$this->assertSame($newEnd, $out->getEndDate()->format('Y-m-d'));
	}

	public function testAutoApprovePendingIfNoAssignableManager(): void
	{
		// not found -> false
		$mapper = $this->createMock(AbsenceMapper::class);
		$mapper->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->assertFalse($this->rebuildService($mapper)->autoApprovePendingIfNoAssignableManager(5));

		// approved -> false
		$approved = $this->makeAbsenceRow(6, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_PERSONAL_LEAVE, '+1 days', '+3 days');
		$this->assertFalse($this->rebuildService($this->mapperReturning($approved))->autoApprovePendingIfNoAssignableManager(6));

		// pending but manager exists -> false
		$pending = $this->makeAbsenceRow(7, 'alice', Absence::STATUS_PENDING, Absence::TYPE_PERSONAL_LEAVE, '+1 days', '+3 days');
		$this->assertFalse($this->rebuildService($this->mapperReturning($pending))->autoApprovePendingIfNoAssignableManager(7));

		// pending, no manager -> auto-approves
		$this->hasAssignableManagerForTests = false;
		$pending2 = $this->makeAbsenceRow(8, 'alice', Absence::STATUS_PENDING, Absence::TYPE_PERSONAL_LEAVE, '+1 days', '+3 days');
		$mapper2 = $this->mapperReturning($pending2);
		$mapper2->expects($this->once())->method('update')
			->willReturnCallback(static fn (Absence $a) => $a);
		$service = $this->rebuildService($mapper2);
		$this->assertTrue($service->autoApprovePendingIfNoAssignableManager(8));
		$this->assertSame(Absence::STATUS_APPROVED, $pending2->getStatus());
	}

	public function testGetWorkingDaysForDisplayPrefersStoredDays(): void
	{
		$a = $this->makeAbsenceRow(31, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '+1 days', '+3 days');
		$a->setDays(3.5);
		$this->assertSame(3.5, $this->service->getWorkingDaysForDisplay($a));

		$a2 = $this->makeAbsenceRow(32, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '+1 days', '+3 days');
		$a2->setDays(null);
		$this->assertSame(2.0, $this->service->getWorkingDaysForDisplay($a2));

		$a3 = new Absence();
		$a3->setUserId('alice');
		$this->assertSame(0.0, $this->service->getWorkingDaysForDisplay($a3));
	}

	private function mapperReturning(Absence $a): AbsenceMapper
	{
		$m = $this->createMock(AbsenceMapper::class);
		$m->method('find')->willReturn($a);
		return $m;
	}

	private function rebuildService(AbsenceMapper $mapper): AbsenceService
	{
		return new AbsenceService(
			$mapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$this->lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService
		);
	}

	public function testValidateAbsenceTypeRulesEnforcesPerTypeCaps(): void
	{
		$m = new \ReflectionMethod(AbsenceService::class, 'validateAbsenceTypeRules');
		$m->setAccessible(true);
		$d = static fn (int $n): \DateTime => new \DateTime('2026-01-01 +' . ($n - 1) . ' days');
		$start = new \DateTime('2026-01-01');

		// [type, maxDays, overDays, expectedMessage]
		$cases = [
			[Absence::TYPE_VACATION, 30, 31, 'Vacation cannot exceed 30 days'],
			[Absence::TYPE_SICK_LEAVE, 365, 366, 'Sick leave duration seems unreasonable'],
			[Absence::TYPE_PERSONAL_LEAVE, 5, 6, 'Personal leave cannot exceed 5 days'],
			[Absence::TYPE_PARENTAL_LEAVE, 1095, 1096, 'Parental leave cannot exceed 3 years per request'],
			[Absence::TYPE_SPECIAL_LEAVE, 30, 31, 'Special leave cannot exceed 30 days'],
			[Absence::TYPE_UNPAID_LEAVE, 365, 366, 'Unpaid leave cannot exceed 365 days'],
			[Absence::TYPE_HOME_OFFICE, 365, 366, 'Duration cannot exceed 365 days'],
			[Absence::TYPE_BUSINESS_TRIP, 365, 366, 'Duration cannot exceed 365 days'],
		];
		foreach ($cases as [$type, $ok, $over, $msg]) {
			$m->invoke($this->service, $type, clone $start, $d($ok)); // at cap: no throw
			try {
				$m->invoke($this->service, $type, clone $start, $d($over));
				$this->fail("$type over cap did not throw");
			} catch (\Throwable $e) {
				$this->assertStringContainsString($msg, $e->getMessage());
			}
		}
	}

	/**
	 * Service with the overtime-comp guard deps wired: debit estimator + entry mapper.
	 *
	 * @return array{0: AbsenceService, 1: VacationHoursDebitService&\PHPUnit\Framework\MockObject\MockObject, 2: \OCA\ArbeitszeitCheck\Db\TimeEntryMapper&\PHPUnit\Framework\MockObject\MockObject}
	 */
	private function serviceWithCompGuards(): array
	{
		$debit = $this->createMock(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class);
		$entries = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class);
		$service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$this->lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService,
			null,
			null,
			null,
			$debit,
			null,
			$entries
		);
		return [$service, $debit, $entries];
	}

	public function testOvertimeCompRejectsHalfDay(): void
	{
		[$service] = $this->serviceWithCompGuards();
		$m = new \ReflectionMethod(AbsenceService::class, 'validateAbsenceTypeRules');
		$m->setAccessible(true);
		$this->expectException(\OCA\ArbeitszeitCheck\Exception\BusinessRuleException::class);
		$this->expectExceptionMessage('whole days');
		$m->invoke(
			$service,
			Absence::TYPE_OVERTIME_COMP,
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
			['day_fraction' => 0.5],
			'alice'
		);
	}

	public function testOvertimeCompRejectsRangeWithoutPlannedHours(): void
	{
		[$service, $debit] = $this->serviceWithCompGuards();
		$debit->method('estimateForUserRange')->willReturn([
			'hours' => 0.0, 'one_day_hours' => 0.0, 'average_daily' => 0.0, 'basis' => 'none',
		]);
		$m = new \ReflectionMethod(AbsenceService::class, 'validateAbsenceTypeRules');
		$m->setAccessible(true);
		try {
			$m->invoke(
				$service,
				Absence::TYPE_OVERTIME_COMP,
				new \DateTime('2026-09-12'), // Sat
				new \DateTime('2026-09-13'), // Sun
				[],
				'alice'
			);
			$this->fail('weekend-only overtime_comp must be rejected');
		} catch (\Throwable $e) {
			$inner = $e instanceof \ReflectionException || $e instanceof \Exception && method_exists($e, 'getPrevious') ? ($e->getPrevious() ?? $e) : $e;
			$this->assertStringContainsString('no planned working hours', $inner->getMessage());
		}
	}

	public function testOvertimeCompRejectsDayWithTimeEntries(): void
	{
		[$service, $debit, $entries] = $this->serviceWithCompGuards();
		$debit->method('estimateForUserRange')->willReturn([
			'hours' => 8.0, 'one_day_hours' => 8.0, 'average_daily' => 8.0, 'basis' => 'schedule',
		]);
		$entry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$entries->method('findByUserAndDateRange')->willReturn([$entry]);
		$m = new \ReflectionMethod(AbsenceService::class, 'validateAbsenceTypeRules');
		$m->setAccessible(true);
		try {
			$m->invoke(
				$service,
				Absence::TYPE_OVERTIME_COMP,
				new \DateTime('2026-09-09'),
				new \DateTime('2026-09-09'),
				[],
				'alice'
			);
			$this->fail('overtime_comp on a worked day must be rejected');
		} catch (\Throwable $e) {
			$this->assertStringContainsString('already contain time entries', $e->getMessage());
		}
	}

	public function testOvertimeCompValidFullDayPasses(): void
	{
		[$service, $debit, $entries] = $this->serviceWithCompGuards();
		$debit->method('estimateForUserRange')->willReturn([
			'hours' => 8.0, 'one_day_hours' => 8.0, 'average_daily' => 8.0, 'basis' => 'schedule',
		]);
		$entries->method('findByUserAndDateRange')->willReturn([]);
		$m = new \ReflectionMethod(AbsenceService::class, 'validateAbsenceTypeRules');
		$m->setAccessible(true);
		$m->invoke(
			$service,
			Absence::TYPE_OVERTIME_COMP,
			new \DateTime('2026-09-09'),
			new \DateTime('2026-09-09'),
			[],
			'alice'
		);
		$this->assertTrue(true); // reaching here without throw is the assertion
	}

	public function testOvertimeCompOverThirtyDaysThrows(): void
	{
		[$service] = $this->serviceWithCompGuards();
		$m = new \ReflectionMethod(AbsenceService::class, 'validateAbsenceTypeRules');
		$m->setAccessible(true);
		try {
			$m->invoke(
				$service,
				Absence::TYPE_OVERTIME_COMP,
				new \DateTime('2026-09-01'),
				new \DateTime('2026-10-01'), // 31 days
				[],
				'alice'
			);
			$this->fail('overtime_comp over 30 days must be rejected');
		} catch (\Throwable $e) {
			$this->assertStringContainsString('Duration cannot exceed 30 days', $e->getMessage());
		}
	}

	// ---------- convertVacationToSick (spec: vacation-to-sick-conversion-2026-10) ----------

	private function runningVacation(int $id = 42, string $userId = 'alice'): Absence
	{
		return $this->makeAbsenceRow($id, $userId, Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-5 days', '+9 days');
	}

	private function casToken(Absence $a): string
	{
		return $a->getUpdatedAt() ? $a->getUpdatedAt()->format('c') : '';
	}

	private function convertData(Absence $a, string $sickStart, string $sickEnd, array $extra = []): array
	{
		return array_merge([
			'sickStart' => (new \DateTime($sickStart))->format('Y-m-d'),
			'sickEnd' => (new \DateTime($sickEnd))->format('Y-m-d'),
			'expectedUpdatedAt' => $this->casToken($a),
			'reason' => '',
		], $extra);
	}

	private function expectUpdateInsertUpdate(): void
	{
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->absenceMapper->method('insert')->willReturnCallback(static function (Absence $a) {
			if ($a->getId() === null) {
				$a->setId(999);
			}
			return $a;
		});
	}

	public function testConvertVacationToSickTailCutShortensVacationAndCreatesPendingSick(): void
	{
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->expectUpdateInsertUpdate();
		$audited = [];
		$this->auditLogMapper->method('logAction')->willReturnCallback(
			function ($u, $action, $et, $eid, $old, $new, $pb) use (&$audited) {
				$audited[] = $action;
				return $this->createMock(\OCA\ArbeitszeitCheck\Db\AuditLog::class);
			}
		);

		$result = $this->service->convertVacationToSick(42, $this->convertData($absence, '+2 days', '+9 days'), 'alice', false);

		$this->assertSame((new \DateTime('+1 days'))->format('Y-m-d'), $result['vacation']['endDate'] ?? $absence->getEndDate()->format('Y-m-d'));
		$this->assertSame(Absence::STATUS_PENDING, $result['sick']['status']);
		$this->assertSame(Absence::TYPE_SICK_LEAVE, $result['sick']['type']);
		$this->assertNull($result['tail']);
		$this->assertContains('absence_converted_to_sick', $audited);
		$this->assertContains('absence_created', $audited);
	}

	public function testConvertVacationToSickCarriesStoredHalfDayFraction(): void
	{
		// Single-day vacation storing a 0.5 debit. The weight mock returns
		// 2.0 for every range → stored fraction 0.25, carried sick days
		// 2.0 × 0.25 = 0.5. A conversion that dropped the carry would write
		// the full weight (2.0).
		$absence = $this->makeAbsenceRow(42, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-5 days', '-5 days');
		$absence->getStartDate()->setTime(0, 0, 0);
		$absence->getEndDate()->setTime(0, 0, 0);
		$absence->setDays(0.5);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->expectUpdateInsertUpdate();

		$result = $this->service->convertVacationToSick(42, $this->convertData($absence, '-5 days', '-5 days'), 'manager', true);

		$this->assertEqualsWithDelta(0.5, (float)$result['sick']['days'], 0.001);
	}

	public function testConvertVacationToSickFullCancelsVacation(): void
	{
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->expectUpdateInsertUpdate();
		$this->db->expects($this->once())->method('commit');

		$result = $this->service->convertVacationToSick(42, $this->convertData($absence, '-5 days', '+9 days'), 'alice', false);
		$this->assertSame(Absence::STATUS_CANCELLED, $absence->getStatus());
		$this->assertSame(Absence::STATUS_PENDING, $result['sick']['status']);
	}

	public function testConvertVacationToSickHeadCutMovesVacationStart(): void
	{
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->expectUpdateInsertUpdate();

		$result = $this->service->convertVacationToSick(42, $this->convertData($absence, '-5 days', '+2 days'), 'alice', false);
		$this->assertSame((new \DateTime('+3 days'))->format('Y-m-d'), $absence->getStartDate()->format('Y-m-d'));
		$this->assertNull($result['tail']);
	}

	public function testConvertVacationToSickMidSplitCreatesTailVacation(): void
	{
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$inserted = [];
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->absenceMapper->expects($this->exactly(2))->method('insert')
			->willReturnCallback(function (Absence $a) use (&$inserted) {
				$inserted[] = $a;
				$a->setId(1000 + count($inserted));
				return $a;
			});

		$result = $this->service->convertVacationToSick(42, $this->convertData($absence, '+1 days', '+3 days'), 'alice', false);

		$this->assertNotNull($result['tail']);
		$this->assertCount(2, $inserted);
		$this->assertSame(Absence::TYPE_SICK_LEAVE, $inserted[0]->getType());
		$this->assertSame(Absence::TYPE_VACATION, $inserted[1]->getType());
		$this->assertSame((new \DateTime('+4 days'))->format('Y-m-d'), $inserted[1]->getStartDate()->format('Y-m-d'));
		$this->assertSame(Absence::STATUS_APPROVED, $inserted[1]->getStatus());
		$this->assertSame('alice', $inserted[1]->getUserId());
		$this->assertSame((new \DateTime('today'))->format('Y-m-d'), $absence->getEndDate()->format('Y-m-d'));
	}

	public function testConvertVacationToSickManagerFlowApprovesSickRetroactively(): void
	{
		// Vacation already ended — only the manager path may convert it.
		$absence = $this->makeAbsenceRow(42, 'bob', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-10 days', '-3 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$insertedSick = null;
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->absenceMapper->method('insert')->willReturnCallback(function (Absence $a) use (&$insertedSick) {
			if ($a->getType() === Absence::TYPE_SICK_LEAVE) {
				$insertedSick = $a;
			}
			$a->setId(999);
			return $a;
		});
		$audited = [];
		$this->auditLogMapper->method('logAction')->willReturnCallback(
			function ($u, $action) use (&$audited) {
				$audited[] = $action;
				return $this->createMock(\OCA\ArbeitszeitCheck\Db\AuditLog::class);
			}
		);

		$this->service->convertVacationToSick(42, $this->convertData($absence, '-8 days', '-6 days'), 'manager-1', true);
		$this->assertNotNull($insertedSick);
		$this->assertSame(Absence::STATUS_APPROVED, $insertedSick->getStatus());
		$this->assertSame('manager-1', $insertedSick->getApprovedByUserId());
		$this->assertContains('absence_manager_recorded', $audited);
	}

	public function testConvertVacationToSickRejectsForeignAbsence(): void
	{
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->expectException(DoesNotExistException::class);
		$this->service->convertVacationToSick(42, $this->convertData($absence, '+1 days', '+3 days'), 'mallory', false);
	}

	public function testConvertVacationToSickRejectsWrongTypeAndStatus(): void
	{
		$sick = $this->makeAbsenceRow(43, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_SICK_LEAVE, '-5 days', '+9 days');
		$this->absenceMapper->method('find')->willReturn($sick);
		try {
			$this->service->convertVacationToSick(43, $this->convertData($sick, '+1 days', '+3 days'), 'alice', false);
			$this->fail('type check must fire');
		} catch (\Exception $e) {
			// FA-2a whitelist: non-convertible types reject with a machine code.
			$this->assertSame('type_not_convertible', $e->getMessage());
		}

		$pending = $this->runningVacation(44);
		$pending->setStatus(Absence::STATUS_PENDING);
		$mapper = $this->mapperReturning($pending);
		$service = $this->rebuildService($mapper);
		try {
			$service->convertVacationToSick(44, $this->convertData($pending, '+1 days', '+3 days'), 'alice', false);
			$this->fail('status check must fire');
		} catch (\Exception $e) {
			$this->assertStringContainsString('approved', $e->getMessage());
		}
	}

	public function testConvertVacationToSickOwnerFlowRejectsFutureVacation(): void
	{
		$absence = $this->makeAbsenceRow(45, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '+5 days', '+9 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		try {
			$this->service->convertVacationToSick(45, $this->convertData($absence, '+6 days', '+8 days'), 'alice', false);
			$this->fail('future vacation must be rejected for owner flow');
		} catch (\Exception $e) {
			$this->assertStringContainsString('cancel', $e->getMessage());
		}
		// Same absence is convertible in the manager flow (retro/future allowed).
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->expectUpdateInsertUpdate();
		$result = $this->service->convertVacationToSick(45, $this->convertData($absence, '+6 days', '+8 days'), 'manager-1', true);
		$this->assertSame(Absence::STATUS_APPROVED, $result['sick']['status']);
	}

	public function testConvertVacationToSickRejectsStaleRevision(): void
	{
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);
		$data = $this->convertData($absence, '+1 days', '+3 days');
		$data['expectedUpdatedAt'] = '2001-01-01T00:00:00+00:00';
		try {
			$this->service->convertVacationToSick(42, $data, 'alice', false);
			$this->fail('stale revision must be rejected');
		} catch (\Exception $e) {
			$this->assertSame('revision_stale', $e->getMessage());
		}
	}

	public function testConvertVacationToSickRejectsRangeOutsideVacation(): void
	{
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);
		try {
			$this->service->convertVacationToSick(42, $this->convertData($absence, '+8 days', '+12 days'), 'alice', false);
			$this->fail('range outside must be rejected');
		} catch (\Exception $e) {
			$this->assertSame('range_outside', $e->getMessage());
		}
	}

	public function testConvertVacationToSickOverlapBlocksAndRollsBack(): void
	{
		$absence = $this->runningVacation();
		$other = $this->makeAbsenceRow(50, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_SICK_LEAVE, '+1 days', '+2 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		// TOCTOU: the plan-time probe sees a clean window; a concurrent
		// insert lands before the in-lock overlap check — that second,
		// authoritative probe is what must abort the conversion.
		$this->absenceMapper->method('findOverlapping')
			->willReturnOnConsecutiveCalls([], [$other]);
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		try {
			$this->service->convertVacationToSick(42, $this->convertData($absence, '+1 days', '+3 days'), 'alice', false);
			$this->fail('overlap must be rejected');
		} catch (\Exception $e) {
			$this->assertSame('overlap', $e->getMessage());
		}
	}

	public function testConvertVacationToSickDryRunWritesNothing(): void
	{
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->expects($this->never())->method('update');
		$this->absenceMapper->expects($this->never())->method('insert');
		$this->db->expects($this->never())->method('beginTransaction');
		$this->auditLogMapper->expects($this->never())->method('logAction');

		$plan = $this->service->convertVacationToSick(42, $this->convertData($absence, '+1 days', '+3 days', ['dryRun' => '1']), 'alice', false);
		$this->assertSame('shorten_and_split', $plan['vacation']['action']);
		$this->assertSame('pending', $plan['sick']['status']);
		$this->assertNotNull($plan['tail']);
	}

	public function testConvertVacationToSickRollsBackWhenSickInsertFails(): void
	{
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->absenceMapper->method('insert')->willThrowException(new \RuntimeException('db gone'));
		$this->db->expects($this->once())->method('rollBack');
		try {
			$this->service->convertVacationToSick(42, $this->convertData($absence, '+1 days', '+3 days'), 'alice', false);
			$this->fail('insert failure must propagate');
		} catch (\RuntimeException $e) {
			$this->assertSame('db gone', $e->getMessage());
		}
	}

	public function testConvertVacationToSickRejectsHalfDayBoundary(): void
	{
		$absence = $this->runningVacation();
		// stored 2.4 vs computed 2.0 → diff 0.4 sits above the 0.011 epsilon
		// but below a widened 0.5 threshold: this kills threshold mutants.
		$absence->setDays(2.4);
		$this->absenceMapper->method('find')->willReturn($absence);
		try {
			$this->service->convertVacationToSick(42, $this->convertData($absence, '+1 days', '+3 days'), 'alice', false);
			$this->fail('fraction boundary must be rejected');
		} catch (\Exception $e) {
			$this->assertSame('fraction_boundary', $e->getMessage());
		}
	}

	public function testConvertVacationToSickAllowsVacationEndingToday(): void
	{
		// vacEnd == storage-day start instant is still a running vacation —
		// the owner gate must use strict <. Reading the boundary from the
		// same clock source the service uses keeps this deterministic in
		// any storage TZ (TimeZoneService is final, no mock).
		$prop = new \ReflectionProperty(AbsenceService::class, 'timeZoneService');
		$prop->setAccessible(true);
		[$todayStart] = $prop->getValue($this->service)->todayWindowInStorage();
		$absence = $this->runningVacation();
		$absence->setStartDate((clone $todayStart)->modify('-5 days'));
		$absence->setEndDate(clone $todayStart);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->expectUpdateInsertUpdate();

		// Full-range conversion skips the fraction check (sick == whole vacation).
		$data = [
			'sickStart' => (clone $todayStart)->modify('-5 days')->format('Y-m-d'),
			'sickEnd' => $todayStart->format('Y-m-d'),
			'expectedUpdatedAt' => $this->casToken($absence),
			'reason' => '',
		];
		$result = $this->service->convertVacationToSick(42, $data, 'alice', false);
		$this->assertSame(Absence::STATUS_CANCELLED, $absence->getStatus());
		$this->assertSame(Absence::STATUS_PENDING, $result['sick']['status']);
	}

	public function testConvertVacationToSickInLockRecheckRejectsConcurrentDrift(): void
	{
		// CAS is re-verified under the user lock: a second find() returning a
		// newer updatedAt (concurrent shorten/cancel between plan and lock)
		// must abort with revision_stale and write nothing.
		$before = $this->runningVacation();
		$drifted = $this->runningVacation();
		$drifted->setUpdatedAt(new \DateTime('-1 day'));
		$this->absenceMapper->method('find')->willReturnOnConsecutiveCalls($before, $before, $drifted);
		$this->absenceMapper->expects($this->never())->method('insert');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		try {
			$this->service->convertVacationToSick(42, $this->convertData($before, '+2 days', '+9 days'), 'alice', false);
			$this->fail('in-lock drift must be rejected');
		} catch (\Exception $e) {
			$this->assertSame('revision_stale', $e->getMessage());
		}
	}

	public function testConvertVacationToSickMonthClosurePropagates(): void
	{
		// The VACATION-range closure check (assertAbsenceMutable) must gate
		// independently of the sick-range check: only calls for the
		// vacation's own range throw here, so removing that assert lets the
		// conversion slip through — which this test then catches.
		$absence = $this->runningVacation();
		$vacStartDay = $absence->getStartDate()->format('Y-m-d');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->monthClosureService->method('assertDateRangeMutable')
			->willReturnCallback(static function ($userId, $start, $end) use ($vacStartDay) {
				if ($start->format('Y-m-d') === $vacStartDay) {
					throw new \OCA\ArbeitszeitCheck\Exception\MonthFinalizedException('closed');
				}
				return null;
			});
		$this->absenceMapper->expects($this->never())->method('insert');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\MonthFinalizedException::class);
		$this->service->convertVacationToSick(42, $this->convertData($absence, '+2 days', '+9 days'), 'alice', false);
	}

	public function testConvertVacationToSickLockContentionBubblesBusy(): void
	{
		// A held per-user mutation lock must surface AbsenceMutationBusyException
		// (→ HTTP 423 upstream) before any transaction work starts.
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		// Shared pre-locks (unit-migration, year-mode) pass; the exclusive
		// per-user mutation lock ('azc/ab/<md5>') is the one that is held.
		$this->lockingProvider->method('acquireLock')
			->willReturnCallback(static function (string $key) {
				if (str_starts_with($key, 'azc/ab/')) {
					throw new \OCP\Lock\LockedException('held');
				}
				return null;
			});
		$this->db->expects($this->never())->method('beginTransaction');
		$this->absenceMapper->expects($this->never())->method('insert');

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\AbsenceMutationBusyException::class);
		$this->service->convertVacationToSick(42, $this->convertData($absence, '+2 days', '+9 days'), 'alice', false);
	}

	// ---------- FA-2a: whitelist conversion (absence-storno-conversion-bt-rules-2026-11) ----------

	public function testConvertWhitelistConvertsPersonalLeaveAndKeepsTailType(): void
	{
		// Mid-split of an approved personal_leave: head shortens, tail keeps
		// the SOURCE type (NN-07) — never vacation.
		$absence = $this->makeAbsenceRow(42, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_PERSONAL_LEAVE, '-5 days', '+9 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$inserted = [];
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->absenceMapper->method('insert')->willReturnCallback(function (Absence $a) use (&$inserted) {
			$inserted[] = $a;
			$a->setId(2000 + count($inserted));
			return $a;
		});

		$result = $this->service->convertVacationToSick(42, $this->convertData($absence, '+1 days', '+3 days'), 'alice', false);

		$this->assertCount(2, $inserted);
		$this->assertSame(Absence::TYPE_SICK_LEAVE, $inserted[0]->getType());
		$this->assertSame(Absence::TYPE_PERSONAL_LEAVE, $inserted[1]->getType());
		$this->assertNotNull($result['tail']);
		$this->assertSame(Absence::STATUS_PENDING, $result['sick']['status']);
	}

	public function testConvertWhitelistRejectsSickFamilyAndParental(): void
	{
		// Spec AC-4: sick-family (conversion target family) and parental
		// leave reject with the machine code.
		foreach ([Absence::TYPE_SICK_LEAVE, Absence::TYPE_CHILD_SICK, Absence::TYPE_PARENTAL_LEAVE] as $type) {
			$row = $this->makeAbsenceRow(43, 'alice', Absence::STATUS_APPROVED, $type, '-5 days', '+9 days');
			$service = $this->rebuildService($this->mapperReturning($row));
			try {
				$service->convertVacationToSick(43, $this->convertData($row, '+1 days', '+3 days'), 'alice', false);
				$this->fail("$type must not be convertible");
			} catch (\Exception $e) {
				$this->assertSame('type_not_convertible', $e->getMessage(), "type=$type");
			}
		}
	}

	// ---------- FA-2b: manager cancel (shared pipeline) ----------

	public function testManagerCancelCancelsApprovedAndNotifies(): void
	{
		$absence = $this->makeAbsenceRow(50, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_PERSONAL_LEAVE, '-2 days', '+5 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->db->expects($this->once())->method('commit');
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'absence_manager_cancelled', 'absence', 50, $this->anything(), $this->anything(), 'manager-1');
		$this->notificationService->expects($this->once())->method('notifyAbsenceCancelledByManager')
			->with('alice', $this->anything(), 'medical emergency');

		$out = $this->service->managerCancelApprovedAbsence(50, [
			'reason' => 'medical emergency',
			'expectedUpdatedAt' => $this->casToken($absence),
		], 'manager-1');

		$this->assertSame(Absence::STATUS_CANCELLED, $out->getStatus());
	}

	public function testManagerCancelRequiresReasonAndValidRevision(): void
	{
		$absence = $this->makeAbsenceRow(50, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_PERSONAL_LEAVE, '+1 days', '+5 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		try {
			$this->service->managerCancelApprovedAbsence(50, ['reason' => '  ', 'expectedUpdatedAt' => $this->casToken($absence)], 'manager-1');
			$this->fail('empty reason must fail');
		} catch (\Exception $e) {
			$this->assertSame('reason_required', $e->getMessage());
		}
		try {
			$this->service->managerCancelApprovedAbsence(50, ['reason' => 'ok', 'expectedUpdatedAt' => '2001-01-01T00:00:00+00:00'], 'manager-1');
			$this->fail('stale revision must fail');
		} catch (\Exception $e) {
			$this->assertSame('revision_stale', $e->getMessage());
		}
	}

	public function testManagerCancelRejectsEndedAbsence(): void
	{
		$absence = $this->makeAbsenceRow(50, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_PERSONAL_LEAVE, '-9 days', '-2 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		try {
			$this->service->managerCancelApprovedAbsence(50, ['reason' => 'x', 'expectedUpdatedAt' => $this->casToken($absence)], 'manager-1');
			$this->fail('ended absence must reject');
		} catch (\Exception $e) {
			$this->assertSame('already_ended', $e->getMessage());
		}
	}

	// ---------- FA-2c: owner cancel-request / withdraw / manager decision ----------

	private function requestData(Absence $a, array $extra = []): array
	{
		return array_merge(['expectedUpdatedAt' => $this->casToken($a)], $extra);
	}

	public function testRequestCancelSetsFlagsKeepsApprovedAndNotifiesManagers(): void
	{
		$absence = $this->makeAbsenceRow(60, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-2 days', '+5 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->teamResolver->method('getManagerIdsForEmployee')->with('alice')->willReturn(['manager-1']);
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'absence_cancel_requested', 'absence', 60);
		$this->notificationService->expects($this->once())->method('notifyAbsenceCancelRequested')
			->with('manager-1', 'alice', $this->anything(), 'family emergency');

		$out = $this->service->requestAbsenceCancellation(60, $this->requestData($absence, ['reason' => 'family emergency']), 'alice');

		$this->assertSame(Absence::STATUS_APPROVED, $out->getStatus());
		$this->assertNotNull($out->getCancelRequestedAt());
		$this->assertSame('alice', $out->getCancelRequestedBy());
		$this->assertSame('family emergency', $out->getCancelReason());
	}

	public function testRequestCancelRejectsFutureDuplicateAndStale(): void
	{
		$future = $this->makeAbsenceRow(60, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '+2 days', '+5 days');
		$mapper = $this->mapperReturning($future);
		$service = $this->rebuildService($mapper);
		try {
			$service->requestAbsenceCancellation(60, $this->requestData($future, ['reason' => 'x']), 'alice');
			$this->fail('future absence must use direct cancel');
		} catch (\Exception $e) {
			$this->assertSame('use_direct_cancel', $e->getMessage());
		}

		$running = $this->makeAbsenceRow(61, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-2 days', '+5 days');
		$running->setCancelRequestedAt(new \DateTime());
		$running->setCancelRequestedBy('alice');
		$mapper = $this->mapperReturning($running);
		$service = $this->rebuildService($mapper);
		try {
			$service->requestAbsenceCancellation(61, $this->requestData($running, ['reason' => 'x']), 'alice');
			$this->fail('duplicate request must fail');
		} catch (\Exception $e) {
			$this->assertSame('cancel_request_pending', $e->getMessage());
		}

		$running2 = $this->makeAbsenceRow(62, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-2 days', '+5 days');
		$mapper = $this->mapperReturning($running2);
		$service = $this->rebuildService($mapper);
		try {
			$service->requestAbsenceCancellation(62, ['expectedUpdatedAt' => '2001-01-01T00:00:00+00:00', 'reason' => 'x'], 'alice');
			$this->fail('stale revision must fail');
		} catch (\Exception $e) {
			$this->assertSame('revision_stale', $e->getMessage());
		}
	}

	public function testRequestCancelIsOwnerOnly(): void
	{
		$absence = $this->makeAbsenceRow(60, 'bob', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-2 days', '+5 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->expectException(DoesNotExistException::class);
		$this->service->requestAbsenceCancellation(60, $this->requestData($absence, ['reason' => 'x']), 'alice');
	}

	public function testWithdrawClearsFlagsAndKeepsApproved(): void
	{
		$absence = $this->makeAbsenceRow(60, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-2 days', '+5 days');
		$absence->setCancelRequestedAt(new \DateTime('-1 hour'));
		$absence->setCancelRequestedBy('alice');
		$absence->setCancelReason('family emergency');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'absence_cancel_request_withdrawn', 'absence', 60);

		$out = $this->service->withdrawAbsenceCancelRequest(60, $this->requestData($absence), 'alice');

		$this->assertSame(Absence::STATUS_APPROVED, $out->getStatus());
		$this->assertNull($out->getCancelRequestedAt());
		$this->assertNull($out->getCancelRequestedBy());
		$this->assertNull($out->getCancelReason());
	}

	public function testApproveCancelRequestRunsSharedCancelPipeline(): void
	{
		$absence = $this->makeAbsenceRow(60, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-2 days', '+5 days');
		$absence->setCancelRequestedAt(new \DateTime('-1 hour'));
		$absence->setCancelRequestedBy('alice');
		$absence->setCancelReason('family emergency');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'absence_manager_cancelled', 'absence', 60, $this->anything(), $this->anything(), 'manager-1');
		$this->notificationService->expects($this->once())->method('notifyAbsenceCancelledByManager')
			->with('alice', $this->anything(), 'family emergency');

		$out = $this->service->approveAbsenceCancelRequest(60, $this->requestData($absence), 'manager-1');

		$this->assertSame(Absence::STATUS_CANCELLED, $out->getStatus());
		$this->assertNull($out->getCancelRequestedAt());
	}

	public function testDeclineCancelRequestClearsFlagsNotifiesOwner(): void
	{
		$absence = $this->makeAbsenceRow(60, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-2 days', '+5 days');
		$absence->setCancelRequestedAt(new \DateTime('-1 hour'));
		$absence->setCancelRequestedBy('alice');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'absence_cancel_request_declined', 'absence', 60);
		$this->notificationService->expects($this->once())->method('notifyAbsenceCancelDeclined')
			->with('alice', $this->anything(), 'still needed');

		$out = $this->service->declineAbsenceCancelRequest(60, $this->requestData($absence, ['comment' => 'still needed']), 'manager-1');

		$this->assertSame(Absence::STATUS_APPROVED, $out->getStatus());
		$this->assertNull($out->getCancelRequestedAt());
	}

	public function testDecisionWithoutOpenRequestRejects(): void
	{
		$absence = $this->makeAbsenceRow(60, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '-2 days', '+5 days');
		$this->absenceMapper->method('find')->willReturn($absence);
		try {
			$this->service->approveAbsenceCancelRequest(60, $this->requestData($absence), 'manager-1');
			$this->fail('no open request must fail');
		} catch (\Exception $e) {
			$this->assertSame('no_cancel_request', $e->getMessage());
		}
	}

	// ---------- FA-2a: entitlement-backed sources (AC-S5 / AC-S6 / MH-04) ----------

	private function serviceWithEntitlements(AbsenceMapper $mapper, SpecialEntitlementService $se): AbsenceService
	{
		return new AbsenceService(
			$mapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$this->lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService,
			null,
			null,
			null,
			null,
			null,
			null,
			$se
		);
	}

	/**
	 * SpecialEntitlementService is final — instantiate without the ctor and
	 * bind only the collaborators the exercised path touches (same pattern as
	 * testShortenAbsenceAdjustsConsumedSpecialEntitlement).
	 */
	private function entitlementServiceStub(array $props): SpecialEntitlementService
	{
		$se = (new \ReflectionClass(SpecialEntitlementService::class))->newInstanceWithoutConstructor();
		foreach ($props as $name => $value) {
			\Closure::bind(function () use ($name, $value): void {
				$this->{$name} = $value;
			}, $se, SpecialEntitlementService::class)();
		}
		return $se;
	}

	private function consumedReservation(int $id, int $revision, int $periodId): \OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservation
	{
		// Entity accessors are magic __call — a real row, not a mock.
		$reservation = new \OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservation();
		$reservation->setId($id);
		$reservation->setStatus(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservation::STATUS_CONSUMED);
		$reservation->setRevision($revision);
		$reservation->setPeriodId($periodId);
		return $reservation;
	}

	/**
	 * AC-S5: an interior sick range on an entitlement-backed absence rejects
	 * BEFORE any write — the tail row could never carry a reservation (BQ-2).
	 */
	public function testConvertEntitlementBackedMidSplitRejectsBeforeWrite(): void
	{
		$absence = $this->makeAbsenceRow(46, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_SPECIAL_LEAVE, '-5 days', '+9 days');
		$reservations = $this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class);
		$reservations->method('findByAbsenceId')->with(46)->willReturn($this->consumedReservation(9, 3, 5));
		$se = $this->entitlementServiceStub(['reservations' => $reservations]);
		$service = $this->serviceWithEntitlements($this->mapperReturning($absence), $se);

		try {
			$service->convertVacationToSick(46, $this->convertData($absence, '+1 days', '+3 days'), 'alice', false);
			$this->fail('entitlement mid-split must reject');
		} catch (\Exception $e) {
			$this->assertSame('entitlement_mid_split', $e->getMessage());
		}
		// No write ever happened: status untouched, no sick row, no release.
		$this->assertSame(Absence::STATUS_APPROVED, $absence->getStatus());
		$reservations->expects($this->never())->method('transitionIfRevision');
	}

	/**
	 * AC-S6 / NN-08: full conversion of an entitlement-backed absence must run
	 * releaseForAbsence — the reservation transitions to RELEASED inside the
	 * same transaction (compensating ledger), never adjust-on-cancelled.
	 */
	public function testConvertEntitlementBackedFullReleasesReservation(): void
	{
		$absence = $this->makeAbsenceRow(47, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_SPECIAL_LEAVE, '-5 days', '+9 days');
		$reservations = $this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class);
		$reservations->method('findByAbsenceId')->with(47)->willReturn($this->consumedReservation(9, 3, 5));
		$reservations->expects($this->once())->method('transitionIfRevision')
			->with(9, 3, \OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservation::STATUS_RELEASED)
			->willReturn(true);
		$ledger = $this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper::class);
		$ledger->method('findByEventKey')->willReturn(null);
		$se = $this->entitlementServiceStub([
			'reservations' => $reservations,
			'ledger' => $ledger,
			'audit' => $this->auditLogMapper,
			'lockingProvider' => $this->lockingProvider,
		]);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->absenceMapper->method('insert')->willReturnCallback(static function (Absence $a) {
			$a->setId(3000);
			return $a;
		});
		$service = $this->serviceWithEntitlements($this->absenceMapper, $se);

		$result = $service->convertVacationToSick(47, $this->convertData($absence, '-5 days', '+9 days'), 'alice', false);

		$this->assertSame(Absence::STATUS_CANCELLED, $absence->getStatus());
		$this->assertSame(Absence::TYPE_SICK_LEAVE, $result['sick']['type']);
	}

	/**
	 * MH-04: head/tail cuts keep the row alive — adjustConsumedAbsence runs
	 * (re-pricing the remaining window), the reservation must NOT be released.
	 */
	public function testConvertEntitlementBackedHeadCutAdjustsNotReleases(): void
	{
		$absence = $this->makeAbsenceRow(48, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_SPECIAL_LEAVE, '-5 days', '+9 days');
		$reservations = $this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class);
		$reservations->method('findByAbsenceId')->with(48)->willReturn($this->consumedReservation(9, 3, 5));
		$reservations->expects($this->never())->method('transitionIfRevision');
		// adjustConsumedAbsenceLocked early-returns when the type is unknown —
		// reaching this call proves the adjust path ran instead of release.
		$types = $this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper::class);
		$types->expects($this->once())->method('findByAbsenceTypeCode')->with(Absence::TYPE_SPECIAL_LEAVE)->willReturn(null);
		$ledger = $this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper::class);
		$se = $this->entitlementServiceStub([
			'reservations' => $reservations,
			'types' => $types,
			'ledger' => $ledger,
			'lockingProvider' => $this->lockingProvider,
		]);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->absenceMapper->method('insert')->willReturnCallback(static function (Absence $a) {
			$a->setId(3001);
			return $a;
		});
		$service = $this->serviceWithEntitlements($this->absenceMapper, $se);

		$result = $service->convertVacationToSick(48, $this->convertData($absence, '-5 days', '-3 days'), 'alice', false);

		// Head cut: start moved to the day after the sick window, still approved.
		$this->assertSame(Absence::STATUS_APPROVED, $absence->getStatus());
		$this->assertSame((new \DateTime('-2 days'))->format('Y-m-d'), $absence->getStartDate()->format('Y-m-d'));
		$this->assertNull($result['tail']);
	}

	/**
	 * QA finding (fail-closed guard): a LIVE reservation that is not yet
	 * consumed (status active — e.g. a reservation attached post-approval by
	 * any future path) must still block the mid-split. The guard checks
	 * "not released", not only "consumed".
	 */
	public function testConvertEntitlementBackedActiveReservationMidSplitRejects(): void
	{
		$absence = $this->makeAbsenceRow(49, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_SPECIAL_LEAVE, '-5 days', '+9 days');
		$reservation = new \OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservation();
		$reservation->setId(10);
		$reservation->setStatus(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservation::STATUS_ACTIVE);
		$reservation->setRevision(1);
		$reservation->setPeriodId(5);
		$reservations = $this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class);
		$reservations->method('findByAbsenceId')->with(49)->willReturn($reservation);
		$se = $this->entitlementServiceStub(['reservations' => $reservations]);
		$service = $this->serviceWithEntitlements($this->mapperReturning($absence), $se);

		try {
			$service->convertVacationToSick(49, $this->convertData($absence, '+1 days', '+3 days'), 'alice', false);
			$this->fail('mid-split must reject for ANY live reservation, not only consumed ones');
		} catch (\Exception $e) {
			$this->assertSame('entitlement_mid_split', $e->getMessage());
		}
		$this->assertSame(Absence::STATUS_APPROVED, $absence->getStatus());
	}

	/**
	 * QA finding: a full conversion cancels the source row — an open cancel
	 * request is moot and must be cleared in the same write, or the dead row
	 * keeps a "Cancellation requested" badge + undecidable decide buttons.
	 */
	public function testConvertFullClearsOpenCancelRequestFlags(): void
	{
		$absence = $this->runningVacation();
		$absence->setCancelRequestedAt(new \DateTime('now'));
		$absence->setCancelRequestedBy('alice');
		$absence->setCancelReason('family emergency');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->expectUpdateInsertUpdate();
		$this->db->expects($this->once())->method('commit');

		$this->service->convertVacationToSick(42, $this->convertData($absence, '-5 days', '+9 days'), 'alice', false);

		$this->assertSame(Absence::STATUS_CANCELLED, $absence->getStatus());
		$this->assertNull($absence->getCancelRequestedAt());
		$this->assertNull($absence->getCancelRequestedBy());
		$this->assertNull($absence->getCancelReason());
	}

	/**
	 * QA finding: canDecideCancelRequest must express *decidability* —
	 * a cancelled row with stale flags must not render decide buttons.
	 */
	public function testManagerFlagsHideDecisionOnCancelledRowWithStaleFlags(): void
	{
		$absence = $this->runningVacation();
		$absence->setStatus(Absence::STATUS_CANCELLED);
		$absence->setCancelRequestedAt(new \DateTime('now'));

		$flags = $this->service->managerAbsenceActionFlags($absence);

		$this->assertFalse($flags['canDecideCancelRequest']);
	}
	/**
	 * state_transition_toctou (r43 MF-1): every absence write path must hold
	 * the month-closure advisory locks (azc/mc/, LOCK_EXCLUSIVE) across the
	 * guard→commit window, acquired BEFORE beginTransaction (the DB locking
	 * provider writes oc_file_locks on the same connection — an in-tx
	 * acquire would be invisible to a concurrent finalize until commit).
	 *
	 * For each named write path we drive the method into its transaction and
	 * assert: at least one azc/mc/ key was acquired EXCLUSIVE strictly before
	 * the tx began, and every acquired mc key is released on exit.
	 *
	 * @dataProvider monthClosureLockWritePaths
	 */
	public function testAbsenceWritePathsHoldMonthClosureLocksAcrossTx(string $method): void
	{
		$events = [];
		$lp = $this->createMock(ILockingProvider::class);
		$lp->method('acquireLock')->willReturnCallback(
			static function (string $key, int $type) use (&$events): void {
				$events[] = ['acq', $key, $type];
			}
		);
		$lp->method('releaseLock')->willReturnCallback(
			static function (string $key, int $type) use (&$events): void {
				$events[] = ['rel', $key, $type];
			}
		);
		$db = $this->createMock(\OCP\IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(static function () use (&$events): void {
			$events[] = ['tx', null, null];
		});
		$db->method('commit');
		$db->method('rollBack');

		$mc = $this->createMock(MonthClosureService::class);
		$mc->method('monthsOverlappingRange')->willReturn([[2026, 6]]);
		$mc->method('assertDateRangeMutable');

		$service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$db,
			$lp,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$mc
		);

		$this->fixtureMonthClosureLockPath($method);

		try {
			$this->invokeMonthClosureLockPath($service, $method);
		} catch (\Throwable $e) {
			// A post-lock failure still must satisfy the lock protocol.
		}

		$acquiredMc = [];
		$releasedMc = [];
		$txIndex = null;
		foreach ($events as $i => [$kind, $key, $type]) {
			if ($kind === 'tx' && $txIndex === null) {
				$txIndex = $i;
			}
			if (is_string($key) && str_starts_with($key, 'azc/mc/')) {
				if ($kind === 'acq') {
					$acquiredMc[] = [$i, $type];
				} elseif ($kind === 'rel') {
					$releasedMc[] = [$i, $type];
				}
			}
		}

		$this->assertNotEmpty($acquiredMc, $method . ' must acquire azc/mc/ month-closure locks');
		foreach ($acquiredMc as [$i, $type]) {
			$this->assertSame(ILockingProvider::LOCK_EXCLUSIVE, $type, $method . ': mc lock must be EXCLUSIVE');
			$this->assertNotNull($txIndex, $method . ': no transaction started');
			$this->assertLessThan($txIndex, $i, $method . ': mc locks must be acquired BEFORE beginTransaction (oc_file_locks writes are tx-scoped)');
		}
		$this->assertCount(count($acquiredMc), $releasedMc, $method . ': every mc lock must be released');
		foreach ($releasedMc as [$i, $type]) {
			$this->assertSame(ILockingProvider::LOCK_EXCLUSIVE, $type);
		}
	}

	/** @return array<string, array{0:string}> */
	public function monthClosureLockWritePaths(): array
	{
		return [
			'updateAbsence' => ['updateAbsence'],
			'deleteAbsence' => ['deleteAbsence'],
			'cancelAbsence' => ['cancelAbsence'],
			'shortenAbsence' => ['shortenAbsence'],
			'managerShortenAbsence' => ['managerShortenAbsence'],
			'approveBySubstitute' => ['approveBySubstitute'],
			'declineBySubstitute' => ['declineBySubstitute'],
		];
	}

	private function mkLockAbsence(string $status, string $type = Absence::TYPE_SICK_LEAVE): Absence
	{
		$a = new Absence();
		$a->setId(123);
		$a->setUserId('testuser');
		$a->setType($type);
		$a->setStatus($status);
		$a->setStartDate(new \DateTime('2026-06-10'));
		$a->setEndDate(new \DateTime('2026-06-12'));
		$a->setDays(3.0);
		return $a;
	}

	private function fixtureMonthClosureLockPath(string $method): void
	{
		switch ($method) {
			case 'updateAbsence':
				$this->absenceMapper->method('find')->willReturn($this->mkLockAbsence(Absence::STATUS_PENDING));
				$this->absenceMapper->method('findOverlapping')->willReturn([]);
				$this->absenceMapper->method('update')->willReturnArgument(0);
				$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
				break;
			case 'deleteAbsence':
				$this->absenceMapper->method('find')->willReturn($this->mkLockAbsence(Absence::STATUS_PENDING));
				$this->absenceMapper->method('delete');
				break;
			case 'cancelAbsence':
				$a = $this->mkLockAbsence(Absence::STATUS_PENDING);
				$a->setStartDate((new \DateTime())->modify('+7 days'));
				$a->setEndDate((new \DateTime())->modify('+9 days'));
				$this->absenceMapper->method('find')->willReturn($a);
				$this->absenceMapper->method('update')->willReturnArgument(0);
				break;
			case 'shortenAbsence':
				$a = $this->mkLockAbsence(Absence::STATUS_APPROVED);
				$a->setStartDate((new \DateTime())->modify('-3 days'));
				$a->setEndDate((new \DateTime())->modify('+3 days'));
				$this->absenceMapper->method('find')->willReturn($a);
				$this->absenceMapper->method('update')->willReturnArgument(0);
				break;
			case 'managerShortenAbsence':
				$a = $this->mkLockAbsence(Absence::STATUS_APPROVED);
				$a->setStartDate((new \DateTime())->modify('-3 days'));
				$a->setEndDate((new \DateTime())->modify('+3 days'));
				$a->setUpdatedAt(new \DateTime('-1 day'));
				$this->absenceMapper->method('find')->willReturn($a);
				$this->absenceMapper->method('update')->willReturnArgument(0);
				break;
			case 'approveBySubstitute':
				$a = $this->mkLockAbsence(Absence::STATUS_SUBSTITUTE_PENDING);
				$a->setSubstituteUserId('sub1');
				$this->absenceMapper->method('find')->willReturn($a);
				$this->absenceMapper->method('update')->willReturnArgument(0);
				$this->teamResolver->method('hasAssignableManagerForEmployee')->willReturn(true);
				break;
			case 'declineBySubstitute':
				$a = $this->mkLockAbsence(Absence::STATUS_SUBSTITUTE_PENDING);
				$a->setSubstituteUserId('sub1');
				$this->absenceMapper->method('find')->willReturn($a);
				$this->absenceMapper->method('update')->willReturnArgument(0);
				break;
			default:
				$this->fail('unknown path ' . $method);
		}
	}

	private function invokeMonthClosureLockPath(AbsenceService $service, string $method): void
	{
		switch ($method) {
			case 'updateAbsence':
				$service->updateAbsence(123, ['reason' => 'changed'], 'testuser');
				break;
			case 'deleteAbsence':
				$service->deleteAbsence(123, 'testuser');
				break;
			case 'cancelAbsence':
				$service->cancelAbsence(123, 'testuser');
				break;
			case 'shortenAbsence':
				$service->shortenAbsence(123, 'testuser', (new \DateTime())->format('Y-m-d'));
				break;
			case 'managerShortenAbsence':
				// CAS token must match the fixture's updatedAt (set -1 day).
				$a = $this->absenceMapper->find(123);
				$service->managerShortenAbsence(123, [
					'end_date' => (new \DateTime())->format('Y-m-d'),
					'expectedUpdatedAt' => $a->getUpdatedAt()?->format('c') ?? '',
					'reason' => 'Employee returned early',
				], 'manager1');
				break;
			case 'approveBySubstitute':
				$service->approveBySubstitute(123, 'sub1');
				break;
			case 'declineBySubstitute':
				$service->declineBySubstitute(123, 'sub1', 'no coverage needed');
				break;
		}
	}

	/**
	 * A finalize in flight on one of the window's months must surface as a
	 * retryable busy error (AbsenceMutationBusyException / LOCKED) — never
	 * a write into the sealing month.
	 */
	public function testDeleteAbsenceMapsMonthClosureLockContentionToBusy(): void
	{
		$absence = new Absence();
		$absence->setId(7);
		$absence->setUserId('testuser');
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setStartDate(new \DateTime('2026-06-10'));
		$absence->setEndDate(new \DateTime('2026-06-12'));

		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->expects($this->never())->method('delete');

		$this->monthClosureService->method('monthsOverlappingRange')->willReturn([[2026, 6]]);
		$this->monthClosureService->method('assertDateRangeMutable');

		$lp = $this->createMock(ILockingProvider::class);
		$lp->method('acquireLock')->willReturnCallback(
			static function (string $key, int $type): void {
				if (str_starts_with($key, 'azc/mc/')) {
					throw new \OCP\Lock\LockedException($key);
				}
			}
		);
		$lp->method('releaseLock');

		$service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$lp,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService
		);

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\AbsenceMutationBusyException::class);
		$service->deleteAbsence(7, 'testuser');
	}

	/* ------------------------------------------------------------------
	 * FA-MSHORTEN (US-407, absence-day-accuracy-pack-2026-11):
	 * managerShortenAbsence — mirror of owner rules + CAS + reason + audit.
	 * ----------------------------------------------------------------- */

	private function managerShortenData(Absence $a, string $newEnd, array $extra = []): array
	{
		return array_merge([
			'end_date' => (new \DateTime($newEnd))->format('Y-m-d'),
			'expectedUpdatedAt' => $this->casToken($a),
			'reason' => 'Employee returned early',
		], $extra);
	}

	/**
	 * Service variant carrying the optional collaborators the shorten path
	 * exercises (entitlement adjust, HR mail, schedule-aware counter).
	 */
	private function serviceWithExtras(
		?SpecialEntitlementService $entitlements = null,
		?AbsenceNotificationMailService $mail = null,
		?\OCA\ArbeitszeitCheck\Support\ScheduledWorkdayCounter $counter = null,
	): AbsenceService {
		return new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$this->lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			$mail,
			$this->monthClosureService,
			null, null, null, null, null, null,
			$entitlements,
			null, null,
			$counter
		);
	}

	/**
	 * Real ScheduledWorkdayCounter (final — cannot be doubled) whose model
	 * resolution is driven by the mocked mappers. $work maps mon..sun.
	 * @param array<string,bool>|null $work null → no assignment (fallback path)
	 */
	private function realScheduleCounter(?array $work): \OCA\ArbeitszeitCheck\Support\ScheduledWorkdayCounter
	{
		$modelMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class);
		if ($work === null) {
			$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		} else {
			$days = [];
			foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
				$days[$d] = ($work[$d] ?? false)
					? ['work' => true, 'start' => '08:00', 'end' => '16:00', 'breaks' => []]
					: ['work' => false];
			}
			$model = new \OCA\ArbeitszeitCheck\Db\WorkingTimeModel();
			$model->setBreakRulesArray([
				\OCA\ArbeitszeitCheck\Support\WeekdaySchedule::KEY => ['days' => $days],
			]);
			$assignment = new \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel();
			$assignment->setWorkingTimeModelId(7);
			$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn($assignment);
			$modelMapper->method('find')->with(7)->willReturn($model);
		}
		return new \OCA\ArbeitszeitCheck\Support\ScheduledWorkdayCounter(
			$this->userWorkingTimeModelMapper,
			$modelMapper,
			$this->holidayCalendarService
		);
	}

	public function testManagerShortenAbsenceHappyPathRecomputesDaysAuditsAndNotifies(): void
	{
		$absence = $this->runningVacation();
		$newEnd = (new \DateTime())->format('Y-m-d');

		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnArgument(0);
		$this->monthClosureService->method('monthsOverlappingRange')->willReturn([[2026, 6]]);
		$this->holidayCalendarService->method('getHolidayWeightMapForUser')->willReturn([]);

		// MH-01: days must come from the schedule-aware authority — a real
		// counter on a Mo–Thu schedule, never the legacy Mon–Fri mock.
		$counter = $this->realScheduleCounter(['mon' => true, 'tue' => true, 'wed' => true, 'thu' => true]);
		$expectedDays = $counter->daysForUser(
			'alice', $absence->getStartDate(), new \DateTime($newEnd)
		);

		// SpecialEntitlementService is final — build it without the
		// constructor and inject the reservations mapper (same pattern as
		// testShortenAbsenceAdjustsConsumedSpecialEntitlement). The early
		// return on "no reservation" still proves the adjust call ran with
		// the right absence id.
		$reservations = $this->createMock(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class);
		$reservations->expects($this->once())->method('findByAbsenceId')->with(42)->willReturn(null);
		$entitlements = (new \ReflectionClass(SpecialEntitlementService::class))->newInstanceWithoutConstructor();
		\Closure::bind(function (object $r): void {
			$this->reservations = $r;
		}, $entitlements, SpecialEntitlementService::class)($reservations);

		$mail = $this->createMock(AbsenceNotificationMailService::class);
		$mail->expects($this->once())->method('sendHrOfficeNotification')
			->with($absence, 'manager_shortened', 'manager1');

		$audits = [];
		$this->auditLogMapper->method('logAction')->willReturnCallback(
			function ($u, $action, $et, $eid, $old, $new, $performedBy) use (&$audits) {
				$audits[] = [$action, $performedBy, $new['shorten_reason'] ?? null];
				return new \OCA\ArbeitszeitCheck\Db\AuditLog();
			}
		);
		$this->notificationService->expects($this->once())
			->method('notifyAbsenceShortenedByManager')
			->with('alice', $this->isType('array'), 'Employee returned early');

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$service = $this->serviceWithExtras($entitlements, $mail, $counter);
		$out = $service->managerShortenAbsence(42, $this->managerShortenData($absence, $newEnd), 'manager1');

		$this->assertSame($newEnd, $out->getEndDate()->format('Y-m-d'));
		// Schedule-aware value, computed by the real counter — proves the
		// write went through DC-01 rather than the mocked 2.0 legacy count.
		$this->assertSame($expectedDays, $out->getDays());
		$this->assertSame(
			[['absence_manager_shortened', 'manager1', 'Employee returned early']],
			$audits
		);
	}

	public function testManagerShortenAbsenceRejectsNonApprovedStatuses(): void
	{
		foreach ([Absence::STATUS_PENDING, Absence::STATUS_CANCELLED, Absence::STATUS_REJECTED] as $status) {
			$absence = $this->runningVacation();
			$absence->setStatus($status);
			$this->absenceMapper = $this->createMock(AbsenceMapper::class);
			$this->absenceMapper->method('find')->willReturn($absence);
			$service = $this->serviceWithExtras();
			try {
				$service->managerShortenAbsence(42, $this->managerShortenData($absence, 'now'), 'manager1');
				$this->fail('expected not_approved for status ' . $status);
			} catch (\Exception $e) {
				$this->assertSame('not_approved', $e->getMessage());
			}
		}
	}

	public function testManagerShortenAbsenceRejectsNotStarted(): void
	{
		$absence = $this->runningVacation();
		$absence->setStartDate(new \DateTime('+2 days'));
		$absence->setEndDate(new \DateTime('+9 days'));
		$this->absenceMapper->method('find')->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('not_started');
		$this->serviceWithExtras()->managerShortenAbsence(42, $this->managerShortenData($absence, '+3 days'), 'manager1');
	}

	public function testManagerShortenAbsenceRejectsAlreadyEnded(): void
	{
		// Storage TZ is Europe/Berlin in this environment — anchor "today"
		// there so the calendar-date guard is exercised deterministically.
		$storageTz = new \DateTimeZone(\OCA\ArbeitszeitCheck\Service\TimeZoneService::DEFAULT_STORAGE_TZ);
		foreach (['today', 'yesterday'] as $end) {
			$absence = $this->runningVacation();
			$absence->setEndDate(new \DateTime($end, $storageTz));
			$this->absenceMapper = $this->createMock(AbsenceMapper::class);
			$this->absenceMapper->method('find')->willReturn($absence);
			$this->absenceMapper->expects($this->never())->method('update');
			$service = $this->serviceWithExtras();
			try {
				$service->managerShortenAbsence(42, $this->managerShortenData($absence, '-2 days'), 'manager1');
				$this->fail('expected already_ended for end ' . $end);
			} catch (\Exception $e) {
				$this->assertSame('already_ended', $e->getMessage());
			}
		}
	}

	public function testManagerShortenAbsenceRequiresReason(): void
	{
		foreach (['', '   ', str_repeat('x', 501)] as $reason) {
			$absence = $this->runningVacation();
			$this->absenceMapper = $this->createMock(AbsenceMapper::class);
			$this->absenceMapper->method('find')->willReturn($absence);
			$service = $this->serviceWithExtras();
			try {
				$service->managerShortenAbsence(
					42,
					$this->managerShortenData($absence, 'now', ['reason' => $reason]),
					'manager1'
				);
				$this->fail('expected reason_required');
			} catch (\Exception $e) {
				$this->assertSame('reason_required', $e->getMessage());
			}
		}
	}

	public function testManagerShortenAbsenceRejectsStaleOrMissingCas(): void
	{
		foreach (['', '2020-01-01T00:00:00+00:00'] as $token) {
			$absence = $this->runningVacation();
			$this->absenceMapper = $this->createMock(AbsenceMapper::class);
			$this->absenceMapper->method('find')->willReturn($absence);
			$this->absenceMapper->expects($this->never())->method('update');
			$service = $this->serviceWithExtras();
			try {
				$service->managerShortenAbsence(
					42,
					$this->managerShortenData($absence, 'now', ['expectedUpdatedAt' => $token]),
					'manager1'
				);
				$this->fail('expected revision_stale');
			} catch (\Exception $e) {
				$this->assertSame('revision_stale', $e->getMessage());
			}
		}
	}

	public function testManagerShortenAbsenceRejectsInvalidNewEnd(): void
	{
		$absence = $this->runningVacation();
		$this->absenceMapper->method('find')->willReturn($absence);

		// Before start → end_before_start
		try {
			$this->serviceWithExtras()->managerShortenAbsence(
				42, $this->managerShortenData($absence, '-10 days'), 'manager1'
			);
			$this->fail('expected end_before_start');
		} catch (\Exception $e) {
			$this->assertSame('end_before_start', $e->getMessage());
		}

		// Equal to original end → end_not_earlier (no-op writes are rejected)
		try {
			$this->serviceWithExtras()->managerShortenAbsence(
				42,
				$this->managerShortenData($absence, $absence->getEndDate()->format('Y-m-d')),
				'manager1'
			);
			$this->fail('expected end_not_earlier');
		} catch (\Exception $e) {
			$this->assertSame('end_not_earlier', $e->getMessage());
		}

		// After original end → end_not_earlier (shorten never extends)
		try {
			$this->serviceWithExtras()->managerShortenAbsence(
				42, $this->managerShortenData($absence, '+30 days'), 'manager1'
			);
			$this->fail('expected end_not_earlier');
		} catch (\Exception $e) {
			$this->assertSame('end_not_earlier', $e->getMessage());
		}
	}

	/**
	 * FM-05: an open owner cancellation request wins — the manager must
	 * decide it (FA-2c), not silently shorten around it. The write must
	 * roll back, never partially apply.
	 */
	public function testManagerShortenAbsenceRejectsOpenCancelRequest(): void
	{
		$absence = $this->runningVacation();
		$absence->setCancelRequestedAt(new \DateTime('now'));
		$absence->setCancelRequestedBy('alice');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->expects($this->never())->method('update');
		$this->monthClosureService->method('monthsOverlappingRange')->willReturn([[2026, 6]]);
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('cancel_request_pending');
		$this->serviceWithExtras()->managerShortenAbsence(
			42, $this->managerShortenData($absence, 'now'), 'manager1'
		);
	}

	/**
	 * CAS re-check under the lock: a row mutated between the first read and
	 * the in-transaction re-read must still fail closed (FM-06/TH-03).
	 */
	public function testManagerShortenAbsenceRechecksCasUnderLock(): void
	{
		$original = $this->runningVacation();
		$drifted = $this->runningVacation();
		$drifted->setUpdatedAt(new \DateTime('-1 hour')); // ≠ the token read outside
		$this->absenceMapper->method('find')
			->willReturnOnConsecutiveCalls($original, $drifted);
		$this->absenceMapper->expects($this->never())->method('update');
		$this->monthClosureService->method('monthsOverlappingRange')->willReturn([[2026, 6]]);
		$this->db->expects($this->never())->method('commit');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('revision_stale');
		$this->serviceWithExtras()->managerShortenAbsence(
			42, $this->managerShortenData($original, 'now'), 'manager1'
		);
	}

	/* ------------------------------------------------------------------
	 * FA-PREVIEW (US-406/408): estimateAbsenceDays — server-authoritative
	 * day estimate with basis disclosure.
	 * ----------------------------------------------------------------- */

	public function testEstimateAbsenceDaysUsesScheduleCounterAndReportsBasis(): void
	{
		$this->holidayCalendarService->method('getHolidayWeightMapForUser')->willReturn([]);
		// Mo–Thu schedule over Mo 2026-11-16 → Fr 2026-11-20 = exactly 4 days.
		$counter = $this->realScheduleCounter(['mon' => true, 'tue' => true, 'wed' => true, 'thu' => true]);

		$out = $this->serviceWithExtras(null, null, $counter)
			->estimateAbsenceDays('alice', new \DateTime('2026-11-16'), new \DateTime('2026-11-20'));

		$this->assertSame(4.0, $out['days']);
		$this->assertSame('weekday_schedule', $out['basis']);
		$this->assertFalse($out['single_day']);
	}

	public function testEstimateAbsenceDaysFallsBackToMonFriWithoutCounter(): void
	{
		// No counter injected → holidayCalendarService mock returns 2.0.
		$out = $this->serviceWithExtras()
			->estimateAbsenceDays('alice', new \DateTime('2026-11-16'), new \DateTime('2026-11-16'));

		$this->assertSame(2.0, $out['days']);
		$this->assertSame('weekdays_mon_fri', $out['basis']);
		$this->assertTrue($out['single_day']);
	}

	public function testEstimateAbsenceDaysFallsBackToMonFriWithoutSchedule(): void
	{
		// Counter injected but the user has no model → documented Mon–Fri
		// fallback (E6/E12), never a 404.
		$counter = $this->realScheduleCounter(null);
		$out = $this->serviceWithExtras(null, null, $counter)
			->estimateAbsenceDays('alice', new \DateTime('2026-11-16'), new \DateTime('2026-11-20'));

		$this->assertSame(2.0, $out['days']);
		$this->assertSame('weekdays_mon_fri', $out['basis']);
	}

	/* ------------------------------------------------------------------
	 * Owner/manager action flags — eligibility must be server-computed
	 * (ADR-04/ADR-06), including the NH-05 owner canShorten flag.
	 * ----------------------------------------------------------------- */

	public function testOwnerAbsenceActionFlagsMatrix(): void
	{
		// Running approved absence ending after today → all running flags on.
		$running = $this->runningVacation();
		$flags = $this->service->ownerAbsenceActionFlags($running);
		$this->assertTrue($flags['canShorten']);
		$this->assertTrue($flags['canConvertToSick']);
		$this->assertTrue($flags['canRequestCancel']);

		// Ends today → no shorten possible (E5); still convertible/cancellable.
		$storageTz = new \DateTimeZone(\OCA\ArbeitszeitCheck\Service\TimeZoneService::DEFAULT_STORAGE_TZ);
		$endsToday = $this->runningVacation();
		$endsToday->setEndDate(new \DateTime('today', $storageTz));
		$flags = $this->service->ownerAbsenceActionFlags($endsToday);
		$this->assertFalse($flags['canShorten']);

		// Future absence → nothing running-scoped is allowed.
		$future = $this->makeAbsenceRow(7, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '+5 days', '+9 days');
		$flags = $this->service->ownerAbsenceActionFlags($future);
		$this->assertFalse($flags['canShorten']);
		$this->assertFalse($flags['canConvertToSick']);
		$this->assertFalse($flags['canRequestCancel']);

		// Pending → owner mutations stay hidden.
		$pending = $this->runningVacation();
		$pending->setStatus(Absence::STATUS_PENDING);
		$flags = $this->service->ownerAbsenceActionFlags($pending);
		$this->assertFalse($flags['canShorten']);
		$this->assertFalse($flags['canRequestCancel']);

		// Open cancel request → canRequestCancel closes, shorten stays legal.
		$requested = $this->runningVacation();
		$requested->setCancelRequestedAt(new \DateTime('now'));
		$flags = $this->service->ownerAbsenceActionFlags($requested);
		$this->assertFalse($flags['canRequestCancel']);
		$this->assertTrue($flags['canShorten']);
	}

	public function testManagerAbsenceActionFlagsShortenWindow(): void
	{
		$running = $this->runningVacation();
		$flags = $this->service->managerAbsenceActionFlags($running);
		$this->assertTrue($flags['canManagerShorten']);
		$this->assertTrue($flags['canManagerCancel']);

		// Ends today → shorten impossible (US-407 AC-3); cancel still allowed.
		$storageTz = new \DateTimeZone(\OCA\ArbeitszeitCheck\Service\TimeZoneService::DEFAULT_STORAGE_TZ);
		$endsToday = $this->runningVacation();
		$endsToday->setEndDate(new \DateTime('today', $storageTz));
		$flags = $this->service->managerAbsenceActionFlags($endsToday);
		$this->assertFalse($flags['canManagerShorten']);
		$this->assertTrue($flags['canManagerCancel']);

		// Not yet started → neither shorten nor a mid-run cancel flag.
		$future = $this->makeAbsenceRow(7, 'alice', Absence::STATUS_APPROVED, Absence::TYPE_VACATION, '+5 days', '+9 days');
		$flags = $this->service->managerAbsenceActionFlags($future);
		$this->assertFalse($flags['canManagerShorten']);
		$this->assertTrue($flags['canManagerCancel']); // future approved stays cancellable

		// Pending → manager mutations hidden.
		$pending = $this->runningVacation();
		$pending->setStatus(Absence::STATUS_PENDING);
		$flags = $this->service->managerAbsenceActionFlags($pending);
		$this->assertFalse($flags['canManagerShorten']);
		$this->assertFalse($flags['canManagerCancel']);
	}

}