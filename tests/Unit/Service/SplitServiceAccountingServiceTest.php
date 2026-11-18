<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\SplitServiceAccountingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\SplitServiceAccounting as S;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Orchestration-layer contract for the frozen split-service context
 * (SPEC §9.5 pin, §9.6 recompute incl. break-cover, §12.2 failure posture).
 *
 * Fixture day throughout: Europe/Berlin 2026-11-16 (no DST), blocks
 * 06:00–10:00 + 13:30–16:30, interruption 10:00–13:30, cutoff 16:00.
 */
class SplitServiceAccountingServiceTest extends TestCase
{
	private const TZ = 'Europe/Berlin';
	private const DAY = '2026-11-16';

	/** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
	private $config;
	/** @var UserWorkingTimeModelMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $userWtmMapper;
	/** @var WorkingTimeModelMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $wtmMapper;
	/** @var TimeEntryMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;
	/** @var UserSettingsMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $userSettingsMapper;

	private SplitServiceAccountingService $service;
	private BreakPolicyResolver $resolver;

	/** Mutable per-test model break_rules handed to the mocked model. */
	private array $modelBreakRules = [];
	/** Mutable per-test assignment. */
	private ?UserWorkingTimeModel $assignment = null;
	/** Mutable per-test overlapping-entry list for day-level lookups. */
	private array $overlappingEntries = [];

