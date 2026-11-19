<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\AutoClockOutJob;
use OCA\ArbeitszeitCheck\BusinessRuleCode;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\PlannedEndResolver;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\LaborLawProfile;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AutoClockOutJobTest extends TestCase
{
	private const TZ = 'Europe/Berlin';

	private $timeEntryMapper;
	private $timeTrackingService;
	private $notificationService;
	private $userManager;
	private $config;
	private $logger;
	private $permissionService;
	private $locking;
	private $userWorkingTimeModelMapper;
	private $workingTimeModelMapper;
	private $absenceMapper;
	private $holidayService;
	private TimeZoneService $timeZoneService;

	/** @var array<string,string> app config backing store */
	private array $appConfig = [];
	private AutoClockOutJob $job;

	protected function setUp(): void
	{
		parent::setUp();
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->timeTrackingService = $this->createMock(TimeTrackingService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->holidayService = $this->createMock(HolidayService::class);

		$this->appConfig = [
			'app_timezone' => self::TZ,
			Constants::CONFIG_AUTO_CLOCK_OUT_ENABLED => '1',
			// Hour must equal the real wall-clock hour or the (real-time)
			// gate vetoes the run — derived per-test where needed.
			Constants::CONFIG_AUTO_CLOCK_OUT_HOUR => (string)(int)(new \DateTime('now', new \DateTimeZone(self::TZ)))->format('G'),
			Constants::CONFIG_AUTO_CLOCK_OUT_SCOPE => Constants::AUTO_CLOCK_OUT_SCOPE_ALL_OPEN,
		];
		$this->config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default) => $this->appConfig[$key] ?? $default
		);

		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone(self::TZ));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$this->timeZoneService = new TimeZoneService($this->config, $dateTimeZone, $userSession, new NullLogger());

		$lawProfileFactory = $this->createMock(LaborLawProfileFactory::class);
		$lawProfileFactory->method('getProfile')->willReturn(new LaborLawProfile(
			'DE', 8.0, 11.0,
			[['afterHours' => 6.0, 'breakMinutes' => 45]],
			null, 6, null, null, 22, 6, [], 20, 0
		));
		$breakPolicyResolver = new BreakPolicyResolver(
			$this->config,
			$lawProfileFactory,
			$this->createMock(UserSettingsMapper::class),
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->createMock(TimeEntryMapper::class),
			$this->timeZoneService,
			$this->createMock(IL10N::class)
		);
		$resolver = new PlannedEndResolver(
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->absenceMapper,
			$this->holidayService,
			$breakPolicyResolver,
			$this->timeZoneService
		);

		$this->job = new AutoClockOutJob(
			$this->createMock(ITimeFactory::class),
			$this->timeEntryMapper,
			$this->timeTrackingService,
			$resolver,
			$this->notificationService,
			$this->userManager,
			$this->config,
			$this->logger,
			$this->permissionService,
			$this->timeZoneService,
			$this->locking
		);
	}

	private function runJob(): void
	{
		$m = new \ReflectionMethod($this->job, 'run');
		$m->invoke($this->job, null);
	}

	private function user(string $uid, bool $enabled = true): IUser
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	private function withUsers(IUser ...$users): void
	{
		$this->userManager->method('callForAllUsers')
			->willReturnCallback(static function (callable $cb) use ($users) {
				foreach ($users as $u) {
					$cb($u);
				}
			});
	}

	private function openEntry(int $id, string $userId, string $startYmdHis): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setId($id);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime($startYmdHis, new \DateTimeZone(self::TZ)));
		return $entry;
	}

	/** Every weekday is a work day 08:00–16:00 — wall-clock independent. */
	private function wireEverydayModel(): void
	{
		$day = ['work' => true, 'start' => '08:00', 'end' => '16:00', 'breaks' => []];
		$model = new WorkingTimeModel();
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setDailyHours(8.0);
		$model->setBreakRules((string)json_encode([
			WeekdaySchedule::KEY => WeekdaySchedule::fromValidated([
				'version' => WeekdaySchedule::VERSION,
				'days' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $day),
			])->toArray(),
		]));

		$assignment = new UserWorkingTimeModel();
		$assignment->setUserId('u');
		$assignment->setWorkingTimeModelId(7);
		$this->userWorkingTimeModelMapper->method('findByUserAndDate')->willReturn($assignment);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn($assignment);
		$this->workingTimeModelMapper->method('find')->willReturn($model);
		$this->holidayService->method('isHolidayForUser')->willReturn(false);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturn([]);
	}

	public function testFeatureFlagOffWritesNothing(): void
	{
		$this->appConfig[Constants::CONFIG_AUTO_CLOCK_OUT_ENABLED] = '0';
		$this->userManager->expects($this->never())->method('callForAllUsers');
		$this->timeTrackingService->expects($this->never())->method('autoClockOutAtPlannedEnd');
		$this->locking->expects($this->once())->method('releaseLock');
		$this->runJob();
	}

	public function testRunOutsideConfiguredHourDoesNothing(): void
	{
		$hour = ((int)(new \DateTime('now', new \DateTimeZone(self::TZ)))->format('G') + 2) % 24;
		$this->appConfig[Constants::CONFIG_AUTO_CLOCK_OUT_HOUR] = (string)$hour;
		$this->userManager->expects($this->never())->method('callForAllUsers');
		$this->locking->expects($this->once())->method('releaseLock');
		$this->runJob();
	}

	public function testUpgradeLockHeldSkipsRunWithoutRelease(): void
	{
		$this->locking->method('acquireLock')->willThrowException(new LockedException('x'));
		$this->userManager->expects($this->never())->method('callForAllUsers');
		$this->locking->expects($this->never())->method('releaseLock');
		$this->runJob();
	}

	public function testClosesOpenEntryAtPlannedEndAndNotifies(): void
	{
		$this->wireEverydayModel();
		// Yesterday 10:00 → planned end yesterday 16:00, always ≤ today's cutover.
		$yesterday = (new \DateTime('now', new \DateTimeZone(self::TZ)))->modify('-1 day');
		$entry = $this->openEntry(55, 'alice', $yesterday->format('Y-m-d') . ' 10:00:00');
		$closed = clone $entry;
		$closed->setStatus(TimeEntry::STATUS_COMPLETED);
		$closed->setEndTime(new \DateTime($yesterday->format('Y-m-d') . ' 16:00:00', new \DateTimeZone(self::TZ)));

		$this->withUsers($this->user('alice'));
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);
		$this->timeEntryMapper->method('findOpenEntryByUser')->with('alice')->willReturn($entry);
		$this->timeEntryMapper->method('findPendingOpenSessionByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);

		$this->timeTrackingService->expects($this->once())
			->method('autoClockOutAtPlannedEnd')
			->with('alice', 55, $this->callback(
				static fn (\DateTimeInterface $end) => $end->format('Y-m-d H:i') === $yesterday->format('Y-m-d') . ' 16:00'
			))
			->willReturn($closed);
		$this->notificationService->expects($this->once())
			->method('notifyAutoClockOut')
			->with('alice', $this->callback(
				static fn (array $p) => ($p['id'] ?? null) === 55 && ($p['end_time'] ?? null) === '16:00'
			));
		$this->config->expects($this->once())
			->method('setUserValue')
			->with('alice', 'arbeitszeitcheck', 'auto_clockout_notice', $this->callback(
				static fn (string $json) => str_contains($json, TimeEntry::ENDED_REASON_AUTO_PLANNED_END)
			));
		$this->locking->expects($this->once())->method('releaseLock');

		$this->runJob();
	}

	public function testTodayScopeSkipsOlderEntry(): void
	{
		$this->appConfig[Constants::CONFIG_AUTO_CLOCK_OUT_SCOPE] = Constants::AUTO_CLOCK_OUT_SCOPE_TODAY;
		$this->wireEverydayModel();
		$yesterday = (new \DateTime('now', new \DateTimeZone(self::TZ)))->modify('-1 day');
		$entry = $this->openEntry(55, 'alice', $yesterday->format('Y-m-d') . ' 10:00:00');

		$this->withUsers($this->user('alice'));
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);
		$this->timeEntryMapper->method('findOpenEntryByUser')->willReturn($entry);
		$this->timeEntryMapper->method('findPendingOpenSessionByUser')->willReturn(null);
		// Resolvable path wired so ONLY the scope gate can veto the write —
		// an inverted day check must be caught, not masked by no_model.
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);

		$this->timeTrackingService->expects($this->never())->method('autoClockOutAtPlannedEnd');
		$this->notificationService->expects($this->never())->method('notifyAutoClockOut');
		$this->runJob();
	}

	public function testDisabledAndRestrictedUsersSkipped(): void
	{
		$this->withUsers($this->user('off', false), $this->user('blocked'));
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(false);
		$this->timeEntryMapper->expects($this->never())->method('findOpenEntryByUser');
		$this->timeTrackingService->expects($this->never())->method('autoClockOutAtPlannedEnd');
		$this->runJob();
	}

	public function testDisabledUserIsSkippedEvenWhenAccessAllows(): void
	{
		// The isEnabled gate must hold on its own — a disabled account whose
		// access-group check would pass must never even be scanned.
		$this->withUsers($this->user('off', false));
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);
		$this->timeEntryMapper->expects($this->never())->method('findOpenEntryByUser');
		$this->timeTrackingService->expects($this->never())->method('autoClockOutAtPlannedEnd');
		$this->runJob();
	}

	public function testPendingCorrectionSkipsUser(): void
	{
		$this->wireEverydayModel();
		$yesterday = (new \DateTime('now', new \DateTimeZone(self::TZ)))->modify('-1 day');
		$entry = $this->openEntry(55, 'alice', $yesterday->format('Y-m-d') . ' 10:00:00');

		$this->withUsers($this->user('alice'));
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);
		$this->timeEntryMapper->method('findOpenEntryByUser')->willReturn($entry);
		$this->timeEntryMapper->method('findPendingOpenSessionByUser')->willReturn($this->openEntry(77, 'alice', $yesterday->format('Y-m-d') . ' 09:00:00'));
		// Without the pending-correction gate this entry resolves cleanly —
		// only the gate may veto the write.
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);

		$this->timeTrackingService->expects($this->never())->method('autoClockOutAtPlannedEnd');
		$this->runJob();
	}

	public function testPausedEntryIsClosedAtPlannedEnd(): void
	{
		$this->wireEverydayModel();
		$yesterday = (new \DateTime('now', new \DateTimeZone(self::TZ)))->modify('-1 day');
		$entry = $this->openEntry(55, 'alice', $yesterday->format('Y-m-d') . ' 10:00:00');
		$entry->setStatus(TimeEntry::STATUS_PAUSED);
		$closed = clone $entry;
		$closed->setStatus(TimeEntry::STATUS_COMPLETED);
		$closed->setEndTime(new \DateTime($yesterday->format('Y-m-d') . ' 16:00:00', new \DateTimeZone(self::TZ)));

		$this->withUsers($this->user('alice'));
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);
		$this->timeEntryMapper->method('findOpenEntryByUser')->willReturn($entry);
		$this->timeEntryMapper->method('findPendingOpenSessionByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);

		$this->timeTrackingService->expects($this->once())
			->method('autoClockOutAtPlannedEnd')
			->with('alice', 55, $this->anything())
			->willReturn($closed);
		$this->notificationService->expects($this->once())->method('notifyAutoClockOut');
		$this->runJob();
	}

	public function testManualClockOutRaceIsHandledNotAnError(): void
	{
		$this->wireEverydayModel();
		$yesterday = (new \DateTime('now', new \DateTimeZone(self::TZ)))->modify('-1 day');
		$entry = $this->openEntry(55, 'alice', $yesterday->format('Y-m-d') . ' 10:00:00');

		$this->withUsers($this->user('alice'));
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);
		$this->timeEntryMapper->method('findOpenEntryByUser')->willReturn($entry);
		$this->timeEntryMapper->method('findPendingOpenSessionByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->timeTrackingService->method('autoClockOutAtPlannedEnd')
			->willThrowException(new BusinessRuleException('User is not currently clocked in', BusinessRuleCode::NOT_CLOCKED_IN));
		$this->notificationService->expects($this->never())->method('notifyAutoClockOut');
		// A raced entry is already handled — neither error nor warning noise.
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->never())->method('warning');

		$this->runJob();
	}

	public function testPerUserFailureDoesNotAbortRun(): void
	{
		$this->wireEverydayModel();
		$yesterday = (new \DateTime('now', new \DateTimeZone(self::TZ)))->modify('-1 day');
		$entry = $this->openEntry(56, 'bob', $yesterday->format('Y-m-d') . ' 10:00:00');
		$closed = clone $entry;
		$closed->setStatus(TimeEntry::STATUS_COMPLETED);
		$closed->setEndTime(new \DateTime($yesterday->format('Y-m-d') . ' 16:00:00', new \DateTimeZone(self::TZ)));

		$this->withUsers($this->user('alice'), $this->user('bob'));
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);
		$this->timeEntryMapper->method('findOpenEntryByUser')->willReturnCallback(
			static fn (string $uid) => $uid === 'alice' ? throw new \RuntimeException('db gone') : $entry
		);
		$this->timeEntryMapper->method('findPendingOpenSessionByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);
		$this->timeTrackingService->expects($this->once())
			->method('autoClockOutAtPlannedEnd')->with('bob', 56, $this->anything())
			->willReturn($closed);
		$this->notificationService->expects($this->once())->method('notifyAutoClockOut')->with('bob', $this->anything());
		$this->logger->expects($this->once())->method('error');

		$this->runJob();
	}

	public function testOverlapConflictSkipsWrite(): void
	{
		$this->wireEverydayModel();
		$yesterday = (new \DateTime('now', new \DateTimeZone(self::TZ)))->modify('-1 day');
		$entry = $this->openEntry(55, 'alice', $yesterday->format('Y-m-d') . ' 10:00:00');

		$this->withUsers($this->user('alice'));
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);
		$this->timeEntryMapper->method('findOpenEntryByUser')->willReturn($entry);
		$this->timeEntryMapper->method('findPendingOpenSessionByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$this->openEntry(90, 'alice', $yesterday->format('Y-m-d') . ' 12:00:00')]);

		$this->timeTrackingService->expects($this->never())->method('autoClockOutAtPlannedEnd');
		$this->notificationService->expects($this->never())->method('notifyAutoClockOut');
		$this->runJob();
	}

	public function testConfiguredHourClampsCorruptStoredValues(): void
	{
		// Defence in depth behind the 0–23 admin validation: a corrupt stored
		// value must clamp into range, never produce an out-of-range gate.
		$m = new \ReflectionMethod($this->job, 'configuredHour');

		$this->appConfig[Constants::CONFIG_AUTO_CLOCK_OUT_HOUR] = '-5';
		$this->assertSame(0, $m->invoke($this->job));

		$this->appConfig[Constants::CONFIG_AUTO_CLOCK_OUT_HOUR] = '99';
		$this->assertSame(23, $m->invoke($this->job));

		$this->appConfig[Constants::CONFIG_AUTO_CLOCK_OUT_HOUR] = 'not-a-number';
		$this->assertSame(Constants::AUTO_CLOCK_OUT_DEFAULT_HOUR, $m->invoke($this->job));
	}
}
