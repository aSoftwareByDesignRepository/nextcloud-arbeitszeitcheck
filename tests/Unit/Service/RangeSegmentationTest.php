<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCA\ArbeitszeitCheck\Service\WorkingTimeModelAssignmentResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The customer case end-to-end: Pia's mid-period model change must segment
 * the Soll — Oct Mo–Mi 5h + Nov Mo–Do 5h = 130h (125h with Buß- und Bettag
 * in Sachsen), never today's model stretched backwards (AC-9.1/9.2/9.10).
 */
class RangeSegmentationTest extends TestCase
{
	private TimeEntryMapper $timeEntries;
	private UserWorkingTimeModelMapper $assignments;
	private WorkingTimeModelMapper $models;
	private HolidayService $holidays;
	private UserOvertimeSettingsService $settings;
	private OvertimeService $service;
	private string $gapPolicy = 'lenient';

	protected function setUp(): void
	{
		parent::setUp();
		$this->timeEntries = $this->createMock(TimeEntryMapper::class);
		$this->assignments = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->models = $this->createMock(WorkingTimeModelMapper::class);
		$this->holidays = $this->createMock(HolidayService::class);
		$this->settings = $this->createMock(UserOvertimeSettingsService::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->timeEntries->method('findByUserAndDateRange')->willReturn([]);
		$this->settings->method('getTrackingFrom')->willReturn(null);
		$this->settings->method('getOpeningBalanceHours')->willReturn(0.0);
		$this->settings->method('resolveEffectiveYearStart')->willReturnCallback(
			static fn (string $u, int $y): \DateTime => new \DateTime(sprintf('%04d-01-01', $y))
		);
		$this->holidays->method('computeWorkingDaysForUser')->willReturn(0.0);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, ?string $default = null) => $key === 'assignments_gap_policy' ? $this->gapPolicy : $default
		);
		$resolver = new WorkingTimeModelAssignmentResolver($this->assignments, $this->models, $config);