	protected function setUp(): void
	{
		parent::setUp();

		$appValues = ['country' => 'DE', 'app_timezone' => self::TZ];
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $default) => $appValues[$key] ?? $default
		);
		$this->config->method('getUserValue')->willReturn('');

		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$this->userSettingsMapper->method('findUserIdsWithNonEmptySetting')->willReturn([]);
		$this->userSettingsMapper->method('getStringSetting')->willReturn('1');

		$this->userWtmMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->userWtmMapper->method('findByUserAndDate')->willReturnCallback(
			fn () => $this->assignment
		);
		$this->wtmMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->wtmMapper->method('find')->willReturnCallback(function (int $id): WorkingTimeModel {
			$model = new WorkingTimeModel();
			$model->setId($id);
			$model->setBreakRules(json_encode($this->modelBreakRules));
			return $model;
		});
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->timeEntryMapper->method('findOverlapping')->willReturnCallback(
			fn () => $this->overlappingEntries
		);

		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone(self::TZ));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($this->config, $dateTimeZone, $userSession, new NullLogger());
		$lawProfileFactory = new LaborLawProfileFactory($this->config, $this->userSettingsMapper);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $p = []) => $s);
		$this->resolver = new BreakPolicyResolver(
			$this->config,
			$lawProfileFactory,
			$this->userSettingsMapper,
			$this->userWtmMapper,
			$this->wtmMapper,
			$this->timeEntryMapper,
			$timeZoneService,
			$l10n,
		);
		$this->service = new SplitServiceAccountingService(
			$this->userWtmMapper,
			$this->wtmMapper,
			$this->timeEntryMapper,
			$this->resolver,
			$lawProfileFactory,
			$timeZoneService,
			new NullLogger(),
		);
	}

	// ------------------------------------------------------------------ //
	// Fixtures                                                            //
	// ------------------------------------------------------------------ //

	private static function ts(string $local, string $tz = self::TZ): int
	{
		return (new \DateTimeImmutable($local, new \DateTimeZone($tz)))->getTimestamp();
	}

	/** v2 weekday_schedule document: two blocks/day Mon–Fri with the F1 gap. */
	private static function v2Schedule(array $blocks = [['start' => '06:00', 'end' => '10:00'], ['start' => '13:30', 'end' => '16:30']], array $split = []): array
	{
		// An empty $blocks means "keep the canonical blocks" — callers pass
		// [] when they only override the split_service group.
		if ($blocks === []) {
			$blocks = [['start' => '06:00', 'end' => '10:00'], ['start' => '13:30', 'end' => '16:30']];
		}
		$days = [];
		foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $d) {
			$days[$d] = ['work' => true, 'blocks' => $blocks, 'breaks' => []];
		}
		$days['sat'] = ['work' => false];
		$days['sun'] = ['work' => false];
		return [
			'version' => 2,
			'interruption_mode' => 'recorded_only',
			'split_service' => array_merge([
				'automatic_split' => true,
				'split_when_clock_out_after' => '16:00',
				'interruption_counts_as_break' => true,
			], $split),
			'days' => $days,
		];
	}

	private function assignSplitModel(array $scheduleDoc): void
	{
		$this->modelBreakRules = [WeekdaySchedule::KEY => $scheduleDoc];
		$assignment = new UserWorkingTimeModel();
		$assignment->setWorkingTimeModelId(7);
		$this->assignment = $assignment;
		$this->resolver->clearCache();
	}

	private function splitModel(): WorkingTimeModel
	{
		$model = new WorkingTimeModel();
		$model->setId(7);
		$model->setBreakRules(json_encode([WeekdaySchedule::KEY => $this->modelBreakRules[WeekdaySchedule::KEY]]));
		return $model;
	}

	private function entry(string $start, string $end, ?int $id = 1): TimeEntry
	{
		$entry = new TimeEntry();
		if ($id !== null) {
			$entry->setId($id);
		}
		$entry->setUserId('u1');
		$entry->setStartTime(new \DateTime($start, new \DateTimeZone(self::TZ)));
		if ($end !== '') {
			$entry->setEndTime(new \DateTime($end, new \DateTimeZone(self::TZ)));
		}
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		return $entry;
	}

	/** Pin the context on a fresh entry through the real service path. */
	private function pinnedEntry(string $start, string $end, array $scheduleDoc, ?int $id = 1): TimeEntry
	{
		$this->assignSplitModel($scheduleDoc);
		$entry = $this->entry($start, $end, $id);
		$this->service->pinContextForEntry($entry, 'u1');
		$this->assertNotNull($entry->getWorkContextJson(), 'fixture: context must pin');
		$this->overlappingEntries = [$entry];
		return $entry;
	}

	// ------------------------------------------------------------------ //
	// buildContext / pinning (§9.5)                                       //
	// ------------------------------------------------------------------ //

	public function testBuildContextPinsForV2SplitModel(): void
	{
		$this->modelBreakRules = [WeekdaySchedule::KEY => self::v2Schedule()];
		$context = $this->service->buildContext(
			$this->splitModel(),
			new \DateTime(self::DAY . ' 06:00:00', new \DateTimeZone(self::TZ)),
			self::TZ
		);

		$this->assertNotNull($context);
		$this->assertSame(1, $context['v']);
		$this->assertSame(7, $context['model_id']);
		$this->assertSame(self::TZ, $context['timezone']);
		$this->assertSame(
			WeekdaySchedule::revisionOf($this->modelBreakRules[WeekdaySchedule::KEY]),
			$context['schedule_revision']
		);
		// Monday blocks frozen at the pin date's weekday.
		$this->assertSame([['06:00', '10:00'], ['13:30', '16:30']], $context['day']['blocks']);
		foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $d) {
			$this->assertSame([['06:00', '10:00'], ['13:30', '16:30']], $context['week'][$d]);
		}
		$this->assertSame([], $context['week']['sat']);
		$this->assertSame(
			[
				'automatic' => true,
				'cutoff' => '16:00',
				'break_cover' => true,
				'weekday_cutoffs' => [
					'mon' => '16:00', 'tue' => '16:00', 'wed' => '16:00',
					'thu' => '16:00', 'fri' => '16:00',
				],
			],
			$context['split']
		);
		$this->assertSame(['decision' => 'auto', 'worked' => []], $context['overrides']);
		$this->assertSame('active', $context['result']['state']);
		$this->assertFalse($context['result']['split']);
		$this->assertSame(1, $context['result']['engine']);
	}

	public function testBuildContextFreezesEffectiveWeekdayCutoff(): void
	{
		// Friday carries its own cut-off — the frozen split.cutoff must be the
		// override on Friday and the default on Monday (NH-01, pin-time).
		$v2 = self::v2Schedule();
		$v2['split_service']['weekday_cutoffs'] = ['fri' => '14:00'];
		$this->modelBreakRules = [WeekdaySchedule::KEY => $v2];

		$fri = $this->service->buildContext(
			$this->splitModel(),
			new \DateTime('2026-11-20 06:00:00', new \DateTimeZone(self::TZ)),
			self::TZ
		);
		$this->assertSame('14:00', $fri['split']['cutoff']);
		$this->assertSame('14:00', $fri['split']['weekday_cutoffs']['fri']);

		$mon = $this->service->buildContext(
			$this->splitModel(),
			new \DateTime('2026-11-23 06:00:00', new \DateTimeZone(self::TZ)),
			self::TZ
		);
		$this->assertSame('16:00', $mon['split']['cutoff']);
		$this->assertSame('14:00', $mon['split']['weekday_cutoffs']['fri']);
	}

	public function testBuildContextFreezesStartWeekdayNotPinDay(): void
	{
		// Different blocks per weekday prove the day map is picked by the
		// entry's start date, not by "now".
		$this->modelBreakRules = [WeekdaySchedule::KEY => self::v2Schedule()];
		$context = $this->service->buildContext(
			$this->splitModel(),
			new \DateTime('2026-11-20 06:00:00', new \DateTimeZone(self::TZ)), // Friday
			self::TZ
		);
		$this->assertNotNull($context);
		$this->assertSame([['06:00', '10:00'], ['13:30', '16:30']], $context['day']['blocks']);
	}

	public function testBuildContextNullForV1Model(): void
	{
		$v1 = ['version' => 1, 'days' => [
			'mon' => ['work' => true, 'start' => '08:00', 'end' => '17:00'],
		] + array_fill_keys(['tue', 'wed', 'thu', 'fri', 'sat', 'sun'], ['work' => false])];
		$this->modelBreakRules = [WeekdaySchedule::KEY => $v1];
		$this->assertNull($this->service->buildContext(
			$this->splitModel(),
			new \DateTime(self::DAY . ' 08:00:00', new \DateTimeZone(self::TZ)),
			self::TZ
		));
	}

	public function testBuildContextNullWhenSplitServiceInactive(): void
	{
		$this->modelBreakRules = [WeekdaySchedule::KEY => self::v2Schedule([], [
			'automatic_split' => false,
			'split_when_clock_out_after' => null,
			'interruption_counts_as_break' => false,
		])];
		$this->assertNull($this->service->buildContext(
			$this->splitModel(),
			new \DateTime(self::DAY . ' 06:00:00', new \DateTimeZone(self::TZ)),
			self::TZ
		));
	}

	public function testPinContextForEntryWithoutAssignmentStaysNull(): void
	{
		$entry = $this->entry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00');
		$this->service->pinContextForEntry($entry, 'u1');
		$this->assertNull($entry->getWorkContextJson(), 'no assignment → legacy NULL context');
	}

	public function testPinContextForEntryStoresFrozenDocument(): void
	{
		$entry = $this->entry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00');
		$this->assignSplitModel(self::v2Schedule());
		$this->service->pinContextForEntry($entry, 'u1');

		$json = $entry->getWorkContextJson();
		$this->assertNotNull($json);
		$decoded = json_decode((string)$json, true);
		$this->assertSame(1, $decoded['v']);
		$this->assertSame('active', $decoded['result']['state']);
		$this->assertSame(0, $entry->getExcludedWorkSeconds());
	}

	public function testPinNeverRepinsExistingContext(): void
	{
		$entry = $this->entry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00');
		$entry->setWorkContextJson('{"v":1,"preexisting":true}');
		$this->userWtmMapper->expects($this->never())->method('findByUserAndDate');
		$this->service->pinContextForEntry($entry, 'u1');
		$this->assertSame('{"v":1,"preexisting":true}', $entry->getWorkContextJson());
	}

	// ------------------------------------------------------------------ //
	// recomputeAccounting — guard rails                                   //
	// ------------------------------------------------------------------ //

	public function testRecomputeLegacyEntryIsNoOpAndRunsNoQueries(): void
	{
		$entry = $this->entry(self::DAY . ' 08:00:00', self::DAY . ' 17:00:00');
		$this->timeEntryMapper->expects($this->never())->method('findOverlapping');
		$this->assertNull($this->service->recomputeAccounting($entry));
		$this->assertNull($entry->getWorkContextJson());
		$this->assertSame(0, $entry->getExcludedWorkSeconds());
	}

	public function testRecomputeInvalidContextLogsAndKeepsLegacy(): void
	{
		$entry = $this->entry(self::DAY . ' 08:00:00', self::DAY . ' 17:00:00');
		$entry->setWorkContextJson('{broken');
		$this->timeEntryMapper->expects($this->never())->method('findOverlapping');
		$this->assertNull($this->service->recomputeAccounting($entry));
		$this->assertSame('{broken', $entry->getWorkContextJson(), 'malformed context is never rewritten');
	}

	public function testRecomputeOpenEntryDoesNoWrites(): void
	{
		$entry = $this->entry(self::DAY . ' 06:00:00', '');
		$this->assignSplitModel(self::v2Schedule());
		$this->service->pinContextForEntry($entry, 'u1');
		$before = $entry->getWorkContextJson();
		$this->assertNull($this->service->recomputeAccounting($entry));
		$this->assertSame($before, $entry->getWorkContextJson(), 'open entries are preview-only (spec step 1)');
	}

	// ------------------------------------------------------------------ //
	// recomputeAccounting — split outcomes                                //
	// ------------------------------------------------------------------ //

	public function testRecomputeF1CoveredByInterruption(): void
	{
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00', self::v2Schedule());

		$result = $this->service->recomputeAccounting($entry);

		$this->assertNotNull($result);
		$this->assertSame('applied', $result['state']);
		$this->assertTrue($result['split']);
		$this->assertSame('clock_out_after_cutoff', $result['reason']);
		$this->assertSame('covered_by_interruption', $result['break_handling']);
		$this->assertSame(12600, $entry->getExcludedWorkSeconds());
		$this->assertSame([['2026-11-16T10:00:00+01:00', '2026-11-16T13:30:00+01:00']], $result['excluded']);
		$this->assertCount(2, $result['segments']);
		// Result lands inside the frozen context document.
		$stored = $entry->getAccountingResult();
		$this->assertSame($result, $stored);
	}

	public function testRecomputeBreakCoverOffStaysStandard(): void
	{
		$entry = $this->pinnedEntry(
			self::DAY . ' 06:00:00',
			self::DAY . ' 16:30:00',
			self::v2Schedule([], ['interruption_counts_as_break' => false])
		);

		$result = $this->service->recomputeAccounting($entry);

		$this->assertSame('applied', $result['state']);
		$this->assertTrue($result['split']);
		$this->assertSame('standard', $result['break_handling']);
		$this->assertSame(12600, $entry->getExcludedWorkSeconds());
	}

	public function testRecomputeA06ClockOutBeforeCutoffNoSplit(): void
	{
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 13:30:00', self::v2Schedule());

		$result = $this->service->recomputeAccounting($entry);

		$this->assertSame('applied', $result['state']);
		$this->assertFalse($result['split']);
		$this->assertSame('not_after_cutoff', $result['reason']);
		$this->assertSame('standard', $result['break_handling']);
		$this->assertSame(0, $entry->getExcludedWorkSeconds());
	}

	public function testRecomputeA09ClockInDuringInterruption(): void
	{
		$entry = $this->pinnedEntry(self::DAY . ' 11:00:00', self::DAY . ' 16:30:00', self::v2Schedule());

		$result = $this->service->recomputeAccounting($entry);

		$this->assertFalse($result['split']);
		$this->assertSame('clock_in_during_interruption', $result['reason']);
		$this->assertSame(0, $entry->getExcludedWorkSeconds());
	}

	public function testRecomputeCrossesMidnight(): void
	{
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', '2026-11-17 01:00:00', self::v2Schedule());

		$result = $this->service->recomputeAccounting($entry);

		$this->assertSame('applied', $result['state']);
		$this->assertFalse($result['split']);
		$this->assertSame('crosses_midnight', $result['reason']);
		$this->assertSame('standard', $result['break_handling']);
		$this->assertSame(0, $entry->getExcludedWorkSeconds());
	}

	public function testRecomputeDstAmbiguityReviews(): void
	{
		// 2026-03-29 02:30 does not exist in Europe/Berlin (spring-forward is
		// a Sunday — the fixture's default sun=>off would yield
		// no_interruption_today instead). A block boundary on that wall time
		// is nonexistent → review, never a guess (§12.2).
		$schedule = self::v2Schedule([], ['split_when_clock_out_after' => '07:00']);
		// Sunday is the only workday — keeping Mon–Fri blocks would push the
		// latest gap end past the 07:00 cutoff (SPLIT_CUTOFF_BEFORE_INTERRUPTION_END).
		foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $d) {
			$schedule['days'][$d] = ['work' => false];
		}
		$schedule['days']['sun'] = [
			'work' => true,
			'blocks' => [['start' => '00:00', 'end' => '02:30'], ['start' => '03:30', 'end' => '08:00']],
			'breaks' => [],
		];
		$entry = $this->pinnedEntry('2026-03-29 00:00:00', '2026-03-29 07:30:00', $schedule);

		$result = $this->service->recomputeAccounting($entry);

		$this->assertSame('review', $result['state']);
		$this->assertFalse($result['split']);
		$this->assertSame('dst_review', $result['reason']);
		$this->assertSame('not_applicable', $result['break_handling']);
		$this->assertSame(0, $entry->getExcludedWorkSeconds());
	}

	// ------------------------------------------------------------------ //
	// Break-cover branches (§9.6 step 10b)                                //
	// ------------------------------------------------------------------ //

	public function testCoverageStandardBreakTooShort(): void
	{
		// Interruption of 5 min is below the DE 15-min floor: split happens,
		// but the gap can never count as break evidence.
		$schedule = self::v2Schedule(
			[['start' => '06:00', 'end' => '10:00'], ['start' => '10:05', 'end' => '16:30']],
		);
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00', $schedule);

		$result = $this->service->recomputeAccounting($entry);

		$this->assertTrue($result['split']);
		$this->assertSame('standard_break_too_short', $result['break_handling']);
		$this->assertSame(300, $entry->getExcludedWorkSeconds());
	}

	public function testCoverageStandardSegmentTooLong(): void
	{
		// 45-min interruption satisfies the break amount, but the morning
		// stretch 06:00–13:00 (7h) exceeds the >6h statutory limit L.
		$schedule = self::v2Schedule(
			[['start' => '06:00', 'end' => '13:00'], ['start' => '13:45', 'end' => '18:00']],
		);
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 18:00:00', $schedule);

		$result = $this->service->recomputeAccounting($entry);

		$this->assertTrue($result['split']);
		$this->assertSame('standard_segment_too_long', $result['break_handling']);
	}

	public function testCoverageNotApplicableWhenGapWorkedByOtherEntry(): void
	{
		$schedule = self::v2Schedule();
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00', $schedule);
		// A second entry fills the whole interruption — nothing left for the
		// cover rule to cover.
		$other = $this->entry(self::DAY . ' 10:00:00', self::DAY . ' 13:30:00', 2);
		$this->overlappingEntries = [$entry, $other];

		$result = $this->service->recomputeAccounting($entry);

		$this->assertTrue($result['split']);
		$this->assertSame('not_applicable', $result['break_handling']);
	}

	public function testManualBreakPortionCombinesWithInterruption(): void
	{
		// 15-min gap alone is below the requirement; gap + recorded 15-min
		// manual break meet it → covered.
		$schedule = self::v2Schedule(
			[['start' => '06:00', 'end' => '10:15'], ['start' => '10:30', 'end' => '14:30']],
			['split_when_clock_out_after' => '14:00']
		);
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 14:30:00', $schedule);
		$entry->setBreaks(json_encode([[
			'start' => '2026-11-16T11:00:00+01:00',
			'end' => '2026-11-16T11:15:00+01:00',
			'duration_minutes' => 15,
		]]));

		$result = $this->service->recomputeAccounting($entry);

		$this->assertTrue($result['split']);
		$this->assertSame('covered_by_interruption', $result['break_handling']);
		$breaks = json_decode((string)$entry->getBreaks(), true);
		$this->assertCount(1, $breaks, 'manual evidence is retained unchanged');
	}

	// ------------------------------------------------------------------ //
	// Derived-data discipline + idempotency (§9.6 step 12, A19)           //
	// ------------------------------------------------------------------ //

	public function testCoveredVerdictDropsStaleAutomaticPortions(): void
	{
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00', self::v2Schedule());
		$entry->setBreaks(json_encode([
			['start' => '2026-11-16T07:00:00+01:00', 'end' => '2026-11-16T07:30:00+01:00', 'automatic' => true],
			['start' => '2026-11-16T14:00:00+01:00', 'end' => '2026-11-16T14:10:00+01:00', 'automatic' => false],
		]));

		$result = $this->service->recomputeAccounting($entry);

		$this->assertSame('covered_by_interruption', $result['break_handling']);
		$breaks = json_decode((string)$entry->getBreaks(), true);
		$this->assertCount(1, $breaks, 'automatic portion removed on covered verdict');
		$this->assertSame('2026-11-16T14:00:00+01:00', $breaks[0]['start'], 'manual portion untouched');
	}

	public function testAutomaticPortionNeverSelfJustifiesCoverage(): void
	{
		// 15-min gap alone cannot meet the 30-min requirement. Counting the
		// stale automatic portion would flip the verdict to covered — which
		// would then delete the very portion that justified it (oscillation).
		// Automatic portions are derived data, never evidence: the verdict
		// must stay standard_break_too_short and the portion is left for
		// the standard reconcile flow (idempotent re-run, §9.6 step 12).
		$schedule = self::v2Schedule(
			[['start' => '06:00', 'end' => '10:15'], ['start' => '10:30', 'end' => '14:30']],
			['split_when_clock_out_after' => '14:00']
		);
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 14:30:00', $schedule);
		// Stale automatic break inside a work segment.
		$entry->setBreaks(json_encode([
			['start' => '2026-11-16T11:00:00+01:00', 'end' => '2026-11-16T11:15:00+01:00', 'automatic' => true],
		]));

		$result = $this->service->recomputeAccounting($entry);
		$this->assertSame('standard_break_too_short', $result['break_handling']);
		$breaks = json_decode((string)$entry->getBreaks(), true);
		$this->assertCount(1, $breaks, 'standard verdict never rewrites evidence');

		// Re-run on the resulting state: identical verdict — no flip-flop.
		$result2 = $this->service->recomputeAccounting($entry);
		$this->assertSame('standard_break_too_short', $result2['break_handling']);
	}

	public function testAutomaticPortionPreservedWhenStandard(): void
	{
		$entry = $this->pinnedEntry(
			self::DAY . ' 06:00:00',
			self::DAY . ' 16:30:00',
			self::v2Schedule([], ['interruption_counts_as_break' => false])
		);
		$json = json_encode([[
			'start' => '2026-11-16T07:00:00+01:00',
			'end' => '2026-11-16T07:30:00+01:00',
			'automatic' => true,
		]]);
		$entry->setBreaks($json);

		$this->service->recomputeAccounting($entry);

		$this->assertSame($json, $entry->getBreaks(), 'standard path leaves evidence to the reconcile flow');
	}

	public function testRecomputeIsIdempotent(): void
	{
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00', self::v2Schedule());

		$this->service->recomputeAccounting($entry);
		$hash1 = S::contextResultHash($entry->getAccountingResult());
		$json1 = $entry->getWorkContextJson();

		$this->service->recomputeAccounting($entry);
		$hash2 = S::contextResultHash($entry->getAccountingResult());

		$this->assertSame($hash1, $hash2, 'same inputs → byte-identical result (A19)');
		$this->assertSame($json1, $entry->getWorkContextJson());
	}

	public function testRecomputeInapplicableOverrideBecomesReview(): void
	{
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00', self::v2Schedule());
		// Simulate an approved force_split override on an entry whose end no
		// longer reaches the gap end (e.g. corrected times): shorten the
		// clock-out and force the decision in the stored context.
		$context = $entry->getWorkContext();
		$context['overrides'] = ['decision' => 'force_split', 'worked' => []];
		$entry->setWorkContextJson(json_encode($context));
		$entry->setEndTime(new \DateTime(self::DAY . ' 12:00:00', new \DateTimeZone(self::TZ)));

		$result = $this->service->recomputeAccounting($entry);

		$this->assertSame('review', $result['state'], 'inapplicable override must never be applied blindly');
		$this->assertFalse($result['split']);
		$this->assertSame(S::ERR_OVERRIDE_NOT_APPLICABLE, $result['error'] ?? null);
		$this->assertSame('not_applicable', $result['break_handling']);
		$this->assertSame(0, $entry->getExcludedWorkSeconds());
	}

	public function testMarkReviewForFailedPlacementRewritesResult(): void
	{
		$entry = $this->pinnedEntry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00', self::v2Schedule());
		$this->service->recomputeAccounting($entry);
		$this->assertTrue($entry->getAccountingResult()['split']);

		$this->service->markReviewForFailedPlacement($entry);

		$result = $entry->getAccountingResult();
		$this->assertSame('review', $result['state']);
		$this->assertFalse($result['split']);
		$this->assertSame(SplitServiceAccountingService::ERR_BREAK_PLACEMENT_IMPOSSIBLE, $result['error'] ?? null);
		$this->assertSame(0, $entry->getExcludedWorkSeconds());
		// Unsplit conservative accounting: segments mirror the raw span.
		$this->assertCount(1, $result['segments']);
	}

	public function testPinOnlyOnV2WithSplitEvenWhenBreakCoverOnly(): void
	{
		// interruption_counts_as_break alone activates pinning (AS-04: the
		// flag is evaluated per frozen context, even with automatic off).
		$entry = $this->entry(self::DAY . ' 06:00:00', self::DAY . ' 16:30:00');
		$this->assignSplitModel(self::v2Schedule([], [
			'automatic_split' => false,
			'split_when_clock_out_after' => null,
			'interruption_counts_as_break' => true,
		]));
		$this->service->pinContextForEntry($entry, 'u1');
		$this->assertNotNull($entry->getWorkContextJson());
	}
}
