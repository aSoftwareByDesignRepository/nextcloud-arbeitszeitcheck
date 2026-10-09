<?php

declare(strict_types=1);

/**
 * Persistence contract for working time model JSON rule columns.
 *
 * Regression guard: setBreakRulesArray()/setOvertimeRulesArray() used to assign
 * the backing property directly, bypassing Entity::setter()/markFieldUpdated().
 * QBMapper::insert()/update() only write fields marked as updated, so
 * break_rules (incl. weekday_schedule and allow_sunday_work) and
 * overtime_rules were silently dropped on every save — models fell back to
 * weekly_hours/5 per weekday regardless of the configured schedule.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use PHPUnit\Framework\TestCase;

class WorkingTimeModelTest extends TestCase
{
	public function testSetBreakRulesArrayMarksColumnDirtyAndRoundTrips(): void
	{
		$model = new WorkingTimeModel();
		$model->setBreakRulesArray(['break_policy' => 'flex', 'allow_sunday_work' => true]);

		self::assertArrayHasKey('breakRules', $model->getUpdatedFields());
		self::assertSame(
			['break_policy' => 'flex', 'allow_sunday_work' => true],
			$model->getBreakRulesArray()
		);
	}

	public function testSetOvertimeRulesArrayMarksColumnDirtyAndRoundTrips(): void
	{
		$model = new WorkingTimeModel();
		$model->setOvertimeRulesArray(['mode' => 'bank', 'cap' => 40]);

		self::assertArrayHasKey('overtimeRules', $model->getUpdatedFields());
		self::assertSame(['mode' => 'bank', 'cap' => 40], $model->getOvertimeRulesArray());
	}

	/**
	 * The customer-reported scenario: a weekday schedule (e.g. 20 h over
	 * Mon–Thu) must be marked for persistence and readable via
	 * getWeekdaySchedule() — otherwise Soll falls back to weekly_hours/5.
	 */
	public function testWeekdaySchedulePayloadMarksDirtyAndParses(): void
	{
		$model = new WorkingTimeModel();
		$model->setBreakRulesArray(
			WeekdaySchedule::mergeIntoBreakRules(null, WeekdaySchedule::banssPreset())
		);

		self::assertArrayHasKey('breakRules', $model->getUpdatedFields());
		$schedule = $model->getWeekdaySchedule();
		self::assertNotNull($schedule, 'weekday_schedule must survive encoding');
		self::assertSame(5.0, $schedule->workDaysPerWeek());
	}

	/**
	 * Update path: an entity hydrated from a row is clean. Clearing the rules
	 * must still mark the column dirty so UPDATE writes NULL — otherwise the
	 * stored schedule would survive a "remove" forever.
	 */
	public function testClearingRulesOnHydratedEntityMarksDirty(): void
	{
		$model = WorkingTimeModel::fromRow([
			'id' => 7,
			'name' => '4-day week',
			'type' => 'part_time',
			'weekly_hours' => 20.0,
			'daily_hours' => 5.0,
			'work_days_per_week' => 4.0,
			'break_rules' => '{"weekday_schedule":{"version":1,"days":{"mon":{"work":true,"start":"08:00","end":"13:00","breaks":[]}}}}',
			'overtime_rules' => '{"mode":"bank"}',
			'is_default' => 0,
			'created_at' => '2026-01-01 08:00:00',
			'updated_at' => '2026-01-01 08:00:00',
		]);
		self::assertSame([], $model->getUpdatedFields(), 'fromRow must reset dirty state');

		$model->setBreakRulesArray(null);
		$model->setOvertimeRulesArray(null);

		self::assertArrayHasKey('breakRules', $model->getUpdatedFields());
		self::assertArrayHasKey('overtimeRules', $model->getUpdatedFields());
		self::assertNull($model->getBreakRulesArray());
		self::assertNull($model->getOvertimeRulesArray());
	}

	public function testNullRulesOnFreshEntityStayClean(): void
	{
		$model = new WorkingTimeModel();
		$model->setBreakRulesArray(null);
		$model->setOvertimeRulesArray(null);

		// Columns are nullable; an unchanged null needs no write.
		self::assertArrayNotHasKey('breakRules', $model->getUpdatedFields());
		self::assertArrayNotHasKey('overtimeRules', $model->getUpdatedFields());
		self::assertNull($model->getBreakRulesArray());
		self::assertNull($model->getOvertimeRulesArray());
	}

	/**
	 * ATLAS sweep regression: a whitespace-only name must fail validation.
	 * empty('   ') is false in PHP, so the old check silently accepted and
	 * persisted invisible-named models via POST /api/admin/working-time-models.
	 */
	public function testWhitespaceOnlyNameFailsValidation(): void
	{
		$model = new WorkingTimeModel();
		$model->setName('   ');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setWorkDaysPerWeek(5.0);

		$errors = $model->validate();
		self::assertArrayHasKey('name', $errors);
	}
}