		$this->service = new OvertimeService(
			$this->timeEntries,
			$this->models,
			$this->assignments,
			$l10n,
			$this->holidays,
			$this->settings,
			null,
			null,
			$resolver,
		);
	}

	private function row(int $id, int $modelId, string $start, ?string $end = null): UserWorkingTimeModel
	{
		$r = new UserWorkingTimeModel();
		$r->setId($id);
		$r->setUserId('pia');
		$r->setWorkingTimeModelId($modelId);
		$r->setVacationDaysPerYear(25);
		$r->setStartDate(new \DateTime($start));
		if ($end !== null) {
			$r->setEndDate(new \DateTime($end));
		}
		return $r;
	}

	/** @param list<string> $workDays day keys ('mon'..'sun') at 5h each */
	private function scheduleModel(int $id, string $name, array $workDays): WorkingTimeModel
	{
		$work = ['work' => true, 'start' => '09:00', 'end' => '14:00', 'breaks' => []];
		$off = ['work' => false];
		$days = [];
		foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
			$days[$d] = in_array($d, $workDays, true) ? $work : $off;
		}
		$m = new WorkingTimeModel();
		$m->setId($id);
		$m->setName($name);
		$m->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$m->setBreakRulesArray(['weekday_schedule' => ['version' => 1, 'days' => $days]]);
		return $m;
	}

	private function useModels(array $models): void
	{
		$this->models->method('find')->willReturnCallback(function (int $id) use ($models): WorkingTimeModel {
			if (!isset($models[$id])) {
				throw new DoesNotExistException('nope');
			}
			return $models[$id];
		});
		$this->models->method('findAll')->willReturn(array_values($models));
	}

	private function useRows(array $rows): void
	{
		$this->assignments->method('findByUser')->willReturnCallback(
			fn (string $uid): array => array_values(array_filter($rows, fn ($r) => $r->getUserId() === $uid))
		);
	}

	/** AC-9.1: Pia without holiday — 60h Oct + 70h Nov = 130h. */
	public function testPiaSegmentationWithoutHoliday(): void
	{
		$this->useRows([
			$this->row(1, 1, '2026-10-01', '2026-10-31'),
			$this->row(2, 2, '2026-11-01', null),
		]);
		$this->useModels([
			1 => $this->scheduleModel(1, '15h Mo–Mi', ['mon', 'tue', 'wed']),
			2 => $this->scheduleModel(2, '20h Mo–Do', ['mon', 'tue', 'wed', 'thu']),
		]);
		$this->holidays->method('getHolidayWeightForUser')->willReturn(0.0);

		$r = $this->service->calculateOvertime('pia', new \DateTime('2026-10-01'), new \DateTime('2026-11-24'), false);

		$this->assertEqualsWithDelta(130.0, $r['required_hours'], 0.01);
		$this->assertSame('segmented', $r['required_hours_basis']);
		$this->assertCount(2, $r['soll_segments']);
		$this->assertSame('2026-10-01', $r['soll_segments'][0]['start']);
		$this->assertSame('2026-10-31', $r['soll_segments'][0]['end']);
		$this->assertSame(1, $r['soll_segments'][0]['model_id']);
		$this->assertEqualsWithDelta(60.0, $r['soll_segments'][0]['hours'], 0.01);
		$this->assertSame('2026-11-01', $r['soll_segments'][1]['start']);
		$this->assertSame('2026-11-24', $r['soll_segments'][1]['end']);
		$this->assertEqualsWithDelta(70.0, $r['soll_segments'][1]['hours'], 0.01);
		// AC-9.10 sum invariant.
		$this->assertEqualsWithDelta(
			$r['required_hours'],
			array_sum(array_column($r['soll_segments'], 'hours')),
			0.01
		);
		// R-07: scalars mirror the contract covering period_end (model B).
		$this->assertEqualsWithDelta(20.0, $r['weekly_hours'], 0.01);
		// 0 worked hours ⇒ delta is −Soll.
		$this->assertEqualsWithDelta(-130.0, $r['overtime_hours'], 0.01);
	}

	/** AC-9.2: Sachsen — Buß- und Bettag (Wed 18.11.2026) drops one 5h day. */
	public function testPiaSegmentationSachsenHoliday(): void
	{
		$this->useRows([
			$this->row(1, 1, '2026-10-01', '2026-10-31'),
			$this->row(2, 2, '2026-11-01', null),
		]);
		$this->useModels([
			1 => $this->scheduleModel(1, '15h Mo–Mi', ['mon', 'tue', 'wed']),
			2 => $this->scheduleModel(2, '20h Mo–Do', ['mon', 'tue', 'wed', 'thu']),
		]);
		$this->holidays->method('getHolidayWeightForUser')->willReturnCallback(
			fn (string $u, \DateTime $day): float => $day->format('Y-m-d') === '2026-11-18' ? 1.0 : 0.0
		);

		$r = $this->service->calculateOvertime('pia', new \DateTime('2026-10-01'), new \DateTime('2026-11-24'), false);

		$this->assertEqualsWithDelta(125.0, $r['required_hours'], 0.01);
		$this->assertEqualsWithDelta(65.0, $r['soll_segments'][1]['hours'], 0.01);
	}

	/** AC-9.13: an on_demand window inside the range contributes 0. */
	public function testOnDemandWindowContributesZero(): void
	{
		$this->useRows([
			$this->row(1, 1, '2026-10-01', '2026-10-31'),
			$this->row(2, 3, '2026-11-01', '2026-11-15'),
			$this->row(3, 2, '2026-11-16', null),
		]);
		$onDemand = $this->scheduleModel(3, 'Abruf', []);
		$onDemand->setType(WorkingTimeModel::TYPE_ON_DEMAND);
		$onDemand->setBreakRulesArray([]);
		$this->useModels([
			1 => $this->scheduleModel(1, '15h Mo–Mi', ['mon', 'tue', 'wed']),
			2 => $this->scheduleModel(2, '20h Mo–Do', ['mon', 'tue', 'wed', 'thu']),
			3 => $onDemand,
		]);
		$this->holidays->method('getHolidayWeightForUser')->willReturn(0.0);

		$r = $this->service->calculateOvertime('pia', new \DateTime('2026-10-01'), new \DateTime('2026-11-24'), false);

		$this->assertSame('segmented', $r['required_hours_basis']);
		$this->assertCount(3, $r['soll_segments']);
		$this->assertSame('on_demand', $r['soll_segments'][1]['basis']);
		$this->assertEqualsWithDelta(0.0, $r['soll_segments'][1]['hours'], 0.01);
		$this->assertEqualsWithDelta(
			$r['required_hours'],
			array_sum(array_column($r['soll_segments'], 'hours')),
			0.01
		);
	}

	/** AC-9.7: strict policy turns the uncovered month into a 0h gap segment. */
	public function testStrictPolicyGapMonthContributesZero(): void
	{
		$this->gapPolicy = 'strict';
		$this->useRows([
			$this->row(1, 1, '2026-10-01', '2026-10-31'),
			$this->row(2, 2, '2026-12-01', null),
		]);
		$this->useModels([
			1 => $this->scheduleModel(1, '15h Mo–Mi', ['mon', 'tue', 'wed']),
			2 => $this->scheduleModel(2, '20h Mo–Do', ['mon', 'tue', 'wed', 'thu']),
		]);
		$this->holidays->method('getHolidayWeightForUser')->willReturn(0.0);

		$r = $this->service->calculateOvertime('pia', new \DateTime('2026-10-01'), new \DateTime('2026-12-04'), false);

		$this->assertSame('segmented', $r['required_hours_basis']);
		$gap = array_values(array_filter($r['soll_segments'], fn ($s) => $s['basis'] === 'unassigned_gap'));
		$this->assertCount(1, $gap);
		$this->assertSame('2026-11-01', $gap[0]['start']);
		$this->assertSame('2026-11-30', $gap[0]['end']);
		$this->assertEqualsWithDelta(0.0, $gap[0]['hours'], 0.01);
		// Oct (60) + gap (0) + Dec 1–4 (Tue/Wed/Thu = 3 days × 5h = 15) = 75.
		$this->assertEqualsWithDelta(75.0, $r['required_hours'], 0.01);
	}

	/** AC-9.7b/9.11-scope: lenient keeps the Ist-Verhalten for gap days. */
	public function testLenientPolicyGapMonthUsesFallback(): void
	{
		$this->useRows([
			$this->row(1, 2, '2026-10-01', '2026-10-31'),
		]);
		$this->useModels([
			2 => $this->scheduleModel(2, '20h Mo–Do', ['mon', 'tue', 'wed', 'thu']),
		]);
		$this->holidays->method('getHolidayWeightForUser')->willReturn(0.0);

		// November has no covering row; lenient resolves via the covering-
		// today row → model B contract, no unassigned_gap segment.
		$r = $this->service->calculateOvertime('pia', new \DateTime('2026-10-01'), new \DateTime('2026-11-24'), false);

		foreach ($r['soll_segments'] as $seg) {
			$this->assertNotSame('unassigned_gap', $seg['basis']);
		}
		$this->assertGreaterThan(60.0, $r['required_hours']);
	}

	/** US-08 AC-1: a single [start,null] row computes byte-identically. */
	public function testSingleOpenRowKeepsScalarBasis(): void
	{
		$this->useRows([$this->row(1, 2, '2026-01-01', null)]);
		$this->useModels([
			2 => $this->scheduleModel(2, '20h Mo–Do', ['mon', 'tue', 'wed', 'thu']),
		]);
		$this->holidays->method('getHolidayWeightForUser')->willReturn(0.0);

		$r = $this->service->calculateOvertime('pia', new \DateTime('2026-11-02'), new \DateTime('2026-11-24'), false);

		$this->assertSame('weekday_schedule', $r['required_hours_basis']);
		$this->assertCount(1, $r['soll_segments']);
		// Nov 2–24 Mo–Do: 14 work days × 5h minus nothing = 70.
		$this->assertEqualsWithDelta(70.0, $r['required_hours'], 0.01);
	}

	/** AC-9.11 (strict scope): days before the first row have Soll 0. */
	public function testPreContractDaysZeroSollUnderStrict(): void
	{
		$this->gapPolicy = 'strict';
		$this->useRows([$this->row(1, 2, '2026-11-01', null)]);
		$this->useModels([
			2 => $this->scheduleModel(2, '20h Mo–Do', ['mon', 'tue', 'wed', 'thu']),
		]);
		$this->holidays->method('getHolidayWeightForUser')->willReturn(0.0);

		$r = $this->service->calculateOvertime('pia', new \DateTime('2026-10-26'), new \DateTime('2026-11-06'), false);

		$gap = array_values(array_filter($r['soll_segments'], fn ($s) => $s['basis'] === 'unassigned_gap'));
		$this->assertCount(1, $gap);
		$this->assertSame('2026-10-26', $gap[0]['start']);
		$this->assertSame('2026-10-31', $gap[0]['end']);
		// Nov 1–6: Mo–Do work days = 2,3,4,5 → 4 × 5h = 20.
		$this->assertEqualsWithDelta(20.0, $r['required_hours'], 0.01);
	}
}
