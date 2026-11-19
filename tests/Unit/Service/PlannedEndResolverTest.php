<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\PlannedEndResolver;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\LaborLawProfile;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PlannedEndResolverTest extends TestCase
{
	private const TZ = 'Europe/Berlin';
	private const USER = 'alice';
	private const MODEL_ID = 42;

	private $userWorkingTimeModelMapper;
	private $workingTimeModelMapper;
	private $absenceMapper;
	private $holidayService;
	private BreakPolicyResolver $breakPolicyResolver;
	private TimeZoneService $timeZoneService;
	private PlannedEndResolver $resolver;

	protected function setUp(): void
	{
		parent::setUp();
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->holidayService = $this->createMock(\OCA\ArbeitszeitCheck\Service\HolidayService::class);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'app_timezone' => self::TZ,
			default => $default,
		});
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone(self::TZ));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$this->timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());

		// BreakPolicyResolver is final — build the real thing on mocked
		// collaborators. The profile grants 45 break minutes beyond 6 net
		// hours so 8h → 45min (the spec's worked example).
		$lawProfileFactory = $this->createMock(LaborLawProfileFactory::class);
		$lawProfileFactory->method('getProfile')->willReturn(new LaborLawProfile(
			'DE', 8.0, 11.0,
			[['afterHours' => 6.0, 'breakMinutes' => 45]],
			null, 6, null, null, 22, 6, [], 20, 0
		));
		$this->breakPolicyResolver = new BreakPolicyResolver(
			$config,
			$lawProfileFactory,
			$this->createMock(UserSettingsMapper::class),
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->createMock(TimeEntryMapper::class),
			$this->timeZoneService,
			$this->createMock(IL10N::class)
		);

		$this->resolver = new PlannedEndResolver(
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->absenceMapper,
			$this->holidayService,
			$this->breakPolicyResolver,
			$this->timeZoneService
		);
	}

	private function entry(string $startYmdHis): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setUserId(self::USER);
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime($startYmdHis, new \DateTimeZone(self::TZ)));
		return $entry;
	}

	private function assignment(): UserWorkingTimeModel
	{
		$a = new UserWorkingTimeModel();
		$a->setUserId(self::USER);
		$a->setWorkingTimeModelId(self::MODEL_ID);
		return $a;
	}

	private function scheduleModel(): WorkingTimeModel
	{
		$model = new WorkingTimeModel();
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setDailyHours(8.0);
		$model->setBreakRules((string)json_encode([
			WeekdaySchedule::KEY => WeekdaySchedule::fromValidated(WeekdaySchedule::banssPreset())->toArray(),
		]));
		return $model;
	}

	private function flexibleModel(): WorkingTimeModel
	{
		$model = new WorkingTimeModel();
		$model->setType(WorkingTimeModel::TYPE_FLEXIBLE);
		$model->setDailyHours(8.0);
		$model->setBreakRules(null);
		return $model;
	}

	/** @var list<Absence> absences the mapper reports for the current test */
	private array $absences = [];

	private function wireModel(?WorkingTimeModel $model): void
	{
		$this->userWorkingTimeModelMapper->method('findByUserAndDate')->willReturn($this->assignment());
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn($this->assignment());
		$this->workingTimeModelMapper->method('find')->willReturnCallback(static function () use ($model) {
			if ($model === null) {
				throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
			}
			return $model;
		});
		$this->holidayService->method('isHolidayForUser')->willReturn(false);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturnCallback(fn () => $this->absences);
	}

	/** 2026-11-02 is a Monday. Cutover far in the future so it never vetoes. */
	private function cutover(): \DateTime
	{
		return new \DateTime('2026-11-02 22:00:00', new \DateTimeZone(self::TZ));
	}

	public function testFixedScheduleEndWins(): void
	{
		$this->wireModel($this->scheduleModel());
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertNull($result['skip']);
		$this->assertSame('2026-11-02 16:15', $result['end']->format('Y-m-d H:i'));
	}

	public function testFridayEarlyScheduleEnd(): void
	{
		$this->wireModel($this->scheduleModel());
		$cache = [];
		// 2026-11-06 is a Friday: banss preset ends 11:45.
		$cutover = new \DateTime('2026-11-06 22:00:00', new \DateTimeZone(self::TZ));
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-06 07:30:00'), $cutover, $cache);
		$this->assertNull($result['skip']);
		$this->assertSame('2026-11-06 11:45', $result['end']->format('Y-m-d H:i'));
	}

	public function testFlexibleModelAddsRequiredBreak(): void
	{
		$this->wireModel($this->flexibleModel());
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertNull($result['skip']);
		// 08:00 + 8h net + 45min mandatory break = 16:45.
		$this->assertSame('2026-11-02 16:45', $result['end']->format('Y-m-d H:i'));
	}

	public function testNoAssignmentSkips(): void
	{
		$this->userWorkingTimeModelMapper->method('findByUserAndDate')->willReturn(null);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertSame(PlannedEndResolver::SKIP_NO_MODEL, $result['skip']);
		$this->assertNull($result['end']);
	}

	public function testDeletedModelSkips(): void
	{
		$this->wireModel(null);
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertSame(PlannedEndResolver::SKIP_NO_MODEL, $result['skip']);
		// The miss must be memoised for co-assignees in the same run.
		$this->assertArrayHasKey(self::MODEL_ID, $cache);
		$this->assertNull($cache[self::MODEL_ID]);
	}

	public function testOnDemandModelSkips(): void
	{
		$model = new WorkingTimeModel();
		$model->setType(WorkingTimeModel::TYPE_ON_DEMAND);
		$model->setDailyHours(0.0);
		$this->wireModel($model);
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertSame(PlannedEndResolver::SKIP_ON_DEMAND_MODEL, $result['skip']);
	}

	public function testHolidaySkips(): void
	{
		$this->wireModel($this->scheduleModel());
		$this->holidayService = $this->createMock(\OCA\ArbeitszeitCheck\Service\HolidayService::class);
		$this->holidayService->method('isHolidayForUser')->willReturn(true);
		$resolver = new PlannedEndResolver(
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->absenceMapper,
			$this->holidayService,
			$this->breakPolicyResolver,
			$this->timeZoneService
		);
		$cache = [];
		$result = $resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertSame(PlannedEndResolver::SKIP_HOLIDAY, $result['skip']);
	}

	public function testApprovedAbsenceSkipsButPendingDoesNot(): void
	{
		$this->wireModel($this->scheduleModel());
		$approved = new Absence();
		$approved->setStatus(Absence::STATUS_APPROVED);
		$this->absences = [$approved];
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertSame(PlannedEndResolver::SKIP_ABSENCE_DAY, $result['skip']);

		// A merely pending request does NOT veto — the person may still be working.
		$pending = new Absence();
		$pending->setStatus(Absence::STATUS_PENDING);
		$this->absences = [$pending];
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertNull($result['skip']);
	}

	public function testApprovedAbsenceStartingTomorrowDoesNotVetoToday(): void
	{
		// The shared $this->absences stub ignores the range it is handed, so
		// it cannot prove the lookup window is right. This stub reproduces
		// AbsenceMapper::findByUserAndDateRange SQL semantics (date-granular
		// inclusive overlap) — then hands the resolver a range that must be
		// the ENTRY's day only: approved leave starting tomorrow must not
		// veto today's closure.
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$leave = new Absence();
		$leave->setStatus(Absence::STATUS_APPROVED);
		$leave->setStartDate(new \DateTime('2026-11-03', new \DateTimeZone(self::TZ)));
		$leave->setEndDate(new \DateTime('2026-11-05', new \DateTimeZone(self::TZ)));
		$absenceMapper->method('findByUserAndDateRange')->willReturnCallback(
			static function (string $userId, \DateTime $from, \DateTime $to) use ($leave): array {
				return $leave->getStartDate()->format('Y-m-d') <= $to->format('Y-m-d')
					&& $leave->getEndDate()->format('Y-m-d') >= $from->format('Y-m-d')
					? [$leave]
					: [];
			}
		);
		$resolver = new PlannedEndResolver(
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$absenceMapper,
			$this->holidayService,
			$this->breakPolicyResolver,
			$this->timeZoneService
		);
		$this->userWorkingTimeModelMapper->method('findByUserAndDate')->willReturn($this->assignment());
		$this->workingTimeModelMapper->method('find')->willReturn($this->scheduleModel());
		$this->holidayService->method('isHolidayForUser')->willReturn(false);

		$cache = [];
		$result = $resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertNull($result['skip']);
		$this->assertSame('2026-11-02 16:15', $result['end']->format('Y-m-d H:i'));
	}

	public function testApprovedAbsenceEndingYesterdayDoesNotVetoToday(): void
	{
		// Mirror boundary on the left: leave ENDED yesterday — today is a
		// normal work day and must resolve.
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$leave = new Absence();
		$leave->setStatus(Absence::STATUS_APPROVED);
		$leave->setStartDate(new \DateTime('2026-10-30', new \DateTimeZone(self::TZ)));
		$leave->setEndDate(new \DateTime('2026-11-01', new \DateTimeZone(self::TZ)));
		$absenceMapper->method('findByUserAndDateRange')->willReturnCallback(
			static function (string $userId, \DateTime $from, \DateTime $to) use ($leave): array {
				return $leave->getStartDate()->format('Y-m-d') <= $to->format('Y-m-d')
					&& $leave->getEndDate()->format('Y-m-d') >= $from->format('Y-m-d')
					? [$leave]
					: [];
			}
		);
		$resolver = new PlannedEndResolver(
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$absenceMapper,
			$this->holidayService,
			$this->breakPolicyResolver,
			$this->timeZoneService
		);
		$this->userWorkingTimeModelMapper->method('findByUserAndDate')->willReturn($this->assignment());
		$this->workingTimeModelMapper->method('find')->willReturn($this->scheduleModel());
		$this->holidayService->method('isHolidayForUser')->willReturn(false);

		$cache = [];
		$result = $resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertNull($result['skip']);
	}

	public function testWeekendRowSkips(): void
	{
		$this->wireModel($this->scheduleModel());
		$cache = [];
		// 2026-11-07 is a Saturday — banss has no sat work row.
		$cutover = new \DateTime('2026-11-07 22:00:00', new \DateTimeZone(self::TZ));
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-07 09:00:00'), $cutover, $cache);
		$this->assertSame(PlannedEndResolver::SKIP_NO_PLANNED_END, $result['skip']);
	}

	public function testFlexibleModelOnWeekendSkips(): void
	{
		$this->wireModel($this->flexibleModel());
		$cache = [];
		$cutover = new \DateTime('2026-11-08 22:00:00', new \DateTimeZone(self::TZ));
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-08 09:00:00'), $cutover, $cache);
		$this->assertSame(PlannedEndResolver::SKIP_NO_PLANNED_END, $result['skip']);
	}

	public function testPlannedEndAfterCutoverSkips(): void
	{
		$this->wireModel($this->scheduleModel());
		$cache = [];
		// Scheduled end 16:15 but cutover is 12:00 — the run must wait.
		$earlyCutover = new \DateTime('2026-11-02 12:00:00', new \DateTimeZone(self::TZ));
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $earlyCutover, $cache);
		$this->assertSame(PlannedEndResolver::SKIP_PLANNED_END_AFTER_CUTOVER, $result['skip']);
	}

	public function testMissingStartSkips(): void
	{
		$entry = new TimeEntry();
		$entry->setUserId(self::USER);
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$this->wireModel($this->scheduleModel());
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $entry, $this->cutover(), $cache);
		$this->assertSame(PlannedEndResolver::SKIP_MISSING_START, $result['skip']);
	}

	public function testPlannedEndEqualToStartIsInvalid(): void
	{
		// Fixed schedule whose last block ends exactly when the entry
		// started — a zero-length "work day" must never be written.
		$model = new WorkingTimeModel();
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setDailyHours(8.0);
		$day = ['work' => true, 'start' => '07:30', 'end' => '09:00', 'breaks' => []];
		$model->setBreakRules((string)json_encode([
			WeekdaySchedule::KEY => WeekdaySchedule::fromValidated([
				'version' => WeekdaySchedule::VERSION,
				'days' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], $day),
			])->toArray(),
		]));
		$this->wireModel($model);
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 09:00:00'), $this->cutover(), $cache);
		$this->assertSame(PlannedEndResolver::SKIP_INVALID_PLANNED_END, $result['skip']);
	}

	public function testPlannedEndExactlyAtCutoverResolves(): void
	{
		// Planned end == cutover means the end has already been reached —
		// only a strictly-later end must wait for a later run.
		$this->wireModel($this->scheduleModel());
		$cache = [];
		$exactCutover = new \DateTime('2026-11-02 16:15:00', new \DateTimeZone(self::TZ));
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $exactCutover, $cache);
		$this->assertNull($result['skip']);
		$this->assertSame('2026-11-02 16:15', $result['end']->format('Y-m-d H:i'));
	}

	public function testExpiredAssignmentFallsBackToCurrentModel(): void
	{
		// No assignment valid on the entry day, but a current one exists —
		// the resolver falls back rather than guessing "no model".
		$this->userWorkingTimeModelMapper->method('findByUserAndDate')->willReturn(null);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn($this->assignment());
		$this->workingTimeModelMapper->method('find')->willReturn($this->scheduleModel());
		$this->holidayService->method('isHolidayForUser')->willReturn(false);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([]);
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertNull($result['skip']);
		$this->assertSame('2026-11-02 16:15', $result['end']->format('Y-m-d H:i'));
	}

	public function testDeletedModelMissIsCachedForCoAssignees(): void
	{
		// A shared deleted model must cost one find() per RUN, not per user —
		// the cached null (array_key_exists, not isset) is the memo.
		$this->wireModel(null);
		$this->workingTimeModelMapper->expects($this->once())->method('find');
		$cache = [];
		$resultA = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$resultB = $this->resolver->resolve('bob', $this->entry('2026-11-02 08:30:00'), $this->cutover(), $cache);
		$this->assertSame(PlannedEndResolver::SKIP_NO_MODEL, $resultA['skip']);
		$this->assertSame(PlannedEndResolver::SKIP_NO_MODEL, $resultB['skip']);
	}

	public function testFlexibleModelOnSaturdaySkips(): void
	{
		// 2026-11-07 is a Saturday — flexible models only plan Mon–Fri.
		$this->wireModel($this->flexibleModel());
		$cache = [];
		$cutover = new \DateTime('2026-11-07 22:00:00', new \DateTimeZone(self::TZ));
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-07 09:00:00'), $cutover, $cache);
		$this->assertSame(PlannedEndResolver::SKIP_NO_PLANNED_END, $result['skip']);
	}

	public function testZeroDailyHoursSkips(): void
	{
		$model = new WorkingTimeModel();
		$model->setType(WorkingTimeModel::TYPE_FLEXIBLE);
		$model->setDailyHours(0.0);
		$model->setBreakRules(null);
		$this->wireModel($model);
		$cache = [];
		$result = $this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->assertSame(PlannedEndResolver::SKIP_NO_PLANNED_END, $result['skip']);
	}

	public function testModelCacheIsSharedAcrossUsers(): void
	{
		$this->wireModel($this->scheduleModel());
		$this->workingTimeModelMapper->expects($this->once())->method('find');
		$cache = [];
		$this->resolver->resolve(self::USER, $this->entry('2026-11-02 08:00:00'), $this->cutover(), $cache);
		$this->resolver->resolve('bob', $this->entry('2026-11-02 08:30:00'), $this->cutover(), $cache);
		$this->assertInstanceOf(WorkingTimeModel::class, $cache[self::MODEL_ID]);
	}
}
