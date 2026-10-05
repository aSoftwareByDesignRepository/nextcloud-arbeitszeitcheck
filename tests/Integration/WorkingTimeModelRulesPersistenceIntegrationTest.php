<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\AppFramework\Db\DoesNotExistException;
use Test\TestCase;

/**
 * Atlas regression: WorkingTimeModel::setBreakRulesArray() /
 * setOvertimeRulesArray() assigned the backing property directly, bypassing
 * Entity::setter()/markFieldUpdated(). QBMapper::insert()/update() only write
 * marked fields, so break_rules (weekday_schedule, allow_sunday_work) and
 * overtime_rules were silently NULL in at_models — every model fell back to
 * weekly_hours/5 per weekday. These tests persist real rows and re-read them.
 */
class WorkingTimeModelRulesPersistenceIntegrationTest extends TestCase
{
	private const TEST_NAME = '__azc_atlas_rules_persist__';

	private WorkingTimeModelMapper $mapper;

	/** @var int[] */
	private array $createdIds = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = \OC::$server->get(WorkingTimeModelMapper::class);
		$this->cleanupTestRows();
	}

	protected function tearDown(): void
	{
		$this->cleanupTestRows();
		parent::tearDown();
	}

	public function testRulesColumnsSurviveInsertUpdateAndClear(): void
	{
		$model = new WorkingTimeModel();
		$model->setName(self::TEST_NAME);
		$model->setType(WorkingTimeModel::TYPE_PART_TIME);
		$model->setWeeklyHours(20.0);
		$model->setDailyHours(5.0);
		$model->setWorkDaysPerWeek(4.0);
		$model->setIsDefault(false);
		$model->setCreatedAt(new \DateTime());
		$model->setUpdatedAt(new \DateTime());
		$model->setBreakRulesArray(
			WeekdaySchedule::mergeIntoBreakRules(
				['allow_sunday_work' => true],
				WeekdaySchedule::banssPreset()
			)
		);
		$model->setOvertimeRulesArray(['mode' => 'bank', 'cap' => 40]);

		$saved = $this->mapper->insert($model);
		$this->createdIds[] = $saved->getId();
		$this->assertGreaterThan(0, $saved->getId());

		// Re-read through the mapper: what the DB actually stored.
		$reloaded = $this->mapper->find($saved->getId());
		$rules = $reloaded->getBreakRulesArray();
		$this->assertNotNull($rules, 'break_rules must be persisted, not NULL');
		$this->assertArrayHasKey(WeekdaySchedule::KEY, $rules);
		$this->assertTrue($rules['allow_sunday_work'] ?? false);
		$schedule = $reloaded->getWeekdaySchedule();
		$this->assertNotNull($schedule, 'weekday_schedule must re-parse from DB');
		$this->assertSame(5.0, $schedule->workDaysPerWeek());
		$this->assertSame(['mode' => 'bank', 'cap' => 40], $reloaded->getOvertimeRulesArray());

		// Update path: a changed payload must land in the row.
		$reloaded->setOvertimeRulesArray(['mode' => 'payout']);
		$this->mapper->update($reloaded);
		$afterUpdate = $this->mapper->find($saved->getId());
		$this->assertSame(['mode' => 'payout'], $afterUpdate->getOvertimeRulesArray());

		// Clear path: setting null must write NULL, not leave the stale value.
		$afterUpdate->setBreakRulesArray(null);
		$afterUpdate->setOvertimeRulesArray(null);
		$this->mapper->update($afterUpdate);
		$afterClear = $this->mapper->find($saved->getId());
		$this->assertNull($afterClear->getBreakRulesArray());
		$this->assertNull($afterClear->getOvertimeRulesArray());
	}

	private function cleanupTestRows(): void
	{
		foreach ($this->createdIds as $id) {
			try {
				$this->mapper->delete($this->mapper->find($id));
			} catch (DoesNotExistException $e) {
				// already gone
			}
		}
		$this->createdIds = [];

		foreach ($this->mapper->searchByName(self::TEST_NAME) as $row) {
			$this->mapper->delete($row);
		}
	}
}
